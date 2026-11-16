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
        var shape = L.polygon(r.polygon.map(function (q) { return ll(q[0], q[1]); }), { color: '#0f766e', weight: 1.5, dashArray: '5 4', fillColor: '#14b8a6', fillOpacity: 0.08 });
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
        var cls = 'pin' + (a.status === 'repair' || a.status === 'idle' ? ' pin-off' : '') + (selected[a.id] ? ' pin-sel' : '');
        // Поворот – у значка, а не у кружка: кружок круглый, крутить его незачем.
        var turn = a.rotation ? ' style="transform:rotate(' + a.rotation + 'deg)"' : '';
        var svg = '<svg viewBox="0 0 24 24"' + turn + '><path d="' + (ICONS[a.icon] || ICONS.dot) + '"/></svg>';
        return L.divIcon({ className: '', html: '<span class="' + cls + '" style="background:' + color + '">' + svg + '</span>', iconSize: [22, 22], iconAnchor: [11, 11] });
    }
    function drawAsset(a) {
        if (markers[a.id]) map.removeLayer(markers[a.id]);
        var m = L.marker(ll(a.x, a.y), { icon: pinIcon(a), draggable: F.canAssets, keyboard: false });
        m.bindTooltip(esc(a.title) + (a.inv ? ' · ' + esc(a.inv) : ''), { direction: 'top', offset: [0, -11] });
        m.on('click', function (e) {
            if (tool && tool !== 'select') return;
            L.DomEvent.stop(e);
            var ev = e.originalEvent;
            if (tool === 'select' || ev.ctrlKey || ev.metaKey || ev.shiftKey) return toggleSelected(a);
            showAsset(a);
        });
        // Выделенную точку тянут – едут все выделенные, взаимное расположение сохраняется.
        var from = null;
        m.on('dragstart', function () { from = [a.x, a.y]; });
        m.on('drag', function () {
            if (!selected[a.id] || !from || selectedList().length < 2) return;
            var now = xy(m.getLatLng());
            selectedList().forEach(function (b) {
                if (b.id !== a.id && markers[b.id]) markers[b.id].setLatLng(ll(b.x + now[0] - from[0], b.y + now[1] - from[1]));
            });
        });
        m.on('dragend', function () {
            var to = xy(m.getLatLng());
            if (selected[a.id] && selectedList().length > 1) {
                return moveSelected(to[0] - a.x, to[1] - a.y, null).catch(showSelectedWhereThey);
            }
            send(F.urls.place.replace('__ID__', a.id), 'POST', { floor_id: F.floorId, x: to[0], y: to[1] }).then(function (res) {
                a.x = to[0]; a.y = to[1];
                if (res.message) toast(res.message);
            }).catch(function () { m.setLatLng(ll(a.x, a.y)); });
        });
        m.asset = a;
        markers[a.id] = m;
        if (!hiddenLayers[a.layer]) m.addTo(map);
    }

    // --- Выделение нескольких единиц ---
    var selected = {};
    function selectedList() {
        return Object.keys(selected).map(function (id) { return markers[id] && markers[id].asset; }).filter(Boolean);
    }
    function repaint(list) { list.forEach(function (a) { if (markers[a.id]) markers[a.id].setIcon(pinIcon(a)); }); }
    function showSelectedWhereThey() { selectedList().forEach(function (a) { markers[a.id].setLatLng(ll(a.x, a.y)); }); }
    function toggleSelected(a) {
        if (selected[a.id]) delete selected[a.id]; else selected[a.id] = true;
        repaint([a]);
        selectionPanel();
    }
    function clearSelection() {
        var was = selectedList();
        selected = {};
        repaint(was);
    }
    /** Перенести или повернуть выделенное разом (AssetMover::moveMany на сервере). */
    function moveSelected(dx, dy, rotation) {
        var list = selectedList();
        if (!list.length) return Promise.resolve();
        var body = { ids: list.map(function (a) { return a.id; }), dx: round(dx), dy: round(dy) };
        if (rotation !== null && rotation !== undefined) body.rotation = rotation;
        return send(F.urls.placeMany, 'POST', body).then(function (res) {
            res.assets.forEach(drawAsset);
            toast(res.message);
            selectionPanel();
        });
    }
    function selectionPanel() {
        var list = selectedList();
        if (!list.length) {
            panel.innerHTML = '';
            if (tool === 'select') say(SELECT_HINT);
            return;
        }
        var names = list.slice(0, 6).map(function (a) { return a.title; }).join(', ');
        panel.innerHTML = '<div class="side-block">' +
            '<strong>Выбрано единиц: ' + list.length + '</strong>' +
            '<p class="small muted">' + esc(names) + (list.length > 6 ? ' и еще ' + (list.length - 6) : '') + '</p>' +
            (F.canAssets
                ? '<p class="small muted">Перетащите любую выделенную точку – поедут все. Помещение у каждой определится по новому месту, смена помещения или филиала – с историей.</p>' +
                  '<label>Повернуть все, °</label><input type="number" id="sel-rot" step="15" min="0" max="359" value="0">' +
                  '<div class="actions"><button class="btn" id="sel-turn">Повернуть</button><button class="btn link" id="sel-no">Снять выделение</button></div>'
                : '<div class="actions"><button class="btn link" id="sel-no">Снять выделение</button></div>') +
            '</div>';
        document.getElementById('sel-no').onclick = function () { clearSelection(); selectionPanel(); };
        var turn = document.getElementById('sel-turn');
        if (turn) turn.onclick = function () { moveSelected(0, 0, parseFloat(val('sel-rot').replace(',', '.')) || 0); };
    }

    // Рамка выделения: инструмент "Выделить" (на время него карту не тянем).
    var SELECT_HINT = 'Обведите рамкой единицы на плане или щелкайте по ним. Ctrl и щелчок работают и без инструмента. Esc – снять.';
    var band = null, bandFrom = null;
    map.on('mousedown', function (e) {
        if (tool !== 'select' && tool !== 'erase') return;
        bandFrom = e.latlng;
        band = L.rectangle(L.latLngBounds(e.latlng, e.latlng), { color: '#0f766e', weight: 1, dashArray: '4 4', fillOpacity: 0.08 }).addTo(temp);
    });
    map.on('mousemove', function (e) { if (band) band.setBounds(L.latLngBounds(bandFrom, e.latlng)); });
    map.on('mouseup', function (e) {
        if (!band) return;
        var box = L.latLngBounds(bandFrom, e.latlng);
        var size = map.latLngToContainerPoint(box.getNorthWest()).distanceTo(map.latLngToContainerPoint(box.getSouthEast()));
        temp.clearLayers();
        band = bandFrom = null;
        if (tool === 'erase') {
            // Короткое нажатие – выбрать линию под курсором, рамка – все, что целиком внутри.
            if (size < 8) return eraseAt(e.latlng);
            var a = xy(box.getNorthWest()), b = xy(box.getSouthEast());
            erasing.rects.push([a[0], a[1], b[0], b[1]]);
            eraseDraw();
            erasePanel();
            return erasePreview();
        }
        if (size < 8) return;
        Object.keys(markers).forEach(function (id) {
            var a = markers[id].asset;
            if (map.hasLayer(markers[id]) && box.contains(ll(a.x, a.y))) selected[a.id] = true;
        });
        repaint(selectedList());
        selectionPanel();
    });
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
        if (name !== 'corners') map.getContainer().classList.add('drawing');
        if (name === 'calibrate') say('Щелкните по двум концам отрезка, длина которого известна (стена, размерная линия). Если размеров нет – обведите помещение с известной площадью из экспликации. Esc – отмена.');
        if (name === 'crop') say('Щелкните по двум противоположным углам рамки вокруг здания – останется только она. Esc – отмена.');
        if (name === 'room') say('Щелкайте по углам помещения. Закончить – щелчок по первой точке или двойной щелчок. Esc – отмена.');
        if (name === 'wand') say('Щелкните внутри помещения – контур обведется по стенам чертежа. Esc – отмена.');
        if (name === 'zone') say('Щелкайте по углам зоны. Закончить – щелчок по первой точке или двойной щелчок. Esc – отмена.');
        if (name === 'select') { map.dragging.disable(); say(SELECT_HINT); }
        if (name === 'erase') {
            map.dragging.disable();
            eraseStart();
            say('Щелкайте по лишним линиям и подписям или обводите их рамкой – сотрем с чертежа. Esc – отмена.');
        }
        if (name === 'place') placeForm(opts);
        if (name === 'clean') { map.getContainer().classList.add('cleaning'); cleanForm(); }
    }
    function stopTool() {
        tool = null;
        placing = false;
        points = [];
        if (corners) { var wasCorners = corners.room; corners = null; drawRoom(wasCorners); }
        erasing = null;
        clearTimeout(eraseTimer);
        marks.clearLayers();
        temp.clearLayers();
        band = bandFrom = null;
        map.dragging.enable();
        clearSelection();
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
        } else if (tool === 'zone') {
            if (points.length >= 3 && nearFirst(e.latlng)) return finishZone();
            points.push(q);
            drawPath('#7c3aed');
        } else if (tool === 'wand') {
            wandAt(q);
        } else if (tool === 'place' && placing) {
            placeAt(q);
        }
    });
    map.on('dblclick', function () {
        if (tool === 'room' && points.length >= 3) finishRoom();
        if (tool === 'zone' && points.length >= 3) finishZone();
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
            now = polyArea(points);
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

    // --- Ластик: убрать с чертежа лишнее ---
    // Щелчок выбирает линию, заливку или подпись под курсором (сервер ищет их в потоке страницы
    // PDF), рамка – все, что целиком внутри нее. Применение, как и чистка по типам, перерисовывает
    // страницу новой версией подложки: геометрия листа не меняется, расставленное стоит на местах.
    var erasing = null; // { items: [{i, label, color, points}], rects: [[x0, y0, x1, y1]] }
    var marks = L.layerGroup().addTo(map);
    function eraseStart() {
        erasing = { items: [], rects: [] };
        erasePanel();
    }
    /** Что под точкой: берем ближнее, остальное – если щелкнуть еще раз точнее. */
    function eraseAt(latlng) {
        var q = xy(latlng);
        send(F.urls.eraseAt, 'POST', { x: q[0], y: q[1], r: round(snapTolerance() * 1.5) }).then(function (res) {
            if (tool !== 'erase') return;
            var it = (res.items || []).filter(function (o) {
                return !erasing.items.some(function (had) { return had.i === o.i; });
            })[0];
            if (!it) return toast('Под курсором ничего нового – щелкните точно по линии или обведите рамкой.', true);
            erasing.items.push(it);
            eraseDraw();
            erasePanel();
            erasePreview();
        });
    }
    function eraseDraw() {
        marks.clearLayers();
        erasing.rects.forEach(function (r) {
            L.rectangle(L.latLngBounds(ll(r[0], r[1]), ll(r[2], r[3])),
                { color: '#b91c1c', weight: 1, dashArray: '4 4', fillOpacity: 0.12, interactive: false }).addTo(marks);
        });
        erasing.items.forEach(function (it) {
            var line = it.points.map(function (q) { return ll(q[0], q[1]); });
            (it.points.length > 1 ? L.polyline(line, { color: '#b91c1c', weight: 4, opacity: 0.6, interactive: false })
                : L.circleMarker(line[0], { radius: 6, color: '#b91c1c', interactive: false })).addTo(marks);
        });
    }
    /** Как будет после "Стереть": та же картинка результата, что и у чистки по типам. */
    var eraseTimer;
    function erasePreview() {
        clearTimeout(eraseTimer);
        eraseTimer = setTimeout(function () {
            if (tool !== 'erase') return;
            var state = document.getElementById('erase-state');
            if (!erasing.items.length && !erasing.rects.length) {
                if (resultLayer) { map.removeLayer(resultLayer); resultLayer = null; }
                return cleanPreview(null);
            }
            var url = F.urls.clean + '/result.png?erase=' + encodeURIComponent(JSON.stringify({
                rects: erasing.rects, items: erasing.items.map(function (it) { return it.i; })
            })) + ((F.plan.hide || []).map(function (k) { return '&hide[]=' + encodeURIComponent(k); }).join(''));
            if (state) state.textContent = 'Перерисовываю…';
            var img = new Image();
            img.onload = function () {
                if (tool !== 'erase') return;
                if (resultLayer) map.removeLayer(resultLayer);
                resultLayer = L.imageOverlay(url, bounds, { interactive: false }).addTo(map);
                cleanPreview(null);
                if (state) state.textContent = 'Так будет после "Стереть".';
            };
            img.onerror = function () { if (state) state.textContent = 'Не удалось перерисовать.'; };
            img.src = url;
        }, 350);
    }
    function num(v) { return v.toFixed(2).replace('.', ','); }
    /** Размеры на плане – в метрах, а пока масштаб не задан – в пикселях картинки. */
    function unit() { return F.plan && F.plan.calibrated ? ' м' : ' px'; }
    function erasePanel() {
        var list = erasing.rects.map(function (r, i) {
            return '<label class="clean-row" data-rect="' + i + '"><span></span><span class="clean-sw" style="border-color:#b91c1c"></span>' +
                '<span>Рамка ' + num(Math.abs(r[2] - r[0])) + ' x ' + num(Math.abs(r[3] - r[1])) + unit() + '</span>' +
                '<span class="muted small">убрать</span></label>';
        }).concat(erasing.items.map(function (it, i) {
            return '<label class="clean-row" data-item="' + i + '"><span></span>' +
                '<span class="clean-sw" style="border-color:' + esc(it.color) + '"></span>' +
                '<span>' + esc(it.label) + '</span><span class="muted small">убрать</span></label>';
        })).join('');
        panel.innerHTML = '<div class="side-block"><strong>Ластик</strong>' +
            '<p class="small muted">Щелчок по чертежу выбирает линию или подпись под курсором, рамка мышью – все, что целиком внутри нее. План сразу покажет, что останется. Esc – отмена.</p>' +
            '<p class="small muted" id="erase-state" hidden></p>' +
            (list ? list : '<p class="small muted">Пока ничего не выбрано.</p>') +
            '<div class="actions"><button class="btn primary" id="erase-ok"' + (list ? '' : ' disabled') + '>Стереть</button>' +
            '<button class="btn link" id="erase-no">Отмена</button></div></div>';
        var state = document.getElementById('erase-state');
        if (state) state.hidden = !(erasing.items.length || erasing.rects.length);
        panel.querySelectorAll('[data-rect]').forEach(function (row) {
            row.onclick = function () { erasing.rects.splice(+row.dataset.rect, 1); eraseDraw(); erasePanel(); erasePreview(); };
        });
        panel.querySelectorAll('[data-item]').forEach(function (row) {
            row.onclick = function () { erasing.items.splice(+row.dataset.item, 1); eraseDraw(); erasePanel(); erasePreview(); };
        });
        document.getElementById('erase-no').onclick = stopTool;
        document.getElementById('erase-ok').onclick = function () {
            this.disabled = true;
            this.textContent = 'Перерисовываю…';
            send(F.urls.erase, 'POST', {
                rects: erasing.rects, items: erasing.items.map(function (it) { return it.i; })
            }).then(function (res) { sessionToast(res.message); location.reload(); })
                .catch(function () { erasePanel(); });
        };
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
    // --- Правка углов помещения ---
    // Контур рисуется заново только целиком, а тут двигается по одной вершине:
    // угол тянется мышью, светлая точка на середине стороны добавляет угол, правый щелчок убирает.
    var corners = null; // { room, poly } – правка идет на копии, помещение меняется только при сохранении
    function editCorners(r) {
        startTool('corners');
        corners = { room: r, poly: r.polygon.map(function (q) { return q.slice(); }) };
        if (roomShapes[r.id]) roomsLayer.removeLayer(roomShapes[r.id]);
        drawCorners();
        cornersPanel();
        say('Тяните углы ' + r.title + '. Точка на середине стороны добавляет угол, правый щелчок по углу убирает. Esc – отмена.');
    }
    function cornerHandle(x, y, cls, size, drag) {
        return L.marker(ll(x, y), {
            icon: L.divIcon({ className: cls, iconSize: [size, size] }),
            draggable: drag, keyboard: false, zIndexOffset: 1000
        });
    }
    function drawCorners() {
        temp.clearLayers();
        var poly = corners.poly;
        var shape = L.polygon(poly.map(function (q) { return ll(q[0], q[1]); }),
            { color: '#0f766e', weight: 2, dashArray: '5 4', fillColor: '#14b8a6', fillOpacity: 0.12 }).addTo(temp);
        poly.forEach(function (q, i) {
            var h = cornerHandle(q[0], q[1], 'corner-pt', 12, true).addTo(temp);
            h.on('drag', function (e) {
                poly[i] = snapCorner(xy(e.target.getLatLng()), i);
                e.target.setLatLng(ll(poly[i][0], poly[i][1]));
                shape.setLatLngs(poly.map(function (t) { return ll(t[0], t[1]); }));
            });
            h.on('dragend', function () { drawCorners(); cornersPanel(); });
            h.on('contextmenu', function (e) {
                L.DomEvent.stop(e);
                if (poly.length <= 3) return toast('В контуре должно остаться хотя бы три угла.', true);
                poly.splice(i, 1);
                drawCorners();
                cornersPanel();
            });
        });
        poly.forEach(function (q, i) {
            var n = poly[(i + 1) % poly.length], mid = [round((q[0] + n[0]) / 2), round((q[1] + n[1]) / 2)];
            var m = cornerHandle(mid[0], mid[1], 'corner-add', 10, false).addTo(temp);
            m.bindTooltip('Добавить угол');
            m.on('click', function (e) {
                L.DomEvent.stop(e);
                poly.splice(i + 1, 0, mid);
                drawCorners();
                cornersPanel();
            });
        });
    }
    /** Угол липнет к линии другого угла (несколько экранных пикселей) – стены выходят ровными. */
    function snapCorner(q, i) {
        var t = snapTolerance(), x = q[0], y = q[1];
        corners.poly.forEach(function (o, j) {
            if (j === i) return;
            if (Math.abs(o[0] - x) < t) x = o[0];
            if (Math.abs(o[1] - y) < t) y = o[1];
        });
        return [round(x), round(y)];
    }
    function snapTolerance() {
        var c = map.getCenter(), p = map.latLngToContainerPoint(c);
        return Math.abs(map.containerPointToLatLng(L.point(p.x + 6, p.y)).lng - c.lng);
    }
    function cornersPanel() {
        var r = corners.room;
        panel.innerHTML = '<div class="side-block">' +
            '<strong>Углы: ' + esc(r.title) + '</strong>' +
            '<p class="small muted">Площадь по контуру: ' + num(polyArea(corners.poly)) + ' м², углов: ' + corners.poly.length + '</p>' +
            '<p class="small muted">Угол липнет к линии соседнего – стены выходят ровными. Площадь пересчитает сервер.</p>' +
            '<div class="actions"><button class="btn primary" id="cn-ok">Сохранить</button>' +
            '<button class="btn link" id="cn-no">Отмена</button></div></div>';
        document.getElementById('cn-no').onclick = stopTool;
        document.getElementById('cn-ok').onclick = function () {
            var room = corners.room, poly = corners.poly.slice();
            send(F.urls.room.replace('__ID__', room.id), 'PUT', { polygon: poly }).then(function (res) {
                room.polygon = poly;
                room.area = res.area;
                stopTool();
                drawRoom(room);
                showRoom(room);
                toast('Контур сохранен.');
            });
        };
    }
    /** Площадь многоугольника по формуле землемера. */
    function polyArea(poly) {
        return Math.abs(poly.reduce(function (s, p, i) {
            var n = poly[(i + 1) % poly.length];
            return s + p[0] * n[1] - n[0] * p[1];
        }, 0)) / 2;
    }

    function roomForm(r, save) {
        var edit = F.canPlans;
        var off = edit ? '' : ' disabled';
        var people = '<option value="">– не назначен –</option>' + (F.employees || []).map(function (e) {
            return '<option value="' + e.id + '"' + (String(r.responsible_id || '') === String(e.id) ? ' selected' : '') + '>' + esc(e.name) + '</option>';
        }).join('');
        panel.innerHTML = '<div class="side-block">' +
            '<strong>' + (r.id ? esc(r.title) : 'Новое помещение') + '</strong>' +
            (r.area ? '<p class="small muted">Площадь по контуру: ' + r.area.toFixed(2) + ' м²</p>' : '') +
            '<label>Номер</label><input id="rm-number" value="' + esc(r.number || '') + '"' + off + '>' +
            '<label>Название</label><input id="rm-name" value="' + esc(r.name || '') + '" placeholder="Процедурная"' + off + '>' +
            '<label>Назначение</label><input id="rm-purpose" value="' + esc(r.purpose || '') + '" placeholder="лаборатория, склад, коридор"' + off + '>' +
            '<label>Отделение</label><input id="rm-department" value="' + esc(r.department || '') + '"' + off + '>' +
            '<label>Ответственный за помещение</label><select id="rm-responsible"' + off + '>' + people + '</select>' +
            '<label>Кабинет в it.his.kz</label><input id="rm-it" type="number" min="1" value="' + esc(r.it_location_id || '') + '"' + off + '>' +
            '<p class="small muted">Номер кабинета (locations.id) в it.his.kz: по нему ИТ-техника едет туда и приходит оттуда.</p>' +
            '<label>Заметки</label><input id="rm-notes" value="' + esc(r.notes || '') + '"' + off + '>' +
            specsBlock(r.specs || {}, edit) +
            (edit ? '<div class="actions"><button class="btn primary" id="rm-ok">Сохранить</button>' +
                (r.id && r.polygon ? '<button class="btn" id="rm-corners">Поправить углы</button>' : '') +
                (r.id ? '<button class="btn" id="rm-shape">Обвести заново</button>' : '') +
                (r.id && F.urls.outline ? '<button class="btn" id="rm-wand">По стенам</button>' : '') +
                '<button class="btn link" id="rm-no">' + (r.id ? 'Закрыть' : 'Отмена') + '</button></div>' : '') +
            '</div>';
        if (!edit) return;
        document.getElementById('rm-name').focus();
        document.getElementById('rm-no').onclick = stopTool;
        document.getElementById('rm-ok').onclick = function () {
            save({
                number: val('rm-number'), name: val('rm-name'), purpose: val('rm-purpose'),
                department: val('rm-department'), notes: val('rm-notes'),
                responsible_id: val('rm-responsible') || null,
                it_location_id: val('rm-it') || null,
                specs: specsValues(), polygon: r.polygon
            });
        };
        var cn = document.getElementById('rm-corners');
        if (cn) cn.onclick = function () { editCorners(r); };
        var sh = document.getElementById('rm-shape');
        if (sh) sh.onclick = function () { startTool('room'); reshape = r; say('Обведите помещение заново. Esc – отмена.'); };
        var wand = document.getElementById('rm-wand');
        if (wand) wand.onclick = function () { startTool('wand'); reshape = r; say('Щелкните внутри ' + r.title + ' – контур обведется по стенам чертежа. Esc – отмена.'); };
    }
    /** Инженерные свойства помещения по каталогу App\Services\Specs: "не указано" тоже ответ. */
    function specsBlock(have, edit) {
        var off = edit ? '' : ' disabled';
        var groups = {};
        (F.specs || []).forEach(function (s) { (groups[s.group] = groups[s.group] || []).push(s); });
        var html = '';
        Object.keys(F.specGroups || {}).forEach(function (code) {
            if (!groups[code]) return;
            html += '<div class="clean-kind">' + esc(F.specGroups[code]) + '</div>';
            groups[code].forEach(function (s) {
                var v = have[s.key];
                if (s.type === 'bool') {
                    html += '<label>' + esc(s.label) + '</label><select data-spec="' + s.key + '"' + off + '>' +
                        '<option value="">не указано</option>' +
                        '<option value="1"' + (v === true ? ' selected' : '') + '>есть</option>' +
                        '<option value="0"' + (v === false ? ' selected' : '') + '>нет</option></select>';
                } else if (s.type === 'select') {
                    html += '<label>' + esc(s.label) + '</label><select data-spec="' + s.key + '"' + off + '>' +
                        '<option value="">не указано</option>' +
                        Object.keys(s.options).map(function (o) {
                            return '<option value="' + esc(o) + '"' + (String(v || '') === o ? ' selected' : '') + '>' + esc(s.options[o]) + '</option>';
                        }).join('') + '</select>';
                } else {
                    html += '<label>' + esc(s.label) + (s.unit ? ', ' + esc(s.unit) : '') + '</label>' +
                        '<input type="number" step="0.01" min="0" data-spec="' + s.key + '" value="' + (v === undefined || v === null ? '' : v) + '"' + off + '>';
                }
            });
        });
        return html ? '<details class="side-block specs"><summary><strong>Инженерные свойства</strong></summary>' + html + '</details>' : '';
    }
    function specsValues() {
        var out = {};
        panel.querySelectorAll('[data-spec]').forEach(function (el) {
            if (el.value !== '') out[el.dataset.spec] = el.value;
        });
        return out;
    }
    function showRoom(r) {
        stopTool();
        roomForm(r, function (data) {
            return send(F.urls.room.replace('__ID__', r.id), 'PUT', data).then(function (res) {
                Object.assign(r, data, { area: res.area, specs: res.specs, responsible: res.responsible, title: ((data.number ? data.number + ' ' : '') + data.name).trim() });
                drawRoom(r); showRoom(r); toast(res.message);
            });
        });
    }

    /** Контур по стенам картинки: щелчок внутри помещения (scripts/room_outline.py на сервере). */
    function wandAt(q) {
        var forRoom = reshape;
        say('Обвожу по стенам…');
        send(F.urls.outline, 'POST', { x: q[0], y: q[1] }).then(function (res) {
            if (tool !== 'wand') return;
            points = res.polygon;
            // Помещение из экспликации без контура, если метка попала внутрь, – ему и контур.
            if (!forRoom) {
                forRoom = (F.pending || []).filter(function (r) { return !r.polygon && inside(res.polygon, r.label[0], r.label[1]); })[0] || null;
            }
            reshape = forRoom;
            if (!forRoom) {
                tool = 'room';
                return finishRoom();
            }
            // У готового помещения контур не затираем молча: сначала показываем, что вышло.
            temp.clearLayers();
            L.polygon(points.map(function (t) { return ll(t[0], t[1]); }), { color: '#0f766e', weight: 2, dashArray: '4 4', fillOpacity: 0.15 }).addTo(temp);
            hint.hidden = true;
            tool = 'wand-form';
            var scaled = F.plan && F.plan.calibrated;
            panel.innerHTML = '<div class="side-block"><strong>Контур для ' + esc(forRoom.title) + '</strong>' +
                '<p class="small muted">' + (scaled
                    ? 'По стенам вышло ' + res.area.toFixed(2).replace('.', ',') + ' м²' +
                      (forRoom.area ? ', по проекту ' + forRoom.area.toFixed(2).replace('.', ',') + ' м²' : '') + '. '
                    : 'Масштаб этажа не задан, поэтому площадь тут – в пикселях подложки. ') +
                'Контур идет по внутренней стороне стен, поэтому он чуть меньше экспликации. ' +
                'Если он ушел к соседям, на чертеже открыт дверной проем – обведите по точкам.</p>' +
                '<div class="actions"><button class="btn primary" id="wd-ok">Сохранить контур</button>' +
                '<button class="btn" id="wd-hand">Обвести по точкам</button>' +
                '<button class="btn link" id="wd-no">Отмена</button></div></div>';
            document.getElementById('wd-no').onclick = stopTool;
            document.getElementById('wd-hand').onclick = function () { startTool('room'); reshape = forRoom; say('Обведите ' + forRoom.title + ' по точкам. Esc – отмена.'); };
            document.getElementById('wd-ok').onclick = function () { tool = 'room'; finishRoom(); };
        }).catch(function () { if (tool === 'wand') say('Щелкните внутри помещения, подальше от стен и подписей. Esc – отмена.'); });
    }
    /** Точка внутри контура (луч вправо) – как App\Services\Geometry::contains. */
    function inside(polygon, x, y) {
        var on = false;
        for (var i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
            var xi = polygon[i][0], yi = polygon[i][1], xj = polygon[j][0], yj = polygon[j][1];
            if ((yi > y) !== (yj > y) && x < (xj - xi) * (y - yi) / (yj - yi) + xi) on = !on;
        }
        return on;
    }

    // --- Зоны: пожарный отсек, отдел, чистая зона – отдельным слоем поверх помещений ---
    var zonesLayer = L.layerGroup(), zoneShapes = {}, zoneReshape = null;
    if (zonesVisible()) zonesLayer.addTo(map);
    function zonesVisible() {
        var c = document.querySelector('[data-zones]');
        return c ? c.checked : false;
    }
    function drawZone(z) {
        if (zoneShapes[z.id]) zonesLayer.removeLayer(zoneShapes[z.id]);
        var shape = L.polygon(z.polygon.map(function (q) { return ll(q[0], q[1]); }),
            { color: z.color, weight: 2, dashArray: '6 4', fillColor: z.color, fillOpacity: 0.1 });
        shape.bindTooltip(esc(z.name) + ' · ' + esc(z.kindLabel));
        shape.on('click', function (e) { if (!tool) { L.DomEvent.stop(e); showZone(z); } });
        shape.addTo(zonesLayer);
        zoneShapes[z.id] = shape;
    }
    (F.zones || []).forEach(drawZone);
    var zonesCheck = document.querySelector('[data-zones]');
    if (zonesCheck) zonesCheck.onchange = function () {
        if (this.checked) zonesLayer.addTo(map); else map.removeLayer(zonesLayer);
    };

    function finishZone() {
        var poly = points.slice();
        temp.clearLayers();
        L.polygon(poly.map(function (t) { return ll(t[0], t[1]); }), { color: '#7c3aed', weight: 2, fillOpacity: 0.15 }).addTo(temp);
        hint.hidden = true;
        tool = 'zone-form';
        if (zoneReshape) {
            var z = zoneReshape;
            zoneReshape = null;
            return send(F.urls.zone.replace('__ID__', z.id), 'PUT', { polygon: poly }).then(function (res) {
                stopTool(); drawZone(res.zone); showZone(res.zone); toast('Контур зоны сохранен.');
            });
        }
        zoneForm({ polygon: poly, kind: 'department', color: '' }, function (data) {
            return send(F.urls.zones, 'POST', data).then(function (res) {
                stopTool(); drawZone(res.zone); toast(res.message);
                if (zonesCheck && !zonesCheck.checked) { zonesCheck.checked = true; zonesLayer.addTo(map); }
            });
        });
    }
    function zoneForm(z, save) {
        var edit = F.canPlans;
        var off = edit ? '' : ' disabled';
        var kinds = (F.zoneKinds || []).map(function (k) {
            return '<option value="' + k.value + '"' + (z.kind === k.value ? ' selected' : '') + '>' + esc(k.label) + '</option>';
        }).join('');
        panel.innerHTML = '<div class="side-block">' +
            '<strong>' + (z.id ? esc(z.name) : 'Новая зона') + '</strong>' +
            (z.area ? '<p class="small muted">Площадь по контуру: ' + z.area.toFixed(2) + ' м²</p>' : '') +
            '<label>Тип</label><select id="zn-kind"' + off + '>' + kinds + '</select>' +
            '<label>Название</label><input id="zn-name" value="' + esc(z.name || '') + '" placeholder="Пожарный отсек 1"' + off + '>' +
            '<label>Свой цвет</label><input id="zn-color" type="color" value="' + esc(z.color || colorOfKind(z.kind)) + '"' + off + '>' +
            '<label class="check"><input type="checkbox" id="zn-own"' + (z.color ? ' checked' : '') + off + '> цвет свой, а не по типу</label>' +
            '<label>Заметки</label><input id="zn-notes" value="' + esc(z.notes || '') + '"' + off + '>' +
            (edit ? '<div class="actions"><button class="btn primary" id="zn-ok">Сохранить</button>' +
                (z.id ? '<button class="btn" id="zn-shape">Обвести заново</button>' : '') +
                '<button class="btn link" id="zn-no">' + (z.id ? 'Закрыть' : 'Отмена') + '</button></div>' : '') +
            '</div>';
        if (!edit) return;
        document.getElementById('zn-name').focus();
        document.getElementById('zn-no').onclick = stopTool;
        document.getElementById('zn-kind').onchange = function () {
            if (!document.getElementById('zn-own').checked) document.getElementById('zn-color').value = colorOfKind(this.value);
        };
        document.getElementById('zn-ok').onclick = function () {
            save({
                name: val('zn-name'), kind: val('zn-kind'), notes: val('zn-notes'),
                color: document.getElementById('zn-own').checked ? val('zn-color') : null,
                polygon: z.polygon
            });
        };
        var sh = document.getElementById('zn-shape');
        if (sh) sh.onclick = function () { startTool('zone'); zoneReshape = z; say('Обведите зону заново. Esc – отмена.'); };
    }
    function colorOfKind(kind) {
        var found = (F.zoneKinds || []).filter(function (k) { return k.value === kind; })[0];
        return found ? found.color : '#64748b';
    }
    function showZone(z) {
        stopTool();
        zoneForm(z, function (data) {
            return send(F.urls.zone.replace('__ID__', z.id), 'PUT', data).then(function (res) {
                drawZone(res.zone); showZone(res.zone); toast(res.message);
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
            (F.canAssets ? '<label>Поворот, °</label><input type="number" id="as-rot" step="15" min="0" max="359" value="' + (a.rotation || 0) + '">' : '') +
            '<div class="actions">' +
            (F.canAssets ? '<button class="btn" id="as-turn">Повернуть</button>' : '') +
            '<a class="btn" href="' + a.url + '">Карточка</a><button class="btn link" id="as-no">Закрыть</button></div>' +
            (F.canAssets ? '<p class="small muted">Переставить – перетащите точку. В другое помещение – с записью в истории. Ctrl и щелчок – выделить несколько и перенести разом.</p>' : '') +
            '</div>';
        document.getElementById('as-no').onclick = stopTool;
        var turn = document.getElementById('as-turn');
        if (turn) turn.onclick = function () {
            var deg = parseFloat(val('as-rot').replace(',', '.')) || 0;
            send(F.urls.place.replace('__ID__', a.id), 'POST', { floor_id: F.floorId, x: a.x, y: a.y, rotation: deg })
                .then(function (res) { drawAsset(res.asset); showAsset(res.asset); toast('Повернуто.'); });
        };
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
