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
    // Значки единиц (контур 24 x 24, белым по цвету слоя); какой у единицы – решает App\Support\AssetIcons.
    var ICONS = {
        chair: 'M8 3v18M8 13h9v8',
        armchair: 'M7 11V5h10v6M4 10v7h16v-7M7 14h10M6 17v3M18 17v3',
        sofa: 'M5 10V6h14v4M3 10h18v6H3zM5 16v3M19 16v3',
        table: 'M3 8h18M5 8v12M19 8v12',
        drawers: 'M5 4h14v16H5zM5 12h14M10 8h4M10 16h4',
        cabinet: 'M5 3h14v18H5zM12 3v18M10 11v2M14 11v2',
        shelf: 'M5 3v18M19 3v18M5 8h14M5 13h14M5 18h14',
        bed: 'M3 6v13M3 14h18v5M10 14v-4h8a3 3 0 0 1 3 3',
        kitchen: 'M3 9h18M4 9v11h16V9M12 9v11M9 13v2M15 13v2M8 9l1-4h6l1 4',
        fridge: 'M6 3h12v18H6zM6 10h12M9 6v2M9 13v3',
        microwave: 'M3 6h18v12H3zM5 8h10v8H5zM18 9v1M18 13v1',
        trolley: 'M4 6h16M4 12h16M6 6v10M18 6v10M7 19a1.5 1.5 0 1 1-3 0a1.5 1.5 0 1 1 3 0M20 19a1.5 1.5 0 1 1-3 0a1.5 1.5 0 1 1 3 0',
        drip: 'M12 3v18M8 21h8M7 3h10M8 3v4a2 2 0 0 0 4 0',
        screen: 'M3 4v16M9 4v16M15 4v16M21 4v16M3 4h18',
        hanger: 'M12 4v17M8 21h8M8 7l4-3 4 3',
        wheelchair: 'M10 3v.5M10 7v6h6l2 5M15 17a5 5 0 1 1-6-6',
        optable: 'M3 10h18M12 10v8M8 21h8M12 18v3M6 7h4',
        hood: 'M4 3h16v18H4zM4 13h16M7 6h10v4H7z',
        safe: 'M4 4h16v15H4zM10 11.5a2 2 0 1 0 4 0a2 2 0 1 0-4 0M17 11.5h1M6 19v2M18 19v2',
        laptop: 'M5 6h14v9H5zM2 18h20',
        monitor: 'M3 4h18v12H3zM8 20h8M12 16v4',
        heart: 'M3 4h18v12H3zM8 20h8M12 16v4M6 10h3l1.5-3 2 6 1.5-3H18',
        printer: 'M7 9V3h10v6M7 17H4V9h16v8h-3M7 14h10v7H7z',
        scanner: 'M3 13h18v6H3zM5 13l13-7M7 16h4',
        network: 'M3 8h18v8H3zM7 12h1M11 12h1M15 12h3',
        camera: 'M3 7h12v8H3zM15 10l6-3v8l-6-3M7 15l-2 5',
        socket: 'M3 12a9 9 0 1 0 18 0a9 9 0 1 0-18 0M9 10v3M15 10v3',
        extinguisher: 'M9 9h6v12H9zM10 9V6h4v3M12 6V3H9M14 4l4-1',
        smoke: 'M3 8h18M5 8a7 7 0 0 0 14 0M12 12v1',
        hydrant: 'M12 3v4M8 7h8M8 7v14h8V7M5 13h3M16 13h3',
        exit: 'M4 5h16v14H4zM12 8l-4 4 4 4M8 12h9',
        bolt: 'M13 2L5 14h6l-1 8 8-12h-6l1-8z',
        gas: 'M12 8V3M9 3h6M6 14a6 6 0 1 0 12 0a6 6 0 1 0-12 0M12 11v3l2 2',
        lungs: 'M12 3v8M12 10l-3 2M12 10l3 2M9 7c-3 0-5 4-5 9 0 2 2 3 5 2V7zM15 7c3 0 5 4 5 9 0 2-2 3-5 2V7z',
        snow: 'M12 2v20M3.5 7l17 10M3.5 17l17-10',
        thermo: 'M10 4a2 2 0 0 1 4 0v10a4 4 0 1 1-4 0zM12 9v6',
        cross: 'M9 3h6v6h6v6h-6v6H9v-6H3V9h6z',
        ultrasound: 'M8 3h8v6l-2 3h-4L8 9zM12 12v2M7 16a7 7 0 0 0 10 0M4 19a11 11 0 0 0 16 0',
        xray: 'M4 4h16v16H4zM12 7v10M9 9h6M8 12h8M9 15h6',
        ecg: 'M2 12h4l2-5 3 10 3-8 2 3h6',
        sterilizer: 'M4 10h16v10H4zM8 7c0-2 2-2 2-4M14 7c0-2 2-2 2-4M8 15h8',
        microscope: 'M9 3h4v8H9zM11 11v3M5 21h14M7 18h10M16 8a6 6 0 0 1-2 10',
        centrifuge: 'M3 12a9 9 0 1 0 18 0a9 9 0 1 0-18 0M12 6v4M7 15l3-2M17 15l-3-2',
        analyzer: 'M4 4h16v16H4zM4 10h16M8 14h3M8 17h6M15 7h2',
        scale: 'M4 19h16M6 19l2-8h8l2 8M12 11V6M9 6h6',
        flask: 'M9 3h6M10 3v6l-5 10a1.5 1.5 0 0 0 1.3 2h11.4a1.5 1.5 0 0 0 1.3-2l-5-10V3M7.5 15h9',
        lamp: 'M12 3v5M5 14a7 6 0 0 1 14 0zM9 17h6',
        pallet: 'M3 9h18M3 15h18M5 9v6M12 9v6M19 9v6',
        board: 'M3 4h18v12H3zM8 20l4-4 4 4',
        mirror: 'M6 11a6 8 0 1 0 12 0a6 8 0 1 0-12 0M12 19v2',
        height: 'M8 3v18M8 3h4M8 7h3M8 11h4M8 15h3M8 19h4M16 6v1M16 9v8',
        dot: 'M9 12a3 3 0 1 0 6 0a3 3 0 1 0-6 0'
    };

    var map = L.map('map', { crs: L.CRS.Simple, minZoom: -12, maxZoom: 6, zoomSnap: 0.25, zoomDelta: 0.5, attributionControl: false, doubleClickZoom: false });
    var ll = function (x, y) { return L.latLng(-y, x); };
    var xy = function (latlng) { return [round(latlng.lng), round(-latlng.lat)]; };
    function round(v) { return Math.round(v * 1000) / 1000; }

    // --- Подложка ---
    var bounds, planImage;
    if (F.plan) {
        var p = F.plan;
        bounds = L.latLngBounds(ll(p.ox, p.oy), ll(p.ox + p.w * p.k, p.oy + p.h * p.k));
        planImage = L.imageOverlay(p.url, bounds, { interactive: false }).addTo(map);
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

    // Помещения из экспликации без контура: метка с номером, щелчок – обвести.
    var pendingMarkers = {};
    function drawPending(r) {
        var m = L.marker(ll(r.label[0], r.label[1]), {
            icon: L.divIcon({ className: 'room-pending', html: '<span title="' + esc(r.title) + '">' + esc(r.number || r.name) + '</span>', iconSize: null })
        });
        m.on('click', function (e) {
            if (tool) return;
            L.DomEvent.stop(e);
            if (!F.canPlans) return showRoom(r);
            startTool('room');
            reshape = r;
            say('Обведите ' + r.title + (r.area ? ' (' + r.area.toFixed(2).replace('.', ',') + ' м² по проекту)' : '') + '. Esc – отмена.');
        });
        m.addTo(roomsLayer);
        pendingMarkers[r.id] = m;
    }
    (F.pending || []).forEach(drawPending);

    // --- Единицы ---
    var colorMode = 'layer';
    var markers = {};
    var hiddenLayers = {};
    document.querySelectorAll('[data-layer]').forEach(function (c) { if (!c.checked) hiddenLayers[c.dataset.layer] = true; });

    function pinIcon(a) {
        var color = colorMode === 'due' ? DUE[a.due] : a.color;
        var cls = 'pin' + (a.status === 'repair' || a.status === 'idle' ? ' pin-off' : '');
        var svg = '<svg viewBox="0 0 24 24"><path d="' + (ICONS[a.icon] || ICONS.dot) + '"/></svg>';
        return L.divIcon({ className: '', html: '<span class="' + cls + '" style="background:' + color + '">' + svg + '</span>', iconSize: [22, 22], iconAnchor: [11, 11] });
    }
    function drawAsset(a) {
        if (markers[a.id]) map.removeLayer(markers[a.id]);
        var m = L.marker(ll(a.x, a.y), { icon: pinIcon(a), draggable: F.canAssets, keyboard: false });
        m.bindTooltip(esc(a.title) + (a.inv ? ' · ' + esc(a.inv) : ''), { direction: 'top', offset: [0, -11] });
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
        if (name === 'crop') say('Щелкните по двум противоположным углам рамки вокруг здания – останется только она. Esc – отмена.');
        if (name === 'room') say('Щелкайте по углам помещения. Закончить – щелчок по первой точке или двойной щелчок. Esc – отмена.');
        if (name === 'place') placeForm(opts);
        if (name === 'clean') { map.getContainer().classList.add('cleaning'); cleanForm(); }
    }
    function stopTool() {
        tool = null;
        placing = false;
        points = [];
        temp.clearLayers();
        if (resultLayer) { map.removeLayer(resultLayer); resultLayer = null; }
        cleanPreview(null);
        hint.hidden = true;
        panel.innerHTML = '';
        map.getContainer().classList.remove('drawing', 'cleaning');
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
        } else if (tool === 'crop') {
            points.push(q);
            L.circleMarker(e.latlng, { radius: 4, color: '#b91c1c' }).addTo(temp);
            if (points.length === 2) cropForm();
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

    // Чистка: типы линий, заливок и текста PDF – галочка "видно", наведение – подсветить тип на плане
    var KINDS = { L: 'Линии', F: 'Заливки', T: 'Текст' };
    function cleanForm() {
        panel.innerHTML = '<div class="side-block"><strong>Очистить чертеж</strong><p class="small muted">Разбираю чертеж…</p></div>';
        fetch(F.urls.clean, { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json().then(function (b) { if (!r.ok) throw new Error(b.errors ? Object.values(b.errors).flat().join(' ') : (b.message || 'Ошибка ' + r.status)); return b; }); })
            .then(function (res) { if (tool === 'clean') cleanList(res.types); })
            .catch(function (e) { toast(e.message, true); stopTool(); });
    }
    function cleanList(types) {
        var html = '<div class="side-block clean"><strong>Очистить чертеж</strong>' +
            '<p class="small muted">Снимите галочки с лишнего: размеры, оси, штриховка – план сразу покажет, что останется. Наведите на строку – тип подсветится красным; пустая подсветка – тип вне рамки (штамп, рамка листа). Сохраняется кнопкой "Применить".</p>' +
            '<p class="small muted" id="clean-state" hidden></p>';
        var kind = null;
        types.forEach(function (t, i) {
            if (t.key[0] !== kind) { kind = t.key[0]; html += '<div class="clean-kind">' + KINDS[kind] + '</div>'; }
            html += '<label class="clean-row" data-i="' + i + '"><input type="checkbox" data-key="' + esc(t.key) + '"' + (t.hidden ? '' : ' checked') + '>' +
                '<span class="clean-sw" style="' + (kind === 'F' ? 'background:' : 'border-color:') + t.color + '"></span>' +
                '<span title="' + t.color + '">' + esc(t.label) + '</span><span class="muted small">' + t.count + '</span></label>';
        });
        html += '<div class="actions"><button class="btn primary" id="clean-ok">Применить</button><button class="btn link" id="clean-no">Отмена</button></div></div>';
        panel.innerHTML = html;
        panel.querySelectorAll('.clean-row').forEach(function (row) {
            row.addEventListener('mouseenter', function () { cleanPreview(+row.dataset.i); });
        });
        panel.querySelector('.clean').addEventListener('mouseleave', function () { cleanPreview(null); });
        var timer;
        panel.querySelectorAll('.clean-row input').forEach(function (c) {
            c.addEventListener('change', function () { clearTimeout(timer); timer = setTimeout(cleanResult, 400); });
        });
        cleanResult();
        document.getElementById('clean-no').onclick = stopTool;
        document.getElementById('clean-ok').onclick = function () {
            var hide = [].map.call(panel.querySelectorAll('.clean-row input:not(:checked)'), function (c) { return c.dataset.key; });
            this.disabled = true;
            this.textContent = 'Перерисовываю…';
            send(F.urls.clean, 'POST', { hide: hide })
                .then(function (res) { sessionToast(res.message); location.reload(); })
                .catch(function () { var b = document.getElementById('clean-ok'); if (b) { b.disabled = false; b.textContent = 'Применить'; } });
        };
    }
    // Что останется: картинка рамки без снятых типов поверх подложки (пока не применено).
    var resultLayer = null, resultSeq = 0;
    function cleanResult() {
        var seq = ++resultSeq;
        var hide = [].map.call(panel.querySelectorAll('.clean-row input:not(:checked)'), function (c) { return c.dataset.key; });
        var state = document.getElementById('clean-state');
        if (!hide.length) {
            if (resultLayer) { map.removeLayer(resultLayer); resultLayer = null; }
            if (state) state.hidden = true;
            return cleanPreview(null);
        }
        var url = F.urls.clean + '/result.png?' + hide.map(function (k) { return 'hide[]=' + encodeURIComponent(k); }).join('&');
        if (state) { state.hidden = false; state.textContent = 'Перерисовываю…'; }
        var img = new Image();
        img.onload = function () {
            if (tool !== 'clean' || seq !== resultSeq) return;
            if (resultLayer) map.removeLayer(resultLayer);
            resultLayer = L.imageOverlay(url, bounds, { interactive: false }).addTo(map);
            if (state) state.textContent = 'Так будет после "Применить". Скрыто типов: ' + hide.length + '.';
            cleanPreview(null);
        };
        img.onerror = function () { if (seq === resultSeq && state) state.textContent = 'Не удалось перерисовать.'; };
        img.src = url;
    }
    var previewLayer = null;
    function cleanPreview(i) {
        if (previewLayer) { map.removeLayer(previewLayer); previewLayer = null; }
        var base = i === null ? 1 : 0.35;
        if (planImage) planImage.setOpacity(resultLayer ? 0 : base);
        if (resultLayer) resultLayer.setOpacity(base);
        if (i !== null && bounds) {
            previewLayer = L.imageOverlay(F.urls.clean + '/' + i + '.png', bounds, { interactive: false, className: 'clean-preview' }).addTo(map);
        }
    }

    // Обрезка: рамка по двум углам
    function cropForm() {
        tool = 'crop-form';
        hint.hidden = true;
        L.rectangle(L.latLngBounds(ll(points[0][0], points[0][1]), ll(points[1][0], points[1][1])), { color: '#b91c1c', weight: 2, dashArray: '6 4', fillOpacity: 0.05 }).addTo(temp);
        panel.innerHTML = '<div class="side-block"><strong>Обрезать чертеж</strong>' +
            '<p class="small muted">Останется только рамка; PDF перерисуется крупнее. Расставленное останется на местах, прежний вариант сохранится.</p>' +
            '<div class="actions"><button class="btn primary" id="crop-ok">Обрезать</button><button class="btn link" id="crop-no">Отмена</button></div></div>';
        document.getElementById('crop-no').onclick = stopTool;
        document.getElementById('crop-ok').onclick = function () {
            this.disabled = true;
            send(F.urls.crop, 'POST', { p1: points[0], p2: points[1] })
                .then(function (res) { sessionToast(res.message); location.reload(); })
                .catch(function () { document.getElementById('crop-ok').disabled = false; });
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
                if (pendingMarkers[r.id]) { roomsLayer.removeLayer(pendingMarkers[r.id]); delete pendingMarkers[r.id]; }
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

    // --- Импорт из проекта ---
    var impBtn = document.getElementById('imp-go');
    var preview = L.layerGroup().addTo(map);
    if (impBtn) impBtn.onclick = function () {
        var pages = { marks_page: val('imp-marks') || null, spec_page: val('imp-spec') || null };
        var out = document.getElementById('imp-result');
        impBtn.disabled = true;
        out.innerHTML = '<p class="small muted">Читаю листы…</p>';
        send(F.urls.import, 'POST', pages).then(function (res) {
            impBtn.disabled = false;
            preview.clearLayers();
            res.rooms.forEach(function (r) {
                if (r.label) L.circleMarker(ll(r.label[0], r.label[1]), { radius: 4, color: '#0f766e', weight: 2, fill: false }).bindTooltip(esc(r.number + ' ' + r.name)).addTo(preview);
            });
            res.items.forEach(function (it) {
                L.circleMarker(ll(it.x, it.y), { radius: 3, color: '#b91c1c', weight: 1, fillOpacity: 0.6 }).bindTooltip(esc(it.mark + ' ' + it.name)).addTo(preview);
            });
            var cats = document.getElementById('tpl-categories').innerHTML;
            var groups = Object.keys(res.groups).map(function (g) {
                return '<label class="check"><input type="checkbox" data-imp-on="' + esc(g) + '" ' + (res.suggest[g] ? 'checked' : '') + '> ' +
                    esc(res.groups[g].title) + ': ' + res.groups[g].count + '</label>' +
                    '<select data-imp-cat="' + esc(g) + '">' + cats + '</select>';
            }).join('');
            out.innerHTML = '<p><strong>Помещений: ' + res.rooms.length + '</strong>, на плане – кружки. Те, что уже есть (по номеру), только дополнятся.</p>' +
                (groups ? '<p class="small muted">Единицы по группам спецификации. Категория – основная для группы; внутри ее слоя подбирается по названию (стул – "Стулья и кресла").</p><div class="imp-groups">' + groups + '</div>' : '') +
                (res.notes.length ? '<ul class="small muted imp-notes">' + res.notes.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('') + '</ul>' : '') +
                '<div class="actions"><button type="button" class="btn primary" id="imp-ok">Импортировать</button><button type="button" class="btn link" id="imp-no">Отмена</button></div>';
            out.querySelectorAll('[data-imp-cat]').forEach(function (sel) {
                var g = sel.dataset.impCat;
                if (res.suggest[g]) sel.value = res.suggest[g];
            });
            document.getElementById('imp-no').onclick = function () { preview.clearLayers(); out.innerHTML = ''; };
            document.getElementById('imp-ok').onclick = function () {
                var categories = {};
                out.querySelectorAll('[data-imp-cat]').forEach(function (sel) {
                    var on = out.querySelector('[data-imp-on="' + sel.dataset.impCat + '"]');
                    if (on && on.checked) categories[sel.dataset.impCat] = parseInt(sel.value, 10);
                });
                this.disabled = true;
                send(F.urls.import, 'POST', Object.assign({ confirm: true, categories: categories }, pages))
                    .then(function () { location.reload(); })
                    .catch(function () { document.getElementById('imp-ok').disabled = false; });
            };
        }).catch(function () { impBtn.disabled = false; out.innerHTML = ''; });
    };

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
