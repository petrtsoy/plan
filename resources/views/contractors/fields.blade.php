{{-- Поля сервисной организации: одни и те же в форме добавления и в правке строки. --}}
<div class="fields" style="margin-top:12px">
    <div><label>Название</label><input type="text" name="name" value="{{ old('name', $c?->name) }}" maxlength="191" required></div>
    <div><label>БИН</label><input type="text" name="bin" value="{{ old('bin', $c?->bin) }}" inputmode="numeric" maxlength="12"></div>
    <div><label>Контактное лицо</label><input type="text" name="contact_person" value="{{ old('contact_person', $c?->contact_person) }}" maxlength="191"></div>
    <div><label>Телефон</label><input type="text" name="phone" value="{{ old('phone', $c?->phone) }}" maxlength="64"></div>
    <div><label>Почта</label><input type="email" name="email" value="{{ old('email', $c?->email) }}" maxlength="191"></div>
    <div><label>Аккредитация или лицензия</label><input type="text" name="license" value="{{ old('license', $c?->license) }}" maxlength="191"></div>
    <div><label>№ договора</label><input type="text" name="contract_number" value="{{ old('contract_number', $c?->contract_number) }}" maxlength="64"></div>
    <div><label>Договор до</label><input type="date" name="contract_until" value="{{ old('contract_until', $c?->contract_until?->toDateString()) }}"></div>
    <div class="field-full"><label>Примечание</label><textarea name="notes">{{ old('notes', $c?->notes) }}</textarea></div>
</div>
