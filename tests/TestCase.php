<?php

namespace Tests;

use App\Models\Building;
use App\Models\Office;
use App\Models\Room;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Филиал с корпусом, этажом и помещением 6 x 4 м – как если бы их завели после hr:sync. */
    protected function room(int $hrId = 1, array $specs = []): Room
    {
        $office = Office::firstOrCreate(['hr_id' => $hrId], ['name' => "Филиал {$hrId}"]);
        $building = Building::create(['office_id' => $office->id, 'name' => 'Корпус']);
        $floor = $building->floors()->create(['level' => 1, 'name' => '1 этаж']);

        return Room::create([
            'floor_id' => $floor->id,
            'number' => '101',
            'name' => 'Лаборатория',
            'polygon' => [[0, 0], [6, 0], [6, 4], [0, 4]],
            'specs' => $specs,
        ]);
    }
}
