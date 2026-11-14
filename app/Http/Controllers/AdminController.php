<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Пользователи системы и журнал изменений. */
class AdminController extends Controller
{
    public function users(): View
    {
        $users = User::orderByDesc('is_active')->orderBy('name')->get();

        // Кого можно завести выбором: работающие сотрудники, которых еще нет среди пользователей.
        $employees = Employee::working()
            ->where(fn ($q) => $q->whereNull('iin')->orWhereNotIn('iin', $users->pluck('iin')->filter()))
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'position', 'iin', 'email']);

        return view('admin.users', ['users' => $users, 'employees' => $employees]);
    }

    public function storeUser(Request $request): RedirectResponse
    {
        User::create($this->validatedUser($request));

        return back()->with('ok', 'Пользователь добавлен.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $data = $this->validatedUser($request, $user);

        if ($user->is($request->user()) && ($data['role'] !== Role::Admin->value || ! $data['is_active'])) {
            return back()->with('error', 'Нельзя снять права администратора с самого себя.');
        }

        $user->update($data);

        return back()->with('ok', 'Сохранено.');
    }

    public function audit(Request $request): View
    {
        $logs = AuditLog::with('user')
            ->when($request->filled('type'), fn ($q) => $q->where('subject_type', $request->input('type')))
            ->when($request->filled('id'), fn ($q) => $q->where('subject_id', $request->integer('id')))
            ->latest('id')
            ->paginate(100)->withQueryString();

        return view('admin.audit', ['logs' => $logs]);
    }

    private function validatedUser(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'iin' => ['required', 'digits:12', Rule::unique('users')->ignore($user)],
            'email' => ['nullable', 'email', 'max:191'],
            'role' => ['required', Rule::enum(Role::class)],
        ]);
        $data['is_active'] = $request->boolean('is_active', $user === null);

        return $data;
    }
}
