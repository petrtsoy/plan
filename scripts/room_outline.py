"""
Подсказка контура помещения: щелчок внутри – контур по стенам картинки подложки.

Помещение на чертеже со всех сторон обведено линиями, поэтому заливка от точки щелчка
останавливается на стенах. Залитое обводится по краю, обводка упрощается и выравнивается
по вертикали и горизонтали (стены на чертежах почти всегда прямые). Контур идет по
внутренней стороне стен – площадь по нему чуть меньше, чем в экспликации.

  room_outline.py outline PNG X Y [MAX_PX] [THRESHOLD]
      X, Y – точка в пикселях картинки; MAX_PX – до какого размера уменьшать перед
      заливкой (по умолчанию 2000: 25 Мп чертеж в чистом питоне заливался бы минуту);
      THRESHOLD – темнее какого уровня (0...255) пиксель считается линией.

  Ответ в stdout: {"polygon": [[x, y], ...], "area_px": N} в пикселях исходной картинки
  или {"error": "wall" | "leak" | "tiny"} – щелкнули по линии, заливка ушла за стены,
  область слишком мала.

Нужен PyMuPDF (pip pymupdf или python3-fitz) – тот же, что для чистки подложки.
"""
import json
import math
import sys

import fitz

# По часовой стрелке от востока: обход края залитой области (трассировка Мура).
NEIGH = [(1, 0), (1, 1), (0, 1), (-1, 1), (-1, 0), (-1, -1), (0, -1), (1, -1)]


def gray(path, max_px):
    """Картинка в серый, уменьшенная вдвое столько раз, сколько нужно: (маска линий, w, h, множитель)."""
    pix = fitz.Pixmap(path)
    if pix.alpha:
        pix = fitz.Pixmap(pix, 0)
    if pix.n > 1:
        pix = fitz.Pixmap(fitz.csGRAY, pix)
    k = 0
    while max(pix.width, pix.height) / 2 ** k > max_px and k < 4:
        k += 1
    if k:
        pix.shrink(k)
    return pix, pix.width, pix.height, 2 ** k


def wall_mask(pix, w, h, threshold):
    """1 – линия (темный пиксель), 0 – пусто. translate – чтобы не идти по пикселям в питоне."""
    table = bytes(1 if v < threshold else 0 for v in range(256))
    data = pix.samples
    if pix.stride != w:
        data = b''.join(data[y * pix.stride:y * pix.stride + w] for y in range(h))
    return data.translate(table)


def seed(mask, w, h, sx, sy, radius=4):
    """Точка щелчка или ближайшая к ней пустая: пиксель под курсором мог попасть на линию."""
    if not mask[sy * w + sx]:
        return sx, sy
    for r in range(1, radius + 1):
        for dx in range(-r, r + 1):
            for dy in (-r, r):
                for x, y in ((sx + dx, sy + dy), (sx + dy, sy + dx)):
                    if 0 <= x < w and 0 <= y < h and not mask[y * w + x]:
                        return x, y
    return None


def flood(mask, w, h, sx, sy, limit):
    """Заливка от точки до линий, строками. Больше limit пикселей – ушла за стены."""
    inside = bytearray(w * h)
    stack = [(sx, sy)]
    count = 0
    while stack:
        x, y = stack.pop()
        row = y * w
        if inside[row + x] or mask[row + x]:
            continue
        x0 = x
        while x0 > 0 and not inside[row + x0 - 1] and not mask[row + x0 - 1]:
            x0 -= 1
        x1 = x
        while x1 < w - 1 and not inside[row + x1 + 1] and not mask[row + x1 + 1]:
            x1 += 1
        inside[row + x0:row + x1 + 1] = b'\x01' * (x1 - x0 + 1)
        count += x1 - x0 + 1
        if count > limit:
            return None, count
        for ny in (y - 1, y + 1):
            if not 0 <= ny < h:
                continue
            nrow = ny * w
            i = x0
            while i <= x1:
                if not inside[nrow + i] and not mask[nrow + i]:
                    stack.append((i, ny))
                    while i <= x1 and not mask[nrow + i]:
                        i += 1
                i += 1
    return inside, count


