@extends('layouts.app')
@section('title', 'Пользователи')
@section('content')
<h1>Пользователи системы</h1>
<p class="muted small">Вход только через auth.his.kz. Человек попадает в систему, если его ИИН заведен здесь и учетка активна.</p>

<div class="table-wrap"><table>
    <tr><th>Имя</th><th>ИИН</th><th>Роль</th><th>Последний вход</th><th></th></tr>
    @foreach($users as $u)
        <tr @if(!$u->is_active) class="muted" @endif>
            <td>{{ $u->name }} @if(!$u->is_active)<span class="badge mute">отключен</span>@endif<div class="small muted">{{ $u->email }}</div></td>
            <td>{{ $u->iin }}</td>
            <td>{{ $u->role->label() }}</td>
            <td class="small">{{ $u->last_login_at?->format('d.m.Y H:i') ?? '–' }}</td>
            <td>
                <details>
                    <summary class="small">изменить</summary>
                    <form method="post" action="{{ route('admin.users.update', $u) }}" style="margin-top:8px">
                        @csrf @method('put')
                        @include('admin.user-fields', ['u' => $u])
                        <div class="actions"><button class="btn primary">Сохранить</button></div>
                    </form>
                </details>
            </td>
        </tr>
    @endforeach
</table></div>

<p class="small muted">Смотреть могут все. Править: @foreach(\App\Enums\Role::cases() as $r)<strong>{{ $r->label() }}</strong> – {{ $r === \App\Enums\Role::Admin ? 'все, включая пользователей и настройки' : ($r->areas() ? implode(', ', array_map(fn ($a) => '"'.$a->label().'"', $r->areas())) : 'ничего, только смотрит') }}{{ $loop->last ? '.' : ';' }} @endforeach</p>

<h2>Добавить</h2>
<form method="post" action="{{ route('admin.users.store') }}" class="panel" id="add-user">
    @csrf
    <div class="fields">
        <div style="grid-column:1/-1">
            <label>Сотрудник из HR</label>
            <input list="hr-employees" id="pick-employee" placeholder="начните вводить фамилию – ФИО, ИИН и почта подставятся" autocomplete="off">
            <datalist id="hr-employees">@foreach($employees as $e)<option value="{{ $e->full_name }}{{ $e->position ? ' – '.$e->position : '' }}" data-name="{{ $e->full_name }}" data-iin="{{ $e->iin }}" data-email="{{ $e->email }}">@endforeach</datalist>
        </div>
    </div>
    @include('admin.user-fields', ['u' => new \App\Models\User(['role' => \App\Enums\Role::Engineer, 'is_active' => true])])
    <div class="actions"><button class="btn primary">Добавить</button></div>
</form>
<script>
document.getElementById('pick-employee').addEventListener('input', function () {
    const o = [...document.getElementById('hr-employees').options].find(o => o.value === this.value);
    if (!o) return;
    const f = document.getElementById('add-user');
    f.elements.name.value = o.dataset.name;
    f.elements.iin.value = o.dataset.iin;
    f.elements.email.value = o.dataset.email;
});
</script>
@endsection
