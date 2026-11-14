<?php

namespace Tests\Feature;

use App\Enums\Area;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_goes_to_login_and_dev_login_works_locally(): void
    {
        $this->get('/')->assertRedirect('/login');

        $user = User::factory()->role(Role::Viewer)->create();
        $this->get('/login')->assertOk()->assertSee($user->name);
        $this->post('/login/dev', ['user_id' => $user->id])->assertRedirect('/');
        $this->get('/')->assertOk()->assertSee('Не расставлено на планах');
    }

    public function test_only_admin_manages_users_and_settings(): void
    {
        $this->actingAs(User::factory()->role(Role::Engineer)->create());
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/settings')->assertForbidden();
        $this->get('/audit')->assertOk();

        $this->actingAs(User::factory()->role(Role::Admin)->create());
        $this->get('/admin/users')->assertOk();
        $this->get('/admin/settings')->assertOk()->assertSee('Предупредить о сроке ТО и поверки');
    }

    public function test_disabled_user_is_logged_out(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        $this->actingAs($user)->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_roles_areas(): void
    {
        $this->assertTrue(Role::Engineer->edits(Area::Plans));
        $this->assertTrue(Role::Accountant->edits(Area::Inventory));
        $this->assertFalse(Role::Accountant->edits(Area::Assets));
        $this->assertSame([], Role::Viewer->areas());
    }
}
