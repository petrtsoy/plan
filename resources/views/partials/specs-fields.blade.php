{{--
    Инженерные требования и свойства по каталогу App\Services\Specs: у модели – что нужно,
    у помещения – что есть. $name – имя поля ("requirements"), $values – что уже задано.
--}}
@php($defs = collect(\App\Services\Specs::definitions()))
<div class="specs">
    @foreach(\App\Services\Specs::GROUPS as $group => $title)
        @php($fields = $defs->filter(fn ($d) => $d[0] === $group))
        @continue($fields->isEmpty())
        <div class="specs-group">
            <div class="specs-title">{{ $title }}</div>
            @foreach($fields as $key => [, $label, $type, $unit])
                @php($value = $values[$key] ?? null)
                @if($type === 'bool')
                    <label class="check"><input type="checkbox" name="{{ $name }}[{{ $key }}]" value="1" @checked(old($name.'.'.$key, $value))> {{ $label }}</label>
                @elseif($type === 'number')
                    <label class="specs-num">{{ $label }}{{ $unit ? ", {$unit}" : '' }}
                        <input type="number" step="0.01" min="0" name="{{ $name }}[{{ $key }}]" value="{{ old($name.'.'.$key, $value) }}">
                    </label>
                @else
                    <label class="specs-num">{{ $label }}
                        <select name="{{ $name }}[{{ $key }}]">
                            <option value="">не важно</option>
                            @foreach($unit as $v => $l)<option value="{{ $v }}" @selected((string) old($name.'.'.$key, $value) === (string) $v)>{{ $l }}</option>@endforeach
                        </select>
                    </label>
                @endif
            @endforeach
        </div>
    @endforeach
</div>
