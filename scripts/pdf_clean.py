"""
Чистка подложки из PDF по типам линий: размеры, оси, штриховка, заливки, подписи.

Слоев в выгрузках из CAD обычно нет, но каждый тип элемента нарисован своим "пером":
толщина и цвет линии, цвет заливки, размер и цвет текста. Скрипт разбирает поток команд
страницы, считает типы и выключает выбранные (путь закрывается без рисования, текст – режим 3,
невидимый). Геометрия листа не меняется, поэтому рамка обрезки и масштаб остаются в силе.

  pdf_clean.py analyze SRC PAGE OUT_PDF          – страница отдельным PDF, типы JSON в stdout
  pdf_clean.py apply   SRC PAGE OUT_PDF KEYS     – страница без типов KEYS (через ;)
  pdf_clean.py preview ONE_PAGE_PDF KEY OUT_PNG X0 Y0 X1 Y1 PX
                                                  – только тип KEY в рамке (доли листа), PNG с прозрачностью

Нужен PyMuPDF (pip pymupdf или python3-fitz).
"""
import json
import math
import re
import sys

import fitz

TOKEN = re.compile(rb"""
    (?P<ws>\s+)
  | (?P<comment>%[^\r\n]*)
  | (?P<str>\()
  | (?P<hex><(?!<)[0-9A-Fa-f\s]*>)
  | (?P<punct><<|>>|\[|\]|\{|\})
  | (?P<name>/[^\s/\[\]()<>{}%]*)
  | (?P<num>[+-]?(?:\d+\.?\d*|\.\d+))
  | (?P<op>[A-Za-z'"*][A-Za-z0-9'"*]*)
""", re.X)

PAINT = {b'S', b's', b'f', b'F', b'f*', b'B', b'B*', b'b', b'b*'}
STROKE = {b'S', b's', b'B', b'B*', b'b', b'b*'}
FILL = {b'f', b'F', b'f*', b'B', b'B*', b'b', b'b*'}
SHOW = {b'Tj', b'TJ', b"'", b'"'}


def tokens(data):
    """Токены потока: (вид, байты). Строки в скобках – целиком, с вложенными скобками и экранированием."""
    i, n = 0, len(data)
    while i < n:
        m = TOKEN.match(data, i)
        if not m:
            yield 'raw', data[i:i + 1]
            i += 1
            continue
        kind = m.lastgroup
        if kind == 'str':
            depth, j = 0, i
            while j < n:
                c = data[j]
                if c == 0x5C:  # \
                    j += 2
                    continue
                if c == 0x28:
                    depth += 1
                elif c == 0x29:
                    depth -= 1
                    if depth == 0:
                        break
                j += 1
            yield 'str', data[i:j + 1]
            i = j + 1
            continue
        if kind == 'op' and m.group() == b'ID':
            # Встроенная картинка: данные до EI как есть.
            end = data.find(b'EI', m.end())
            end = n if end < 0 else end + 2
            yield 'raw', data[i:end]
            i = end
            continue
        yield kind, m.group()
        i = m.end()


def color_name(rgb):
    r, g, b = rgb
    if max(rgb) - min(rgb) < 0.12:
        v = (r + g + b) / 3
        return 'черный' if v < 0.2 else 'темно-серый' if v < 0.45 else 'серый' if v < 0.7 else 'светло-серый' if v < 0.95 else 'белый'
    h = (math.degrees(math.atan2(math.sqrt(3) * (g - b), 2 * r - g - b)) + 360) % 360
    names = [(20, 'красный'), (45, 'оранжевый'), (70, 'желтый'), (160, 'зеленый'), (200, 'бирюзовый'), (260, 'синий'), (320, 'фиолетовый'), (360, 'красный')]
    return next(nm for lim, nm in names if h <= lim)


def to_rgb(op, nums):
    if op in (b'g', b'G') and len(nums) >= 1:
        return (nums[0],) * 3
    if op in (b'rg', b'RG') and len(nums) >= 3:
        return tuple(nums[:3])
    if op in (b'k', b'K') and len(nums) >= 4:
        c, m, y, k = nums[:4]
        return ((1 - c) * (1 - k), (1 - m) * (1 - k), (1 - y) * (1 - k))
    if len(nums) == 1:
        return (nums[0],) * 3
    if len(nums) == 3:
        return tuple(nums)
    if len(nums) == 4:
        return to_rgb(b'k', nums)
    return (0, 0, 0)


def mul(a, b):
    return [a[0] * b[0] + a[1] * b[2], a[0] * b[1] + a[1] * b[3],
            a[2] * b[0] + a[3] * b[2], a[2] * b[1] + a[3] * b[3],
            a[4] * b[0] + a[5] * b[2] + b[4], a[4] * b[1] + a[5] * b[3] + b[5]]


def scale(m):
    return math.sqrt(abs(m[0] * m[3] - m[1] * m[2])) or 1


def key_line(st):
    return 'L:%.2f:%s' % (st['w'] * scale(st['ctm']), '%.2f,%.2f,%.2f' % st['stroke'])


def key_fill(st):
    return 'F:%s' % ('%.2f,%.2f,%.2f' % st['fill'])


def key_text(st):
    return 'T:%.1f:%s' % (st['size'] * scale(mul(st['tm'], st['ctm'])), '%.2f,%.2f,%.2f' % st['fill'])


