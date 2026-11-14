<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Office;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Синхронизация филиалов и сотрудников из HR (контракт hr/docs/api-it.md, как в it.his.kz).
 *
 * Здесь эти списки не правят – только зеркалим. Записи, пропавшие из ответа HR,
 * не удаляем: к ним может быть привязана техника и история передач.
 * Уволенных HR отдает за последние include_dismissed_days дней – по ним
 * система подсвечивает технику, которую нужно забрать.
 */
class HrSync
{
    /** Сколько дней назад искать уволенных при обычной (ежедневной) синхронизации. */
    public const DISMISSED_DAYS = 90;

    /** Первая загрузка: уволенные за десять лет, чтобы закрепить историю. */
    public const DISMISSED_DAYS_FULL = 3650;

    public function run(bool $full = false): array
    {
        $offices = $this->syncOffices();
        $employees = $this->syncEmployees($full ? self::DISMISSED_DAYS_FULL : self::DISMISSED_DAYS);

        $stats = ['offices' => $offices, 'employees' => $employees];
        Audit::log('hr_sync', 0, 'synced', $stats);

        return $stats;
    }

    private function syncOffices(): array
    {
        $items = $this->get('/api/it/offices')['items'] ?? [];
        $stats = ['created' => 0, 'updated' => 0];
        $now = now();

        foreach ($items as $item) {
            $office = Office::firstOrNew(['hr_id' => (int) $item['id']]);
            $office->fill([
                'name' => (string) $item['name'],
                'short_name' => $item['short_name'] ?? null,
                'city' => $item['city'] ?? null,
                'address' => $item['address'] ?? null,
                'is_branch' => (bool) ($item['is_branch'] ?? false),
                'is_active' => (bool) ($item['is_active'] ?? true),
            ]);
            $this->count($office, $stats);
            $office->synced_at = $now;
            $office->save();
        }

        return $stats + ['total' => count($items)];
    }

    private function syncEmployees(int $dismissedDays): array
    {
        $items = $this->get('/api/it/employees', ['include_dismissed_days' => $dismissedDays])['items'] ?? [];
        $stats = ['created' => 0, 'updated' => 0, 'fired' => 0];
        $officeIds = Office::pluck('id', 'hr_id');
        $now = now();

        foreach ($items as $item) {
            $assignments = $item['assignments'] ?? [];
            // Основное место работы – не совмещение; из него должность и отдел.
            $main = collect($assignments)->firstWhere('is_combination', false) ?? ($assignments[0] ?? []);

            $employee = Employee::firstOrNew(['hr_id' => (int) $item['id']]);
            $wasWorking = $employee->exists && $employee->fired_at === null;

            $employee->fill([
                'full_name' => trim(implode(' ', array_filter([$item['last_name'] ?? '', $item['first_name'] ?? '', $item['middle_name'] ?? '']))),
                'position' => $main['position_name'] ?? null,
                'department' => $main['department_name'] ?? null,
                'email' => $item['email'] ?? null,
                // Старый HR ИИН не отдает – тогда не затираем известный.
                'iin' => array_key_exists('iin', $item) ? $item['iin'] : $employee->iin,
                'hr_status' => $item['status'] ?? null,
                'hired_at' => $item['hired_at'] ?? null,
                'fired_at' => $item['dismissed_at'] ?? null,
            ]);

            if ($wasWorking && $employee->fired_at !== null) {
                $stats['fired']++;
            }

            DB::transaction(function () use ($employee, $assignments, $officeIds, $now, &$stats) {
                $this->count($employee, $stats);
                $employee->synced_at = $now;
                $employee->save();

                // У уволенного HR отдает пустой список мест – оставляем последние известные филиалы.
                if ($assignments) {
                    $ids = collect($assignments)
                        ->map(fn ($a) => $officeIds[(int) ($a['office_id'] ?? 0)] ?? null)
                        ->filter()->unique()->values()->all();
                    $employee->offices()->sync($ids);
                }
            });
        }

        // Ответ полный: кого в нем нет, тот в HR не работает (уволен давно или без мест).
        // На пустом ответе не трогаем никого – это скорее сбой HR, чем увольнение всех.
        if ($items) {
            $stats['fired'] += Employee::whereNull('fired_at')
                ->whereNotIn('hr_id', collect($items)->pluck('id')->map(fn ($id) => (int) $id))
                ->update(['fired_at' => today(), 'hr_status' => 'missing', 'synced_at' => $now]);
        }

        return $stats + ['total' => count($items)];
    }

    private function count($model, array &$stats): void
    {
        if (! $model->exists) {
            $stats['created']++;
        } elseif ($model->isDirty()) {
            $stats['updated']++;
        }
    }

    private function get(string $path, array $query = []): array
    {
        $cfg = config('services.hr');
        if (! $cfg['api_key']) {
            throw new RuntimeException('Не задан ключ API HR (Настройки – HR).');
        }

        $response = Http::withHeaders(['X-Api-Key' => $cfg['api_key']])
            ->acceptJson()
            ->timeout($cfg['timeout'])
            ->get($cfg['base_url'].$path, $query);

        if (! $response->successful() || $response->json('status') !== 'success') {
            throw new RuntimeException(sprintf('HR %s: HTTP %d, %s', $path, $response->status(), $response->json('error') ?? 'нет ответа'));
        }

        return $response->json();
    }
}
