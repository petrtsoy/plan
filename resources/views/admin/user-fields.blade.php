<div class="fields">
    <div><label>ФИО</label><input name="name" value="{{ $u->name }}" required></div>
    <div><label>ИИН</label><input name="iin" value="{{ $u->iin }}" required pattern="\d{12}" maxlength="12"></div>
    <div><label>Почта</label><input type="email" name="email" value="{{ $u->email }}"></div>
    <div>
        <label>Роль</label>
        <select name="role">
            @foreach(\App\Enums\Role::options() as $v => $l)<option value="{{ $v }}" @selected($u->role?->value === $v)>{{ $l }}</option>@endforeach
        </select>
    </div>
    <div>
        <label>&nbsp;</label>
        <label style="display:flex;gap:6px;align-items:center;color:inherit"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($u->is_active)> активен</label>
    </div>
</div>
