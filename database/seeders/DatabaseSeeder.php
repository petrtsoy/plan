<?php

namespace Database\Seeders;

use App\Enums\MaintenanceKind;
use App\Enums\Role;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Building;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use App\Services\Maintenance;
use Illuminate\Database\Seeder;

/** Демо для разработки: на production не запускать (филиалы и сотрудники там – только из HR). */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CatalogSeeder::class);

        User::updateOrCreate(['iin' => '000000000001'], ['name' => 'Администратор (демо)', 'role' => Role::Admin, 'is_active' => true]);
        User::updateOrCreate(['iin' => '000000000002'], ['name' => 'Инженер (демо)', 'role' => Role::Engineer, 'is_active' => true]);

        $offices = [
            Office::firstOrCreate(['hr_id' => 9001], ['name' => 'Карагандинский филиал', 'short_name' => 'Караганда', 'city' => 'Караганда', 'is_branch' => true]),
            Office::firstOrCreate(['hr_id' => 9002], ['name' => 'Астанинский филиал', 'short_name' => 'Астана', 'city' => 'Астана', 'is_branch' => true]),
        ];
        $mol = Employee::firstOrCreate(['hr_id' => 9101], ['full_name' => 'Иванова Анна Сергеевна', 'position' => 'Заведующая лабораторией', 'department' => 'Лаборатория']);
        $mol->offices()->syncWithoutDetaching([$offices[0]->id]);

        $analyzers = Category::where('name', 'Анализаторы')->firstOrFail();
        $model = AssetModel::firstOrCreate(['name' => 'Гематологический анализатор (демо)'], [
            'category_id' => $analyzers->id,
            'manufacturer' => 'Demo',
            'width_mm' => 600, 'depth_mm' => 550, 'height_mm' => 500, 'weight_kg' => 40,
            'clearance' => ['front' => 600, 'back' => 150, 'left' => 300, 'right' => 300],
            'requirements' => ['power_kw' => 0.5, 'grounding' => true, 'ups' => true, 'drain' => true],
            'intervals' => [MaintenanceKind::Service->value => 6, MaintenanceKind::Verification->value => 12],
        ]);

        foreach ($offices as $office) {
            $building = Building::firstOrCreate(['office_id' => $office->id, 'name' => 'Главный корпус']);
            $floor = $building->floors()->firstOrCreate(['level' => 1], ['name' => '1 этаж']);
            $room = Room::firstOrCreate(['floor_id' => $floor->id, 'number' => '101'], [
                'name' => 'Лаборатория',
                'purpose' => 'лаборатория',
                'polygon' => [[0, 0], [6, 0], [6, 4.5], [0, 4.5]],
                'specs' => ['power_kw' => 5, 'grounding' => true, 'ups' => true, 'water' => true, 'drain' => true],
            ]);

            if ($office->is($offices[0]) && ! Asset::where('inventory_number', 'DEMO-0001')->exists()) {
                $asset = Asset::create([
                    'category_id' => $analyzers->id,
                    'model_id' => $model->id,
                    'inventory_number' => 'DEMO-0001',
                    'serial_number' => 'SN-DEMO-1',
                    'office_id' => $office->id,
                    'room_id' => $room->id,
                    'floor_id' => $floor->id,
                    'x' => 1.5, 'y' => 1.0,
                    'mol_id' => $mol->id,
                    'department' => 'Лаборатория',
                ]);
                app(Maintenance::class)->planFromModel($asset);
            }
        }
    }
}