def process(data, hide=frozenset(), keep=None):
    """Пройти поток: посчитать типы и (если hide/keep) выключить ненужные. keep – оставить только эти."""
    off = (lambda k: k not in keep) if keep is not None else (lambda k: k in hide)
    st = {'ctm': [1, 0, 0, 1, 0, 0], 'w': 1.0, 'stroke': (0, 0, 0), 'fill': (0, 0, 0), 'size': 0, 'tm': [1, 0, 0, 1, 0, 0], 'tr': 0}
    stack, args, out, counts = [], [], [], {}
    clip_pending = False
    start = 0  # где в out начинаются операнды текущего оператора

    def count(k):
        counts[k] = counts.get(k, 0) + 1

    for kind, tok in tokens(data):
        if kind != 'op':
            out.append(tok)
            if kind != 'ws' and kind != 'comment':
                args.append((kind, tok))
            continue
        nums = [float(t) for k, t in args if k == 'num']
        op = tok
        args = []
        if op == b'q':
            stack.append({k: (list(v) if isinstance(v, list) else v) for k, v in st.items()})
        elif op == b'Q' and stack:
            st = stack.pop()
        elif op == b'cm' and len(nums) == 6:
            st['ctm'] = mul(nums, st['ctm'])
        elif op == b'w' and nums:
            st['w'] = nums[0]
        elif op in (b'G', b'RG', b'K', b'SC', b'SCN'):
            st['stroke'] = to_rgb(op, nums)
        elif op in (b'g', b'rg', b'k', b'sc', b'scn'):
            st['fill'] = to_rgb(op, nums)
        elif op == b'CS':
            st['stroke'] = (0, 0, 0)
        elif op == b'cs':
            st['fill'] = (0, 0, 0)
        elif op in (b'W', b'W*'):
            clip_pending = True
        elif op == b'BT':
            st['tm'] = [1, 0, 0, 1, 0, 0]
        elif op == b'Tf' and nums:
            st['size'] = nums[-1]
        elif op == b'Tm' and len(nums) == 6:
            st['tm'] = nums
        elif op == b'Tr' and nums:
            st['tr'] = int(nums[0])
        elif op in PAINT:
            if clip_pending:
                # Путь – еще и обрезка: рисование выключаем, обрезку оставляем.
                clip_pending = False
            s = key_line(st) if op in STROKE else None
            f = key_fill(st) if op in FILL else None
            for k in (s, f):
                if k:
                    count(k)
            hs, hf = s is not None and off(s), f is not None and off(f)
            if (s is None or hs) and (f is None or hf):
                op = b'n'
            elif hs:
                op = b'f*' if op in (b'B*', b'b*') else b'f'
                if tok in (b'b', b'b*'):
                    op = b'h ' + op
            elif hf:
                op = b's' if tok in (b'b', b'b*') else b'S'
        elif op == b'n':
            clip_pending = False
        elif op in SHOW:
            k = key_text(st)
            count(k)
            if off(k) and st['tr'] != 3:
                # Невидимый текст: позиция сдвигается как прежде, следующие строки на месте.
                out.insert(start, b' 3 Tr ')
                op = op + b' %d Tr' % st['tr']
        out.append(op)
        start = len(out)
    return b''.join(out), counts


def one_page(src, page):
    doc = fitz.open(src)
    doc.select([page - 1])
    return doc


def stream(doc):
    page = doc[0]
    xrefs = page.get_contents()
    return page, b'\n'.join(doc.xref_stream(x) for x in xrefs), xrefs


def put(doc, data):
    page, _, xrefs = stream(doc)
    doc.update_stream(xrefs[0], data)
    for x in xrefs[1:]:
        doc.update_stream(x, b'')


def label(k, n):
    kind, rest = k.split(':', 1)
    if kind == 'L':
        w, c = rest.split(':')
        return 'Линия %s, %s' % (w.replace('.', ','), color_name(tuple(map(float, c.split(',')))))
    if kind == 'F':
        return 'Заливка, %s' % color_name(tuple(map(float, rest.split(','))))
    size, c = rest.split(':')
    return 'Текст %s, %s' % (size.replace('.', ','), color_name(tuple(map(float, c.split(',')))))


def hexcolor(k):
    rgb = k.rsplit(':', 1)[1].split(',')
    return '#' + ''.join('%02x' % round(float(v) * 255) for v in rgb)


def main(argv):
    mode = argv[1]
    if mode in ('analyze', 'apply'):
        src, page, out = argv[2], int(argv[3]), argv[4]
        doc = one_page(src, page)
        _, data, _ = stream(doc)
        hide = frozenset(argv[5].split(';')) if mode == 'apply' and len(argv) > 5 and argv[5] else frozenset()
        new, counts = process(data, hide)
        if mode == 'apply':
            put(doc, new)
        doc.save(out, garbage=3, deflate=True)
        if mode == 'analyze':
            order = {'L': 0, 'F': 1, 'T': 2}
            types = [{'key': k, 'label': label(k, n), 'color': hexcolor(k), 'count': n}
                     for k, n in sorted(counts.items(), key=lambda kv: (order[kv[0][0]], -kv[1]))]
            json.dump({'types': types}, sys.stdout, ensure_ascii=False)
    elif mode == 'preview':
        src, key, out = argv[2], argv[3], argv[4]
        x0, y0, x1, y1, px = map(float, argv[5:10])
        doc = fitz.open(src)
        page, data, _ = stream(doc)
        new, _ = process(data, keep={key})
        put(doc, new)
        page = doc[0]
        r = page.rect
        clip = fitz.Rect(r.x0 + x0 * r.width, r.y0 + y0 * r.height, r.x0 + x1 * r.width, r.y0 + y1 * r.height)
        z = px / max(clip.width, clip.height)
        page.get_pixmap(matrix=fitz.Matrix(z, z), clip=clip, alpha=True).save(out)
    else:
        raise SystemExit('mode: analyze | apply | preview')


if __name__ == "__main__":
    sys.stdout.reconfigure(encoding="utf-8")
    main(sys.argv)
