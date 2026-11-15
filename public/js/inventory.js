/*
 * Отметки инвентаризации: ведомость, узкий экран помещения, карточка единицы после
 * скана QR. Везде одна разметка – [data-marks] с data-url и кнопками [data-set];
 * "не на своем месте" дополнительно спрашивает помещение (список – в #tpl-rooms).
 */
(function () {
    var meta = document.querySelector('meta[name=csrf-token]');
    if (!meta) return;
    var csrf = meta.content;
    var roomsTpl = document.getElementById('tpl-rooms');
    var BADGE = { found: 'ok', misplaced: 'warn', missing: 'bad', surplus: '', pending: 'mute' };

    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-set]') : null;
        if (!btn || btn.disabled) return;
        var box = btn.closest('[data-marks]');
        if (!box) return;
        e.preventDefault();
        if (btn.dataset.set === 'misplaced') askRoom(box);
        else post(box, { mark: btn.dataset.set });
    });

    // Излишек, которого нет в учете: помещение и описание.
    var surplus = document.querySelector('[data-surplus]');
    if (surplus) surplus.addEventListener('submit', function (e) {
        e.preventDefault();
        var note = surplus.querySelector('[name=note]');
        if (!note.value.trim()) { toast('Опишите, что нашли.', true); return; }
        var room = surplus.querySelector('[name=found_room_id]');
        send(surplus.action, { mark: 'surplus', note: note.value.trim(), found_room_id: room ? room.value : null })
            .then(function (body) { sessionToast(body.message); location.reload(); })
            .catch(function () {});
    });

    function askRoom(box) {
        if (box.querySelector('.inv-where') || !roomsTpl) return;
        var where = document.createElement('div');
        where.className = 'inv-where';
        var select = document.createElement('select');
        select.innerHTML = roomsTpl.innerHTML;
        var ok = document.createElement('button');
        ok.type = 'button';
        ok.className = 'btn primary';
        ok.textContent = 'Нашли здесь';
        ok.onclick = function () { post(box, { mark: 'misplaced', found_room_id: select.value }); };
        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn';
        cancel.textContent = 'Отмена';
        cancel.onclick = function () { where.remove(); };
        where.appendChild(select);
        where.appendChild(ok);
        where.appendChild(cancel);
        box.appendChild(where);
        select.focus();
    }

    function post(box, data) {
        var buttons = box.querySelectorAll('button');
        buttons.forEach(function (b) { b.disabled = true; });
        if (box.dataset.asset) data.asset_id = box.dataset.asset;

        send(box.dataset.url, data).then(function (body) {
            var where = box.querySelector('.inv-where');
            if (where) where.remove();
            // Карточка единицы: строки ведомости там нет, достаточно перезагрузки.
            if (box.dataset.reload) { sessionToast(body.message); location.reload(); return; }
            buttons.forEach(function (b) { b.disabled = false; });
            var row = box.closest('[data-row]') || box;
            row.dataset.mark = body.mark;
            var state = row.querySelector('[data-state]');
            if (state) {
                state.textContent = body.label;
                state.className = ('badge ' + (BADGE[body.mark] || '')).trim();
            }
            var found = row.querySelector('[data-found]');
            if (found) found.textContent = body.found_room || '';
            toast(body.message);
        }).catch(function () { buttons.forEach(function (b) { b.disabled = false; }); });
    }

    function send(url, data) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(data)
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (body) {
                if (r.ok) return body;
                var msg = body.errors ? Object.values(body.errors).flat().join(' ') : (body.message || 'Ошибка ' + r.status);
                toast(msg, true);
                throw new Error(msg);
            });
        });
    }

    function toast(text, error) {
        if (!text) return;
        var t = document.createElement('div');
        t.className = 'toast ' + (error ? 'error' : 'ok');
        t.textContent = text;
        document.body.appendChild(t);
        t.onclick = function () { t.classList.add('hide'); };
        setTimeout(function () { t.classList.add('hide'); }, error ? 8000 : 4000);
    }
    function sessionToast(text) { try { sessionStorage.setItem('inv-toast', text); } catch (e) {} }
    try { var saved = sessionStorage.getItem('inv-toast'); if (saved) { sessionStorage.removeItem('inv-toast'); toast(saved); } } catch (e) {}
})();
