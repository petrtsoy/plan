<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new \ReflectionProperty(Settings::class, 'env'))->setValue(null, []);
    }

    public function test_empty_env_values_are_not_imported(): void
    {
        config(['services.sso.enabled' => false, 'services.sso.client_id' => null, 'services.sso.system_code' => 'plan']);

        Settings::import();

        $this->assertFalse(Setting::where('key', 'sso.enabled')->exists());
        $this->assertFalse(Setting::where('key', 'sso.client_id')->exists());
        $this->assertSame('plan', Setting::where('key', 'sso.system_code')->value('value'));
    }

    public function test_values_added_to_env_later_reach_db(): void
    {
        // Первая выкладка перенесла пустое: выключено, client_id нет.
        Setting::create(['key' => 'sso.enabled', 'value' => '0']);
        Setting::create(['key' => 'sso.client_id', 'value' => null]);
        // Потом в .env вписали клиента; БД при старте команды легла поверх .env.
        config(['services.sso.enabled' => true, 'services.sso.client_id' => 'plan', 'services.sso.client_secret' => 'секрет']);
        Settings::apply();
        $this->assertFalse(config('services.sso.enabled'));

        Settings::import();
        $this->assertSame('plan', config('services.sso.client_id'));
        $this->assertSame('секрет', config('services.sso.client_secret'));
        $this->assertFalse(config('services.sso.enabled'), 'заданное в БД без --overwrite не трогается');

        Settings::import(['sso']);
        $this->assertTrue(config('services.sso.enabled'));
        $this->assertSame('1', Setting::where('key', 'sso.enabled')->value('value'));
    }
}
