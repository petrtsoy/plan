<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Employee;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Свойства помещения, ответственный и привязка к кабинету it.his.kz – из панели плана. */
class RoomSpecsTest extends TestCase
{
    use RefreshDatabase;

    public function test_specs_responsible_and_it_room_saved_from_panel(): void
    {
        $room = $this->room();
        $employee = Employee::create(['hr_id' => 5, 'full_name' => 'Иванов И.И.']);
        $room->floor->building->office->employees()->attach($employee);
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $this->putJson("/rooms/{$room->id}", [
            'name' => 'Лаборатория',
            'responsible_id' => $employee->id,
            'it_location_id' => 42,
            'notes' => 'Ключ у заведующей',
            'specs' => ['power_kw' => '15,5', 'power_380' => '1', 'water' => '0', 'clean_class' => 'B',
                'ventilation' => '', 'не_из_каталога' => '1'],
        ])->assertOk()
            ->assertJsonPath('responsible', 'Иванов И.И.')
            ->assertJsonPath('specs.power_380', true)
            ->assertJsonPath('specs.power_kw', 15.5);

        $room->refresh();
        $this->assertSame($employee->id, $room->responsible_id);
        $this->assertSame(42, $room->it_location_id);
        $this->assertSame(15.5, $room->specs['power_kw']);
        $this->assertSame('B', $room->specs['clean_class']);
        // "Нет" – это ответ, его храним; "не указано" и чужие ключи не храним вовсе.
        $this->assertFalse($room->specs['water']);
        $this->assertArrayNotHasKey('ventilation', $room->specs);
        $this->assertArrayNotHasKey('не_из_каталога', $room->specs);
    }

    public function test_it_room_is_one_to_one(): void
    {
        $room = $this->room();
        Room::create(['floor_id' => $room->floor_id, 'number' => '102', 'name' => 'Склад', 'it_location_id' => 7]);
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $this->putJson("/rooms/{$room->id}", ['it_location_id' => 7])->assertJsonValidationErrors('it_location_id');
        // Свой же номер при повторном сохранении – не помеха.
        $this->putJson("/rooms/{$room->id}", ['it_location_id' => 8])->assertOk();
        $this->putJson("/rooms/{$room->id}", ['it_location_id' => 8, 'name' => 'Лаборатория'])->assertOk();
    }

    public function test_panel_gets_specs_catalog_and_branch_employees(): void
    {
        $room = $this->room();
        $working = Employee::create(['hr_id' => 1, 'full_name' => 'Петров П.П.']);
        $fired = Employee::create(['hr_id' => 2, 'full_name' => 'Сидоров С.С.', 'fired_at' => now()->subDay()]);
        $room->floor->building->office->employees()->attach([$working->id, $fired->id]);
        $room->update(['specs' => ['power_380' => true], 'responsible_id' => $working->id]);
        $this->actingAs(User::factory()->role(Role::Engineer)->create());

        $this->get("/floors/{$room->floor_id}")->assertOk()->assertViewHas('map', function ($map) use ($working) {
            $this->assertSame(['Петров П.П.'], $map['employees']->pluck('name')->all(), 'уволенных в ответственные не предлагаем');
            $this->assertContains('power_380', array_column($map['specs'], 'key'));
            $this->assertSame('Электропитание', $map['specGroups']['power']);
            $room = $map['rooms']->first();

            return $room['responsible_id'] === $working->id && $room['specs']->power_380 === true;
        });
    }
}
