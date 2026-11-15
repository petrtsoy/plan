/*
 * Примерка на плане этажа: Leaflet CRS.Simple, координаты – метры этажа (x вправо, y вниз),
 * в Leaflet точка [lat, lng] = [-y, x]. Позиция варианта рисуется двумя контурами: сам
 * аппарат и его след с сервисными зонами. Считает влезание сервер (App\Services\Layouts),
 * поэтому после каждой правки он возвращает все позиции заново – здесь только рисование.
 */
(function () {
    var F = window.LAYOUT;
    var csrf = document.querySelector('meta[name=csrf-token]').content;
    var panel = document.getElementById('panel');
    var hint = document.getElementById('hint');
    var itemsBox = document.getElementById('items');
    var needsBox = document.getElementById('needs');
    var OK = '#0d9488', BAD = '#dc2626', STANDING = '#94a3b8';
    var show = { rooms: true, standing: true, clearance: true };

    var map = L.map('map', { crs: L.CRS.Simple, minZoom: -12, maxZoom: 8, zoomSnap: 0.25, zoomDelta: 0.5, attributionControl: false, doubleClickZoom: false });
    var ll = function (x, y) { return L.latLng(-y, x); };
    var xy = function (latlng) { return [round(latlng.lng), round(-latlng.lat)]; };
    function round(v) { return Math.round(v * 1000) / 1000; }
    function poly(points) { return points.map(function (p) { return ll(p[0], p[1]); }); }

    // --- Подложка и помещения ---
    var bounds;
    if (F.plan) {
        var p = F.plan;
        bounds = L.latLngBounds(ll(p.ox, p.oy), ll(p.ox + p.w * p.k, p.oy + p.h * p.k));
        L.imageOverlay(p.url, bounds, { interactive: false }).addTo(map);
    }
    var all = F.rooms.reduce(function (acc, r) { return acc.concat(r.polygon); }, [])
        .concat(F.items.map(function (i) { return [i.x, i.y]; }))
        .concat(F.standing.map(function (a) { return [a.x, a.y]; }));
    if (!bounds && all.length) bounds = L.latLngBounds(poly(all)).pad(0.2);
    var home = bounds || L.latLngBounds(ll(0, 0), ll(30, 20));
    map.fitBounds(home);
    var fitted = map.getSize().x > 0 && map.getSize().y > 0;
    if (window.ResizeObserver) {
        new ResizeObserver(function () {
            map.invalidateSize();
            if (!fitted && map.getSize().x > 0) { fitted = true; map.fitBounds(home); }
        }).observe(map.getContainer());
    }
    if (F.plan && F.plan.calibrated) L.control.scale({ metric: true, imperial: false }).addTo(map);

    var roomsLayer = L.layerGroup().addTo(map);
    F.rooms.forEach(function (r) {
        var shape = L.polygon(poly(r.polygon), { color: '#0f766e', weight: 1.5, fillColor: '#14b8a6', fillOpacity: 0.06 });
        var specs = r.specs && r.specs.length ? '<br>' + esc(r.specs.join(', ')) : '';
        shape.bindTooltip('<b>' + esc(r.title) + '</b>' + (r.area ? ' · ' + r.area.toFixed(2) + ' м²' : '') + specs);
        shape.addTo(roomsLayer);
    });

    // Что уже стоит на этаже: серые точки, чтобы было видно, что обходить.
    var standingLayer = L.layerGroup().addTo(map);
    F.standing.forEach(function (a) {
        L.circleMarker(ll(a.x, a.y), { radius: 5, color: STANDING, weight: 2, fillColor: '#fff', fillOpacity: 1 })
            .bindTooltip(esc(a.title) + ' (стоит)').addTo(standingLayer);
    });

    // --- Позиции варианта ---
    var itemsLayer = L.layerGroup().addTo(map);
    var shapes = {};
    function draw(items) {
        itemsLayer.clearLayers();
        shapes = {};
        items.forEach(function (item) {
            var color = item.fit.ok ? OK : BAD;
            var group = L.layerGroup().addTo(itemsLayer);
            if (item.footprint && show.clearance) {
                L.polygon(poly(item.footprint), { color: color, weight: 1, dashArray: '4 3', fill: false, interactive: false }).addTo(group);
            }
            var body;
            if (item.shape) {
                body = L.polygon(poly(item.shape), { color: color, weight: 2, fillColor: color, fillOpacity: 0.25 });
            } else {
                // Габаритов нет – ставим точкой: место не проверить, но видно, что заложено.
                body = L.circleMarker(ll(item.x, item.y), { radius: 6, color: color, weight: 2, fillColor: color, fillOpacity: 0.3 });
            }
            body.bindTooltip(tip(item));
            body.on('click', function (e) { L.DomEvent.stop(e); select(item); });
            body.addTo(group);
            if (F.canEdit) {
                var handle = L.marker(ll(item.x, item.y), {
                    icon: L.divIcon({ className: '', html: '<span class="li-handle" style="border-color:' + color + '"></span>', iconSize: [14, 14], iconAnchor: [7, 7] }),
                    draggable: true, keyboard: false
                });
                handle.on('dragend', function () {
                    var to = xy(handle.getLatLng());
                    save(item.id, { x: to[0], y: to[1], rotation: item.rotation });
                });
                handle.on('click', function (e) { L.DomEvent.stop(e); select(item); });
                handle.addTo(group);
            }
            shapes[item.id] = group;
        });
        list(items);
    }
    function tip(item) {
        var lines = ['<b>' + esc(item.label) + '</b>'];
        if (item.model) lines.push(esc(item.model));
        if (item.width && item.depth) lines.push(item.width + ' × ' + item.depth + ' мм, поворот ' + Math.round(item.rotation) + '°');
        lines.push(item.room ? esc(item.room) : 'вне помещений');
        item.fit.problems.forEach(function (problem) { lines.push('⚠ ' + esc(problem)); });
        return lines.join('<br>');
    }

    // --- Список позиций в панели ---
    function list(items) {
        document.getElementById('sum-count').textContent = items.length
            ? items.filter(function (i) { return i.fit.ok; }).length + ' из ' + items.length + ' влезает'
            : '';
        if (!items.length) {
            itemsBox.innerHTML = '<p class="small muted">Пусто.' + (F.canEdit ? ' "Поставить" – выбрать модель или единицу и щелкнуть по плану.' : '') + '</p>';
            return;
        }
        itemsBox.innerHTML = items.map(function (item) {
            var problems = item.fit.problems.concat(item.fit.notes);
            return '<div class="li-row' + (item.fit.ok ? '' : ' bad') + '" data-item="' + item.id + '">' +
                '<div class="li-head"><span class="dot" style="background:' + (item.fit.ok ? OK : BAD) + '"></span>' +
                '<strong>' + esc(item.label) + '</strong>' +
                (item.width ? '<span class="muted small">' + item.width + '×' + item.depth + '</span>' : '') +
                '</div>' +
                '<div class="small muted">' + (item.room ? esc(item.room) : 'вне помещений') +
                (item.model ? ' · ' + esc(item.model) : '') + '</div>' +
                (problems.length ? '<ul class="small li-problems">' + problems.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('') + '</ul>' : '') +
                '</div>';
        }).join('');
        itemsBox.querySelectorAll('.li-row').forEach(function (row) {
            row.onclick = function () {
                var item = items.filter(function (i) { return String(i.id) === row.dataset.item; })[0];
                if (item) { map.panTo(ll(item.x, item.y)); select(item); }
            };
        });
    }

    function needsList(needs) {
        if (!needsBox) return;
        needsBox.innerHTML = needs.map(function (n) {
            var left = n.quantity - n.placed;
            return '<div>' + esc(n.label) + ': <strong>' + n.placed + ' из ' + n.quantity + '</strong>' +
                (left > 0 ? ' <span class="badge warn">еще ' + left + '</span>' : ' <span class="badge ok">все</span>') + '</div>';
        }).join('') || '<p class="small muted">Потребностей нет.</p>';
    }

    // --- Карточка позиции: поворот, название, убрать ---
    function select(item) {
        stopTool();
        var edit = F.canEdit;
        panel.innerHTML = '<div class="side-block">' +
            '<strong>' + esc(item.label) + '</strong>' +
            '<p class="small muted">' + (item.room ? esc(item.room) : 'вне помещений') +
            (item.width && item.depth ? ' · ' + item.width + ' × ' + item.depth + ' мм' : ' · габариты не заданы') + '</p>' +
            (item.fit.problems.length
                ? '<ul class="small li-problems">' + item.fit.problems.map(function (t) { return '<li>' + esc(t) + '</li>'; }).join('') + '</ul>'
                : '<p class="small" style="color:' + OK + '">Влезает: сервисная зона в помещении, пересечений нет, требования закрыты.</p>') +
            (edit
                ? '<label>Поворот, °</label><input type="number" id="it-rot" step="15" value="' + Math.round(item.rotation) + '">' +
                  '<div class="turns">' + [0, 90, 180, 270].map(function (a) { return '<button type="button" class="btn" data-turn="' + a + '">' + a + '°</button>'; }).join('') + '</div>' +
                  '<label>Название в варианте</label><input id="it-label" value="' + esc(item.labelOwn || '') + '" placeholder="' + esc(item.label) + '">' +
                  '<div class="actions"><button class="btn primary" id="it-ok">Сохранить</button>' +
                  '<button class="btn danger" id="it-del">Убрать</button>' +
                  (item.url ? '<a class="btn link" href="' + item.url + '">Карточка</a>' : '') + '</div>'
                : (item.url ? '<div class="actions"><a class="btn" href="' + item.url + '">Карточка</a></div>' : '')) +
            '</div>';
        if (!edit) return;
        var rot = document.getElementById('it-rot');
        panel.querySelectorAll('[data-turn]').forEach(function (b) {
            b.onclick = function () { rot.value = b.dataset.turn; document.getElementById('it-ok').click(); };
        });
        document.getElementById('it-ok').onclick = function () {
            save(item.id, { x: item.x, y: item.y, rotation: parseFloat(rot.value) || 0, label: document.getElementById('it-label').value.trim() || null });
        };
        document.getElementById('it-del').onclick = function () {
            if (!confirm('Убрать "' + item.label + '" из варианта?')) return;
            send(F.urls.item.replace('__ID__', item.id), 'DELETE', null).then(apply);
        };
    }

    // --- Поставить ---
    var tool = null;
    document.querySelectorAll('[data-tool]').forEach(function (b) {
        b.onclick = function () { tool === b.dataset.tool ? stopTool() : startTool(b.dataset.tool); };
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && tool) stopTool(); });

    function startTool(name) {
        stopTool();
        tool = name;
        document.querySelectorAll('[data-tool]').forEach(function (b) { b.classList.toggle('primary', b.dataset.tool === name); });
        map.getContainer().classList.add('drawing');
        placeForm();
    }
    function stopTool() {
        tool = null;
        hint.hidden = true;
        panel.innerHTML = '';
        map.getContainer().classList.remove('drawing');
        document.querySelectorAll('[data-tool]').forEach(function (b) { b.classList.remove('primary'); });
    }
    function say(text) { hint.textContent = text; hint.hidden = false; }

    function placeForm() {
        var needs = (F.needs || []).map(function (n) {
            return '<option value="' + n.id + '">' + esc(n.label) + ' (' + n.placed + ' из ' + n.quantity + ')</option>';
        }).join('');
        panel.innerHTML = '<div class="side-block">' +
            '<strong>Что ставим</strong>' +
            (needs ? '<label>Потребность проекта</label><select id="pl-need"><option value="">– не из потребностей –</option>' + needs + '</select>' : '') +
            '<label>Модель из справочника</label><select id="pl-model"><option value="">–</option>' + document.getElementById('tpl-models').innerHTML + '</select>' +
            '<label>Существующая единица (переставить)</label><select id="pl-asset"><option value="">–</option>' + document.getElementById('tpl-assets').innerHTML + '</select>' +
            '<label>Поворот, °</label><input type="number" id="pl-rot" step="15" value="0">' +
            '<p class="small muted">Потребность – "нужен анализатор", модель еще не выбрана: габариты берутся из нее. ' +
            'Модель – покупка: при утверждении заведется новая единица. Существующая единица – переезд или перестановка.</p>' +
            '<div class="actions"><button class="btn link" id="pl-no">Готово</button></div></div>';
        document.getElementById('pl-no').onclick = stopTool;
        say('Щелкните по месту на плане. Esc – закончить.');
    }

    map.on('click', function (e) {
        if (tool !== 'place') return;
        var q = xy(e.latlng);
        var data = { x: q[0], y: q[1], rotation: parseFloat(val('pl-rot')) || 0 };
        var need = val('pl-need'), model = val('pl-model'), asset = val('pl-asset');
        if (need) data.need_id = need;
        if (model) data.model_id = model;
        if (asset) data.asset_id = asset;
        if (!need && !model && !asset) return toast('Сначала выберите, что ставим.', true);
        send(F.urls.items, 'POST', data).then(function (res) {
            apply(res);
            // Единицу ставят один раз: из списка она уходит, чтобы не поставить дважды.
            if (asset) {
                var sel = document.getElementById('pl-asset');
                if (sel) sel.querySelectorAll('option[value="' + asset + '"]').forEach(function (o) { o.remove(); });
            }
        });
    });

    function save(id, data) {
        return send(F.urls.item.replace('__ID__', id), 'PUT', data).then(function (res) {
            apply(res);
            var again = res.items.filter(function (i) { return String(i.id) === String(id); })[0];
            if (again && panel.querySelector('#it-rot')) select(again);
        });
    }
    function apply(res) {
        if (!res) return;
        F.items = res.items;
        draw(res.items);
        if (res.needs) needsList(res.needs);
        else if (F.needs) {
            // Сколько расставлено – считаем по позициям, не перезапрашивая проект.
            F.needs.forEach(function (n) {
                n.placed = res.items.filter(function (i) { return String(i.needId) === String(n.id); }).length;
            });
            needsList(F.needs);
        }
        if (res.message) toast(res.message);
    }

    document.querySelectorAll('[data-show]').forEach(function (c) {
        c.onchange = function () {
            show[c.dataset.show] = c.checked;
            if (c.dataset.show === 'rooms') c.checked ? roomsLayer.addTo(map) : map.removeLayer(roomsLayer);
            else if (c.dataset.show === 'standing') c.checked ? standingLayer.addTo(map) : map.removeLayer(standingLayer);
            else draw(F.items);
        };
    });

    draw(F.items);
    needsList(F.needs || []);

    // --- Служебное ---
    function val(id) { var el = document.getElementById(id); return el ? String(el.value).trim() : ''; }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function send(url, method, data) {
        return fetch(url, {
            method: method,
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: data === null ? null : JSON.stringify(data)
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
})();