def trace(inside, w, h):
    """Край залитой области: список пикселей по кругу (трассировка Мура от верхнего левого)."""
    start = None
    for i in range(w * h):
        if inside[i]:
            start = (i % w, i // w)
            break
    if start is None:
        return []
    contour = [start]
    cur, prev = start, (start[0] - 1, start[1])
    guard = 8 * (w + h)
    while len(contour) <= guard:
        back = (prev[0] - cur[0], prev[1] - cur[1])
        k = NEIGH.index(back)
        step = None
        for i in range(1, 9):
            d = NEIGH[(k + i) % 8]
            x, y = cur[0] + d[0], cur[1] + d[1]
            if 0 <= x < w and 0 <= y < h and inside[y * w + x]:
                before = NEIGH[(k + i - 1) % 8]
                step = ((x, y), (cur[0] + before[0], cur[1] + before[1]))
                break
        if step is None:
            break
        cur, prev = step
        if cur == start:
            break
        contour.append(cur)
    return contour


def rdp(points, eps):
    """Упрощение ломаной (Рамер – Дуглас – Пекер): точки дальше eps от хорды остаются."""
    if len(points) < 3:
        return points
    keep = [False] * len(points)
    keep[0] = keep[-1] = True
    stack = [(0, len(points) - 1)]
    while stack:
        a, b = stack.pop()
        if b <= a + 1:
            continue
        x1, y1 = points[a]
        x2, y2 = points[b]
        dx, dy = x2 - x1, y2 - y1
        n = math.hypot(dx, dy) or 1.0
        best, at = 0.0, None
        for i in range(a + 1, b):
            x, y = points[i]
            d = abs(dy * (x - x1) - dx * (y - y1)) / n
            if d > best:
                best, at = d, i
        if at is not None and best > eps:
            keep[at] = True
            stack.append((a, at))
            stack.append((at, b))
    return [p for p, k in zip(points, keep) if k]


def simplify_closed(contour, eps):
    """Упрощение замкнутой обводки: ломаную рвем в самой далекой от начала точке –
    на круге хорда "начало – начало" нулевая, и упрощать было бы нечего."""
    first = contour[0]
    far = max(range(len(contour)), key=lambda i: (contour[i][0] - first[0]) ** 2 + (contour[i][1] - first[1]) ** 2)
    return rdp(contour[:far + 1], eps)[:-1] + rdp(contour[far:] + [first], eps)[:-1]


def straighten(points, tol):
    """Стены прямые: почти вертикальные и почти горизонтальные стороны делаем ровными."""
    pts = [[float(x), float(y)] for x, y in points]
    n = len(pts)
    for i in range(n):
        a, b = pts[i], pts[(i + 1) % n]
        dx, dy = b[0] - a[0], b[1] - a[1]
        if abs(dx) <= tol and abs(dx) < abs(dy):
            a[0] = b[0] = (a[0] + b[0]) / 2
        elif abs(dy) <= tol and abs(dy) < abs(dx):
            a[1] = b[1] = (a[1] + b[1]) / 2
    # Совпавшие после выравнивания точки – убрать.
    out = []
    for p in pts:
        if not out or math.hypot(p[0] - out[-1][0], p[1] - out[-1][1]) > 0.75:
            out.append(p)
    if len(out) > 2 and math.hypot(out[0][0] - out[-1][0], out[0][1] - out[-1][1]) <= 0.75:
        out.pop()
    return out


def escape(mask, inside, w, h, sx, sy):
    """Щелкнули в кружке номера, в букве или в мебели: ближайшая пустая точка за этой областью."""
    row = [x for x in range(w) if inside[sy * w + x]] or [sx]
    col = [y for y in range(h) if inside[y * w + sx]] or [sy]
    ways = [((max(row), sy), (1, 0)), ((min(row), sy), (-1, 0)), ((sx, max(col)), (0, 1)), ((sx, min(col)), (0, -1))]
    for (x, y), (dx, dy) in ways:
        for _ in range(60):
            x += dx
            y += dy
            if not 0 <= x < w or not 0 <= y < h:
                break
            if not mask[y * w + x] and not inside[y * w + x]:
                return x, y
    return None


def outline(path, px, py, max_px, threshold):
    pix, w, h, factor = gray(path, max_px)
    mask = wall_mask(pix, w, h, threshold)
    sx = min(w - 1, max(0, int(px / factor)))
    sy = min(h - 1, max(0, int(py / factor)))

    start = seed(mask, w, h, sx, sy)
    if start is None:
        return {'error': 'wall'}

    # Пол этажа целиком – это утечка через проем: помещение столько места не занимает.
    limit = 0.45 * w * h
    inside, count = flood(mask, w, h, start[0], start[1], limit)
    if inside is None:
        return {'error': 'leak'}

    # Кружок номера помещения, буква подписи, мебель: залилась мелочь – выходим за нее и заливаем заново.
    for _ in range(3):
        if count >= 0.0012 * w * h:
            break
        out = escape(mask, inside, w, h, start[0], start[1])
        if out is None:
            break
        bigger, more = flood(mask, w, h, out[0], out[1], limit)
        if bigger is None:
            return {'error': 'leak'}
        start = out
        if more <= count:
            break
        inside, count = bigger, more
    if count < 400:
        return {'error': 'tiny'}
    # Залитое дошло до края листа – это не помещение, а поле вокруг здания или утечка.
    if (any(inside[x] or inside[(h - 1) * w + x] for x in range(w))
            or any(inside[y * w] or inside[y * w + w - 1] for y in range(h))):
        return {'error': 'leak'}

    contour = trace(inside, w, h)
    if len(contour) < 8:
        return {'error': 'tiny'}

    eps = max(1.5, min(w, h) / 400)
    simple = straighten(simplify_closed(contour, eps), max(2.0, eps))
    # Контур в сотню углов не нужен ни на плане, ни в rooms.polygon: упрощаем грубее.
    while len(simple) > 80 and eps < min(w, h) / 20:
        eps *= 1.6
        simple = straighten(simplify_closed(contour, eps), max(2.0, eps))
    if len(simple) < 3:
        return {'error': 'tiny'}

    return {
        'polygon': [[round((x + 0.5) * factor, 1), round((y + 0.5) * factor, 1)] for x, y in simple],
        'area_px': count * factor * factor,
    }


def main(argv):
    if len(argv) < 5 or argv[1] != 'outline':
        raise SystemExit('room_outline.py outline PNG X Y [MAX_PX] [THRESHOLD]')
    max_px = int(argv[5]) if len(argv) > 5 else 2000
    threshold = int(argv[6]) if len(argv) > 6 else 200
    json.dump(outline(argv[2], float(argv[3]), float(argv[4]), max_px, threshold), sys.stdout, ensure_ascii=False)


if __name__ == '__main__':
    sys.stdout.reconfigure(encoding='utf-8')
    main(sys.argv)
