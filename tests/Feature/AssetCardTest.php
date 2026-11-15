<?php

namespace Tests\Feature;

use App\Enums\AssetStatus;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\AssetTransfer;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Layer;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Карточка единицы: правка учетных полей, закрепление через AssetMover и файлы. */
class AssetCardTest extends TestCase
{
    use RefreshDatabase;

    private function asset(Room $room): Asset
    {
        $category = Category::create(['layer_id' => Layer::create(['code' => 'med', 'name' => 'Медоборудование'])->id, 'name' => 'УЗИ']);

        return Asset::create(['category_id' => $category->id, 'name' => 'УЗИ', 'inventory_number' => 'INV-1',
            'office_id' => $room->floor->building->office_id, 'room_id' => $room->id, 'floor_id' => $room->floor_id, 'x' => 1, 'y' => 1]);
    }

    public function test_card_edited_without_touching_place(): void
    {
        $room = $this->room();
        $asset = $this->asset($room);
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $this->put("/assets/{$asset->id}", [
            'category_id' => $asset->category_id,
            'status' => 'repair',
            'name' => 'УЗИ Mindray',
            'inventory_number' => 'INV-77',
            'serial_number' => 'SN-5',
            'registration_number' => 'РК-ИМН-1',
            'registration_until' => '2027-01-31',
            'price' => '1500.50',
            'manufactured_year' => '2024',
            'notes' => 'Куплено по программе',
        ])->assertRedirect()->assertSessionHas('ok');

        $asset->refresh();
        $this->assertSame(AssetStatus::Repair, $asset->status);
        $this->assertSame('INV-77', $asset->inventory_number);
        $this->assertSame('1500.50', $asset->price);
        $this->assertSame('31.01.2027', $asset->registration_until->format('d.m.Y'));
        // Место правкой карточки не двигается – для него план и AssetMover.
        $this->assertSame($room->id, $asset->room_id);
        $this->assertSame(0, $asset->moves()->count());

        // Чужой инвентарный номер не занять.
        Asset::create($asset->only(['category_id', 'office_id']) + ['name' => 'Второй', 'inventory_number' => 'INV-2']);
        $this->put("/assets/{$asset->id}", ['category_id' => $asset->category_id, 'status' => 'in_use', 'inventory_number' => 'INV-2'])
            ->assertSessionHasErrors('inventory_number');
    }

    public function test_assign_goes_through_mover_and_makes_draft_act(): void
    {
        $room = $this->room();
        $asset = $this->asset($room);
        $mol = Employee::create(['hr_id' => 3, 'full_name' => 'Петров П.П.']);
        $user = Employee::create(['hr_id' => 4, 'full_name' => 'Сидорова А.А.']);
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $this->post("/assets/{$asset->id}/assign", ['mol_id' => $mol->id, 'employee_id' => $user->id, 'comment' => 'Приказ 15'])
            ->assertRedirect()->assertSessionHas('ok');

        $asset->refresh();
        $this->assertSame($mol->id, $asset->mol_id);
        $this->assertSame($user->id, $asset->employee_id);
        $this->assertSame('Приказ 15', $asset->moves()->sole()->comment);
        $this->assertSame($mol->id, AssetTransfer::sole()->to_mol_id);
    }

    public function test_files_attached_downloaded_and_removed_softly(): void
    {
        Storage::fake('local');
        $asset = $this->asset($this->room());
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $this->post("/assets/{$asset->id}/files", ['file' => UploadedFile::fake()->create('паспорт.pdf', 20, 'application/pdf'), 'kind' => 'passport'])
            ->assertRedirect()->assertSessionHas('ok');

        $file = Attachment::sole();
        $this->assertSame('паспорт.pdf', $file->name);
        $this->assertSame('passport', $file->kind);
        Storage::disk('local')->assertExists($file->path);
        $this->get("/files/{$file->id}")->assertOk();
        $this->get("/assets/{$asset->id}")->assertOk()->assertSee('паспорт.pdf');

        $this->delete("/files/{$file->id}")->assertRedirect();
        // Мягко: запись помечена, файл на диске остался.
        $this->assertSoftDeleted($file);
        Storage::disk('local')->assertExists($file->path);
        $this->get("/assets/{$asset->id}")->assertOk()->assertDontSee('паспорт.pdf');
        $this->get("/files/{$file->id}")->assertNotFound();
    }

    public function test_viewer_only_looks(): void
    {
        Storage::fake('local');
        $asset = $this->asset($this->room());
        $file = $asset->attachments()->create(['kind' => 'photo', 'name' => 'фото.png', 'path' => 'attachments/фото.png', 'mime' => 'image/png']);
        Storage::disk('local')->put('attachments/фото.png', 'png');
        $this->actingAs(User::factory()->role(Role::Viewer)->create());

        $this->get("/assets/{$asset->id}")->assertOk()->assertSee('фото.png')->assertDontSee('Правка карточки');
        $this->get("/files/{$file->id}")->assertOk();
        $this->put("/assets/{$asset->id}", ['category_id' => $asset->category_id, 'status' => 'in_use'])->assertForbidden();
        $this->post("/assets/{$asset->id}/assign", ['mol_id' => null])->assertForbidden();
        $this->post("/assets/{$asset->id}/files", ['file' => UploadedFile::fake()->create('x.pdf', 1), 'kind' => 'act'])->assertForbidden();
        $this->delete("/files/{$file->id}")->assertForbidden();
    }
}
