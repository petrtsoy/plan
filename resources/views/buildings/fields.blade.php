<div class="fields">
    <div>
        <label>Филиал</label>
        <select name="office_id" required>
            @foreach($offices as $o)
                <option value="{{ $o->id }}" @selected(old('office_id', $b?->office_id) == $o->id)>{{ $o->display_name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label>Название</label>
        <input name="name" value="{{ old('name', $b?->name) }}" placeholder="Главный корпус" required>
    </div>
    <div>
        <label>Адрес</label>
        <input name="address" value="{{ old('address', $b?->address) }}">
    </div>
    <div>
        <label>Общая площадь, м²</label>
        <input name="total_area_m2" type="number" step="0.01" min="0" value="{{ old('total_area_m2', $b?->total_area_m2) }}">
    </div>
    <div class="field-full">
        <label>Заметки</label>
        <textarea name="notes" rows="2">{{ old('notes', $b?->notes) }}</textarea>
    </div>
</div>
