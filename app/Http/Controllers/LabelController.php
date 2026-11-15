<?php

namespace App\Http\Controllers;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Office;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Лист QR-этикеток на печать: наименование, инвентарный номер и QR со ссылкой
 * /a/{qr_code}. Сам QR рисует браузер (qrcode-generator с cdnjs) – composer-зависимости
 * для этого не нужны, как и для Leaflet на плане.
 */
class LabelController extends Controller
{
    /** Этикеток на листе: 3 в ряд по 70 x 37 мм – 24 на A4, десять листов за раз. */
    private const PER_PAGE = 240;

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'office' => ['nullable', 'integer', 'exists:offices,id'],
            'room' => ['nullable', 'integer', 'exists:rooms,id'],
            'category' => ['nullable', 'integer', 'exists:categories,id'],
            'inventory' => ['nullable', 'integer', 'exists:inventories,id'],
            'q' => ['nullable', 'string', 'max:191'],
            'numbered' => ['nullable', 'boolean'],
        ]);

        $inventory = isset($filters['inventory']) ? Inventory::with('office')->find($filters['inventory']) : null;
        $officeId = $inventory?->office_id ?? ($filters['office'] ?? null);

        $assets = Asset::with(['category', 'room', 'office'])
            ->where('status', '!=', AssetStatus::WrittenOff)
            ->when($inventory, fn ($q) => $q->whereIn('id', $inventory->items()->whereNotNull('asset_id')->pluck('asset_id')))
            ->when($officeId, fn ($q, $office) => $q->where('office_id', $office))
            ->when($filters['room'] ?? null, fn ($q, $room) => $q->where('room_id', $room))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category_id', $category))
            ->when($filters['q'] ?? null, fn ($q, $text) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$text}%")->orWhere('inventory_number', 'like', "%{$text}%")))
            ->when($filters['numbered'] ?? null, fn ($q) => $q->whereNotNull('inventory_number'))
            ->orderBy('room_id')->orderBy('name')->orderBy('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return view('labels.index', [
            'assets' => $assets,
            'inventory' => $inventory,
            'offices' => Office::where('is_active', true)->orderByDesc('is_branch')->orderBy('name')->get(),
            'rooms' => $officeId
                ? Room::whereHas('floor.building', fn ($q) => $q->real()->where('office_id', $officeId))->with('floor')->orderBy('number')->get()
                : collect(),
            'categories' => Category::orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }
}
