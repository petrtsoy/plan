/*
 * План этажа: Leaflet CRS.Simple, координаты – метры этажа (x вправо, y вниз).
 * В Leaflet точка [lat, lng] = [-y, x]. Пока масштаб не задан, метр – пиксель картинки.
 * Инструменты: масштаб (две точки или контур с известной площадью), помещение (обвести контур), поставить единицу.
 */
(function () {
    var F = window.FLOOR;
    var csrf = document.querySelector('meta[name=csrf-token]').content;
    var panel = document.getElementById('panel');
    var hint = document.getElementById('hint');
    var DUE = { overdue: '#dc2626', soon: '#f59e0b', ok: '#16a34a', none: '#94a3b8' };

    var map = L.map('map', { crs: L.CRS.Simple, minZoom: -12, maxZoom: 6, zoomSnap: 0.25, zoomDelta: 0.5, attributionControl: false, doubleClickZoom: false });
    var ll = function (x, y) { return L.latLng(-y, x); };
    var xy = function (latlng) { return [round(latlng.lng), round(-latlng.lat)]; };
    function round(v) { return Math.round(v * 1000) / 1000; }

    // --- Подложка ---
    var bounds;
    if (F.plan) {
        var p = F.plan;
        bounds = L.latLngBounds(ll(p.ox, p.oy), ll(p.ox + p.w * p.k, p.oy + p.h * p.k));
        L.imageOverlay(p.url, bounds, { interactive: false }).addTo(map);
    }
    var all = F.rooms.reduce(function (acc, r) { return acc.concat(r.polygon); }, [])
        .concat(F.assets.map(function (a) { return [a.x, a.y]; }));
    if (!bounds && all.length) {
        bounds = L.latLngBounds(all.map(function (q) { return ll(q[0], q[1]); })).pad(0.2);
    }
    var home = bounds || L.latLngBounds(ll(0, 0), ll(30, 20));
    map.fitBounds(home);
    // Контейнер без размера (вкладка в фоне, раскладка еще не готова) – вписать, когда размер появится.
    var fitted = map.getSize().x > 0 && map.getSize().y > 0;
    if (window.ResizeObserver) {
        new ResizeObserver(function () {
            map.invalidateSize();
            if (!fitted && map.getSize().x > 0 && map.getSize().y > 0) { fitted = true; map.fitBounds(home); }
        }).observe(map.getContainer());
    }
    if (F.plan && F.plan.calibrated) L.control.scale({ metric: true, imperial: false }).addTo(map);
    // В CRS.Simple линейка считает единицы как метры – это и есть метры этажа.

    // --- Помещения ---
    var roomsLayer = L.layerGroup().addTo(map);
    var roomShapes = {};
    function drawRoom(r) {
        if (roomShapes[r.id]) roomsLayer.removeLayer(roomShapes[r.id]);
        var shape = L.polygon(r.polygon.map(function (q) { return ll(q[0], q[1]); }), { color: '#0f766e', weight: 1.5, fillColor: '#14b8a6', fillOpacity: 0.08 });
        shape.bindTooltip(esc(r.title), { permanent: true, direction: 'center', className: 'room-label' });
        shape.on('click', function (e) { if (!tool) { L.DomEvent.stop(e); showRoom(r); } });
        shape.room = r;
        shape.addTo(roomsLayer);
        roomShapes[r.id] = shape;
        fitLabel(shape);
    }
    // Подпись по размеру помещения на экране: номер и название, только номер или ничего.
    function fitLabel(shape) {
        var tip = shape.getTooltip(), b = shape.getBounds();
        if (!tip || !map.hasLayer(shape)) return;
        var room = map.latLngToContainerPoint(b.getNorthEast()).x - map.latLngToContainerPoint(b.getSouthWest()).x - 4;
        var texts = [shape.room.title, shape.room.number || ''];
        for (var i = 0; i < texts.length; i++) {
            tip.setContent(esc(texts[i]));
            var el = tip.getElement();
            el.style.display = '';
            if (texts[i] && el.offsetWidth <= room) return;
        }
        tip.getElement().style.display = 'none';
    }
    map.on('zoomend', function () { Object.keys(roomShapes).forEach(function (id) { fitLabel(roomShapes[id]); }); });
    F.rooms.forEach(drawRoom);

    // --- Единицы ---
    var colorMode = 'layer';
    var markers = {};
    var hiddenLayers = {};
    document.querySelectorAll('[data-layer]').forEach(function (c) { if (!c.checked) hiddenLayers[c.dataset.layer] = true; });

    function pinIcon(a) {
        var color = colorMode === 'due' ? DUE[a.due] : a.color;
        var cls = 'pin' + (a.status === 'repair' || a.status === 'idle' ? ' pin-off' : '');
        return L.divIcon({ className: '', html: '<span class="' + cls + '" style="background:' + color + '"></span>', iconSize: [16, 16], iconAnchor: [8, 8] });
    }
    function drawAsset(a) {
        if (markers[a.id]) map.removeLayer(markers[a.id]);
        var m = L.marker(ll(a.x, a.y), { icon: pinIcon(a), draggable: F.canAssets, keyboard: false });
        m.bindTooltip(esc(a.title) + (a.inv ? ' · ' + esc(a.inv) : ''), { direction: 'top', offset: [0, -8] });
        m.on('click', function (e) { if (!tool) { L.DomEvent.stop(e); showAsset(a); } });
        m.on('dragend', function () {
            var to = xy(m.getLatLng());
            send(F.urls.place.replace('__ID__', a.id), 'POST', { floor_id: F.floorId, x: to[0], y: to[1] }).then(function (res) {
                a.x = to[0]; a.y = to[1];
                if (res.message) toast(res.message);
            }).catch(function () { m.setLatLng(ll(a.x, a.y)); });
        });
        m.asset = a;
        markers[a.id] = m;
        if (!hiddenLayers[a.layer]) m.addTo(map);
    }
    F.assets.forEach(drawAsset);
    function redrawAssets() { Object.keys(markers).forEach(function (id) { markers[id].setIcon(pinIcon(markers[id].asset)); }); }

    document.querySelectorAll('[data-layer]').forEach(function (c) {
        c.onchange = function () {
            hiddenLayers[c.dataset.layer] = !c.checked;
            Object.keys(markers).forEach(function (id) {
                var m = markers[id];
                if (String(m.asset.layer) !== c.dataset.layer) return;
                if (c.checked) m.addTo(map); else map.removeLayer(m);
            });
        };
    });
    document.querySelector('[data-rooms]').onchange = function () {
        if (this.checked) { roomsLayer.addTo(map); Object.keys(roomShapes).forEach(function (id) { fitLabel(roomShapes[id]); }); } else map.removeLayer(roomsLayer);
    };
    document.querySelectorAll('input[name=color]').forEach(function (r) {
        r.onchange = function () {
            colorMode = r.value;
            document.getElementById('legend-due').hidden = colorMode !== 'due';
            redrawAssets();
        };
    });

    // --- Инструменты ---
    var tool = null, temp = L.layerGroup().addTo(map), points = [];
    document.querySelectorAll('[data-tool]').forEach(function (b) {
        b.onclick = function () { tool === b.dataset.tool ? stopTool() : startTool(b.dataset.tool); };
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && tool) stopTool(); });

    function startTool(name, opts) {
        stopTool();
        tool = name;
        points = [];
        document.querySelectorAll('[data-tool]').forEach(function (b) { b.classList.toggle('primary', b.dataset.tool === name); });
        map.getContainer().classList.add('drawing');
        if (name === 'calibrate') say('Щелкните по двум концам отрезка, длина которого известна (стена, размерная линия). Если размеров нет – обведите помещение с известной площадью из экспликации. Esc – отмена.');
        if (name === 'room') say('Щелкайте по углам помещения. Закончить – щелчок по первой точке или двойной щелчок. Esc – отмена.');
        if (name === 'place') placeForm(opts);
    }
    function stopTool() {
        tool = null;
        placing = false;
        points = [];
        temp.clearLayers();
        hint.hidden = true;
        panel.innerHTML = '';
        map.getContainer().classList.remove('drawing');
        document.querySelectorAll('[data-tool]').forEach(function (b) { b.classList.remove('primary'); });
    }
    function say(text) { hint.textContent = text; hint.hidden = false; }

    map.on('click', function (e) {
        var q = xy(e.latlng);
        if (tool === 'calibrate') {
            // Две точки – отрезок; щелкают дальше – контур помещения, замыкают на первой точке.
            if (points.length >= 3 && nearFirst(e.latlng)) return calibrateForm(true);
            points.push(q);
            drawPath('#b91c1c');
            if (points.length === 2) calibrateForm(false);
            else if (points.length > 2) { panel.innerHTML = ''; say('Обводите помещение дальше. Закончить – щелчок по первой точке или двойной щелчок.'); }
        } else if (tool === 'room') {
            if (points.length >= 3 && nearFirst(e.latlng)) return finishRoom();
            points.push(q);
            drawPath('#0f766e');
        } else if (tool === 'place' && placing) {
            placeAt(q);
        }
    });
    map.on('dblclick', function () {
        if (tool === 'room' && points.length >= 3) finishRoom();
        if (tool === 'calibrate' && points.length >= 3) calibrateForm(true);
    });
    function nearFirst(latlng) {
        return map.latLngToContainerPoint(latlng).distanceTo(map.latLngToContainerPoint(ll(points[0][0], points[0][1]))) < 10;
    }
    function drawPath(color) {
        temp.clearLayers();
        L.polyline(points.map(function (t) { return ll(t[0], t[1]); }), { color: color, weight: 2, dashArray: tool === 'calibrate' ? '4 4' : null }).addTo(temp);
        points.forEach(function (t, i) { L.circleMarker(ll(t[0], t[1]), { radius: i ? 3 : 5, color: color }).addTo(temp); });
    }

    // Масштаб: длина отрезка или площадь обведенного помещения
    function calibrateForm(byArea) {
        hint.hidden = true;
        var now;
        if (byArea) {
            tool = 'calibrate-form';
            temp.clearLayers();
            L.polygon(points.map(function (t) { return ll(t[0], t[1]); }), { color: '#b91c1c', weight: 2, dashArray: '4 4', fillOpacity: 0.1 }).addTo(temp);
            now = Math.abs(points.reduce(function (s, p, i) { var n = points[(i + 1) % points.length]; return s + p[0] * n[1] - n[0] * p[1]; }, 0)) / 2;
        } else {
            now = Math.hypot(points[1][0] - points[0][0], points[1][1] - points[0][1]);
        }
        panel.innerHTML = '<div class="side-block"><strong>' + (byArea ? 'Площадь помещения' : 'Длина отрезка') + '</strong>' +
            (F.plan.calibrated ? '<p class="small muted">Сейчас по плану: ' + now.toFixed(2) + (byArea ? ' м²' : ' м') + '</p>' : '') +
            '<label>' + (byArea ? 'Квадратных метров' : 'Метров') + '</label><input type="number" step="0.01" min="0.01" id="cal-m">' +
            (byArea ? '' : '<p class="small muted">Размеров нет? Щелкайте дальше и обведите помещение – масштаб задается по его площади.</p>') +
            '<div class="actions"><button class="btn primary" id="cal-ok">Задать масштаб</button><button class="btn link" id="cal-no">Отмена</button></div></div>';
        var input = document.getElementById('cal-m');
        input.focus();
        input.onkeydown = function (e) { if (e.key === 'Enter') document.getElementById('cal-ok').click(); };
        document.getElementById('cal-no').onclick = stopTool;
        document.getElementById('cal-ok').onclick = function () {
            send(F.urls.calibrate, 'POST', { points: points, value: parseFloat(input.value.replace(',', '.')) })
                .then(function (res) { sessionToast(res.message); location.reload(); });
        };
    }

    // Помещение: новое или новый контур существующего
    var reshape = null;
    function finishRoom() {
        var poly = points.slice();
        temp.clearLayers();
        L.polygon(poly.map(function (t) { return ll(t[0], t[1]); }), { color: '#0f766e', weight: 2, fillOpacity: 0.15 }).addTo(temp);
        hint.hidden = true;
        tool = 'room-form';
        if (reshape) {
            var r = reshape;
            reshape = null;
            return send(F.urls.room.replace('__ID__', r.id), 'PUT', { polygon: poly }).then(function (res) {
                r.polygon = poly; r.area = res.area;
                stopTool(); drawRoom(r); showRoom(r); toast('Контур сохранен.');
            });
        }
        roomForm({ polygon: poly }, function (data) {
            return send(F.urls.rooms, 'POST', data).then(function (res) {
                var r = Object.assign(data, { id: res.id, area: res.area, title: ((data.number ? data.number + ' ' : '') + data.name).trim() });
                stopTool(); drawRoom(r); toast(res.message);
            });
        });
    }
    function roomForm(r, save) {
        var edit = F.canPlans;
        panel.innerHTML = '<div class="side-block">' +
            '<strong>' + (r.id ? esc(r.title) : 'Новое помещение') + '</strong>' +
            (r.area ? '<p class="small muted">Площадь по контуру: ' + r.area.toFixed(2) + ' м²</p>' : '') +
            '<label>Номер</label><input id="rm-number" value="' + esc(r.number || '') + '" ' + (edit ? '' : 'disabled') + '>' +
            '<label>Название</label><input id="rm-name" value="' + esc(r.name || '') + '" placeholder="Процедурная" ' + (edit ? '' : 'disabled') + '>' +
            '<label>Назначение</label><input id="rm-purpose" value="' + esc(r.purpose || '') + '" placeholder="лаборатория, склад, коридор" ' + (edit ? '' : 'disabled') + '>' +
            '<label>Отделение</label><input id="rm-department" value="' + esc(r.department || '') + '" ' + (edit ? '' : 'disabled') + '>' +
            (edit ? '<div class="actions"><button class="btn primary" id="rm-ok">Сохранить</button>' +
                (r.id ? '<button class="btn" id="rm-shape">Обвести заново</button>' : '') +
                '<button class="btn link" id="rm-no">' + (r.id ? 'Закрыть' : 'Отмена') + '</button></div>' : '') +
            '</div>';
        if (!edit) return;
        document.getElementById('rm-name').focus();
        document.getElementById('rm-no').onclick = stopTool;
        document.getElementById('rm-ok').onclick = function () {
            save({
                number: val('rm-number'), name: val('rm-name'), purpose: val('rm-purpose'),
                department: val('rm-department'), polygon: r.polygon
            });
        };
        var sh = document.getElementById('rm-shape');
        if (sh) sh.onclick = function () { startTool('room'); reshape = r; say('Обведите помещение заново. Esc – отмена.'); };
    }
    function showRoom(r) {
        stopTool();
        roomForm(r, function (data) {
            return send(F.urls.room.replace('__ID__', r.id), 'PUT', data).then(function (res) {
                Object.assign(r, data, { area: res.area, title: ((data.number ? data.number + ' ' : '') + data.name).trim() });
                drawRoom(r); showRoom(r); toast(res.message);
            });
        });
    }

    // Единица
    function showAsset(a) {
        stopTool();
        panel.innerHTML = '<div class="side-block">' +
            '<strong>' + esc(a.title) + '</strong>' +
            '<p class="small muted">' + esc(a.category) + (a.inv ? ' · инв. ' + esc(a.inv) : '') + '</p>' +
            '<p class="small">' + esc(a.statusLabel) + ' · ТО и поверка: ' +
            (a.next ? '<span class="dot" style="background:' + DUE[a.due] + '"></span> ' + a.next : 'нет регламента') + '</p>' +
            '<div class="actions"><a class="btn" href="' + a.url + '">Карточка</a><button class="btn link" id="as-no">Закрыть</button></div>' +
            (F.canAssets ? '<p class="small muted">Переставить – перетащите точку. В другое помещение – с записью в истории.</p>' : '') +
            '</div>';
        document.getElementById('as-no').onclick = stopTool;
    }

    var placing = null;
    function placeForm() {
        var options = F.unplaced.map(function (u) { return '<option value="' + u.id + '">' + esc(u.title) + (u.inv ? ' · ' + esc(u.inv) : '') + '</option>'; }).join('');
        panel.innerHTML = '<div class="side-block">' +
            '<strong>Поставить на план</strong>' +
            '<label>Нерасставленная единица филиала</label>' +
            '<select id="pl-asset"><option value="">– новая единица –</option>' + options + '</select>' +
            '<div id="pl-new">' +
            '<label>Категория</label><select id="pl-cat">' + document.getElementById('tpl-categories').innerHTML + '</select>' +
            '<label>Название</label><input id="pl-name" placeholder="по модели или категории, если пусто">' +
            '<label>Инвентарный номер</label><input id="pl-inv">' +
            '<label>Серийный номер</label><input id="pl-sn">' +
            '</div>' +
            '<p class="small muted">Затем щелкните по месту на плане. Помещение определится по контуру.</p>' +
            '<div class="actions"><button class="btn link" id="pl-no">Готово</button></div></div>';
        var sel = document.getElementById('pl-asset');
        sel.onchange = function () { document.getElementById('pl-new').hidden = !!sel.value; };
        document.getElementById('pl-no').onclick = stopTool;
        placing = true;
        say('Щелкните по месту на плане. Esc – закончить.');
    }
    function placeAt(q) {
        var id = val('pl-asset');
        if (id) {
            return send(F.urls.place.replace('__ID__', id), 'POST', { floor_id: F.floorId, x: q[0], y: q[1] }).then(function (res) {
                F.unplaced = F.unplaced.filter(function (z) { return String(z.id) !== id; });
                drawAsset(res.asset);
                toast(res.message || 'Поставлено.');
                placeForm();
            });
        }
        send(F.urls.assets, 'POST', {
            category_id: val('pl-cat'), name: val('pl-name'), inventory_number: val('pl-inv'), serial_number: val('pl-sn'), x: q[0], y: q[1]
        }).then(function (res) {
            drawAsset(res.asset);
            document.getElementById('pl-inv').value = '';
            document.getElementById('pl-sn').value = '';
            toast(res.message);
        });
    }

    // --- Служебное ---
    function val(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function send(url, method, data) {
        return fetch(url, {
            method: method,
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
    function sessionToast(text) { try { sessionStorage.setItem('floor-toast', text); } catch (e) {} }
    try { var saved = sessionStorage.getItem('floor-toast'); if (saved) { sessionStorage.removeItem('floor-toast'); toast(saved); } } catch (e) {}
})();
