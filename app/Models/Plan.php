<?php

namespace App\Models;

use App\Enums\PlanSource;
use App\Models\Concerns\Auditable;
use App\Services\Geometry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Подложка этажа: исходник (PDF, скан, DXF, DWG) и картинка для показа.
 * Пиксели картинки переводятся в метры этажа: поворот вокруг начала картинки,
 * масштаб meters_per_px, затем сдвиг offset.
 */
class Plan extends Model
{
    use Auditable;

    public string $auditType = 'plan';

    protected $guarded = [];

    // Как default в БД: иначе у только что созданной записи их нет до refresh().
    protected $attributes = ['is_current' => true, 'page' => 1, 'offset_x' => 0, 'offset_y' => 0, 'rotation' => 0];

    protected function casts(): array
    {
        return [
            'source' => PlanSource::class,
            'meters_per_px' => 'float',
            'offset_x' => 'float',
            'offset_y' => 'float',
            'rotation' => 'float',
            'calibration' => 'array',
            'crop' => 'array',
            'clean' => 'array',
            'is_current' => 'boolean',
        ];
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    /** Чем версия отличается от загруженной: для списка прежних подложек. */
    public function changeLabel(): string
    {
        $what = [];
        if (isset($this->calibration['restored_from'])) {
            $what[] = 'возврат прежней';
        }
        if (isset($this->calibration['cropped_from'])) {
            $what[] = 'обрезана';
        }
        if ($this->clean['hide'] ?? null) {
            $what[] = 'скрыто типов: '.count($this->clean['hide']);
        }
        if ($this->clean['erase'] ?? null) {
            $what[] = 'стерто ластиком: '.(count($this->clean['erase']['items'] ?? []) + count($this->clean['erase']['rects'] ?? []));
        }

        return $what ? implode(', ', $what) : 'загружена';
    }

    public function isCalibrated(): bool
    {
        return $this->meters_per_px !== null && $this->meters_per_px > 0;
    }

    /** @return array{0: float, 1: float} метры этажа */
    public function toMeters(float $px, float $py): array
    {
        $a = deg2rad($this->rotation);
        $k = $this->meters_per_px ?? 1.0;

        return [
            $this->offset_x + $k * ($px * cos($a) - $py * sin($a)),
            $this->offset_y + $k * ($px * sin($a) + $py * cos($a)),
        ];
    }

    /** @return array{0: float, 1: float} пиксели картинки */
    public function toPixels(float $x, float $y): array
    {
        $a = deg2rad($this->rotation);
        $k = $this->meters_per_px ?? 1.0;
        $dx = ($x - $this->offset_x) / $k;
        $dy = ($y - $this->offset_y) / $k;

        return [$dx * cos($a) + $dy * sin($a), -$dx * sin($a) + $dy * cos($a)];
    }

    /**
     * Масштаб по точкам картинки: две точки – отрезок длиной $value метров,
     * три и больше – контур площадью $value м² (когда на чертеже нет размеров, только экспликация).
     */
    public function calibrate(array $points, float $value): void
    {
        if (count($points) === 2) {
            [$p1, $p2] = $points;
            $this->meters_per_px = $value / hypot($p2[0] - $p1[0], $p2[1] - $p1[1]);
            $this->calibration = ['p1' => $p1, 'p2' => $p2, 'meters' => $value];
        } else {
            $this->meters_per_px = sqrt($value / Geometry::area($points));
            $this->calibration = ['polygon' => $points, 'area' => $value];
        }
    }
}
