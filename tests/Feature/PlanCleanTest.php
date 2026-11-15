<?php

namespace Tests\Feature;

use App\Enums\PlanSource;
use App\Enums\Role;
use App\Models\Plan;
use App\Models\User;
use App\Services\FloorPlans;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Чистка PDF-подложки по типам: нужны python с PyMuPDF и pdftoppm, иначе тест пропускается. */
class PlanCleanTest extends TestCase
{
    use RefreshDatabase;

    /** PDF из одной страницы: стены (черные 1.0), размер (серая 0.5) и цифра размера (серый текст 10). */
    public static function pdf(): string
    {
        $content = "1 w 0 0 0 RG 100 100 m 500 100 l 500 300 l S\n"
            ."q 0.5 w 0.5 0.5 0.5 RG 100 80 m 500 80 l S Q\n"
            ."0.5 0.5 0.5 rg BT /F1 10 Tf 280 84 Td (4000) Tj ET\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 400] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($content)." >>\nstream\n{$content}endstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($out);
            $out .= ($i + 1)." 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }

        return $out.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function ready(): void
    {
        $py = new Process([config('services.python.bin'), '-c', 'import fitz']);
        $py->run();
        $pp = new Process([FloorPlans::poppler('pdftoppm'), '-v']);
        $pp->run();
        if (! $py->isSuccessful() || ! $pp->isSuccessful()) {
            $this->markTestSkipped('нет python с PyMuPDF или pdftoppm');
        }
    }

    public function test_types_listed_and_hidden_ones_removed(): void
    {
        $this->ready();
        Storage::fake('local');
        $floor = $this->room()->floor;
        Storage::disk('local')->put('plans/x.pdf', self::pdf());
        $plan = Plan::create(['floor_id' => $floor->id, 'source' => PlanSource::Pdf, 'source_path' => 'plans/x.pdf', 'source_name' => 'x.pdf',
            'image_path' => 'plans/x.png', 'width_px' => 6000, 'height_px' => 4000, 'page' => 1]);
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $keys = collect($this->getJson("/plans/{$plan->id}/clean")->assertOk()->json('types'))->pluck('key')->all();
        $this->assertSame(['L:1.00:0.00,0.00,0.00', 'L:0.50:0.50,0.50,0.50', 'T:10.0:0.50,0.50,0.50'], $keys);
        $this->get("/plans/{$plan->id}/clean/1.png")->assertOk();
        // Как будет: без серых – картинка рамки, непрозрачная.
        $res = $this->get("/plans/{$plan->id}/clean/result.png?hide[]=".urlencode('L:0.50:0.50,0.50,0.50'))->assertOk();
        $this->assertSame(2400, getimagesize($res->getFile()->getPathname())[0], 'длинная сторона рамки – 2400 px');

        $this->postJson("/plans/{$plan->id}/clean", ['hide' => ['L:0.50:0.50,0.50,0.50', 'T:10.0:0.50,0.50,0.50', 'чужой']])->assertOk();

        $new = $floor->fresh()->plan;
        $this->assertNotEquals($plan->id, $new->id);
        $this->assertSame(['L:0.50:0.50,0.50,0.50', 'T:10.0:0.50,0.50,0.50'], $new->clean['hide']);
        $this->assertSame([6000, 4000], [$new->width_px, $new->height_px]);
        $this->assertSame([false, true], [$plan->fresh()->is_current, $new->is_current]);

        // В очищенной странице серых линий нет; текст остается, но невидимый (режим 3).
        $check = new Process([config('services.python.bin'), base_path('scripts/pdf_clean.py'), 'analyze',
            Storage::disk('local')->path($new->clean['pdf']), '1', Storage::disk('local')->path('plans/check.pdf')]);
        $check->mustRun();
        $this->assertSame(['L:1.00:0.00,0.00,0.00', 'T:10.0:0.50,0.50,0.50'], array_column(json_decode($check->getOutput(), true)['types'], 'key'));

        // В панели новой подложки скрытые отмечены.
        $hidden = collect($this->getJson("/plans/{$new->id}/clean")->json('types'))->where('hidden', true)->pluck('key')->values()->all();
        $this->assertSame(['L:0.50:0.50,0.50,0.50', 'T:10.0:0.50,0.50,0.50'], $hidden);
    }
}
