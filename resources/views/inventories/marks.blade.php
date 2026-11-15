{{-- Кнопки отметки: обрабатывает public/js/inventory.js. Ждет $url, при отметке по
     единице (скан QR) – $markAsset, на страницах без строки ведомости – $markReload. --}}
<div class="inv-marks" data-marks data-url="{{ $url }}"
     @isset($markAsset) data-asset="{{ $markAsset }}" @endisset
     @isset($markReload) data-reload="1" @endisset>
    <button type="button" class="btn" data-set="found">На месте</button>
    <button type="button" class="btn" data-set="misplaced" title="Нашли в другом помещении">Не здесь</button>
    <button type="button" class="btn" data-set="missing">Не найдено</button>
</div>
