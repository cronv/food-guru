"""
Общий набор для рилсов новых рецептов: раскладка сцен, подсказки с проверкой
ширины текста, запуск рендера и несколько новых предметов (кастрюля, противень,
блендер, яйцо, диск сырника, сито и т. д.). Движок — по-прежнему make_reel.py.

Переменные окружения:
  REEL_OUT      папка для готового mp4        (по умолчанию ./out)
  REEL_PREVIEW  папка: нарисовать по одному кадру на сцену и выйти (без ffmpeg)
"""
import math
import os
import random
import subprocess

from PIL import Image, ImageDraw

import make_reel as E
import make_pasta_reel as P

W, H, FPS, DUR = E.W, E.H, E.FPS, E.DUR
TOTAL = int(DUR * FPS)
CREAM, MUTED, GREEN, BRICK, BG_BOT = E.CREAM, E.MUTED, E.GREEN, E.BRICK, E.BG_BOT
STEEL = E.STEEL

_scratch = ImageDraw.Draw(Image.new("RGB", (10, 10)))


def dim(a):
    return E.mix(CREAM, BG_BOT, a)


# ---------- текст с проверкой ширины ----------
def ctext(d, y, text, fontfn, size, fill, spacing=0, maxw=940):
    """Центрированный текст; если не влезает — уменьшаем кегль."""
    while True:
        f = fontfn(size)
        w = sum(d.textlength(ch, font=f) + spacing for ch in text) - spacing if spacing \
            else d.textlength(text, font=f)
        if w <= maxw or size <= 20:
            break
        size -= 2
    E.center_text(d, y, text, f, fill, spacing)


def hc(layer, y, title, value, accent=GREEN, alpha=1.0):
    """E.hint_card, но с проверкой: заголовок и значение не должны наезжать друг на друга."""
    tw = _scratch.textlength(title, font=E.F_REG(42))
    vw = _scratch.textlength(value, font=E.F_BOLD(44))
    assert tw + vw + 36 <= W - 264, f"карточка не влезает: {title!r} / {value!r}"
    E.hint_card(layer, y, title, value, accent, alpha)


_FR = {0.25: "¼", 0.5: "½", 0.75: "¾"}


def fmt_amount(v):
    if v == int(v):
        return str(int(v))
    w = int(v)
    return (str(w) if w else "") + _FR[v - w]


# ---------- готовые сцены ----------
def make_title(draw_hero, line1, line2, sub, fact):
    def scene(layer, lt, dur, t):
        d = ImageDraw.Draw(layer)
        a = E.ease(lt / 0.6)
        out = 1.0 if lt < dur - 0.4 else 1 - E.ease((lt - (dur - 0.4)) / 0.4)
        al = min(a, out)
        rise = (1 - E.ease_out(lt / 0.9)) * 50
        draw_hero(layer, lt, rise * 0.4, t, al)
        ctext(d, 1230 + rise, line1, E.F_BLACK, 92, E.fade(CREAM, al), 2)
        ctext(d, 1335 + rise, line2, E.F_BLACK, 92, E.fade(BRICK, al), 2)
        ctext(d, 1475 + rise, sub, E.F_REG, 38, E.fade(MUTED, al))
        if lt > 1.0:
            b = E.ease((lt - 1.0) / 0.5)
            ctext(d, 1565, "СОХРАНИТЕ РЕЦЕПТ", E.F_BOLD, 32, E.fade(GREEN, b * al), 4)
            hc(layer, 1650, fact[0], fact[1], BRICK, b * al)
    return scene


def make_ingredients(portions, ing, draw_dish):
    n = len(ing)
    step = 130 if n <= 6 else 116
    y0 = 400 if n <= 6 else 372

    def scene(layer, lt, dur, t):
        d = ImageDraw.Draw(layer)
        ctext(d, 300, f"ИНГРЕДИЕНТЫ НА {portions}", E.F_BOLD, 36, dim(0.4), 3)
        draw_dish(layer, lt, t)
        for i, (name, amount, unit) in enumerate(ing):
            start = 0.2 + i * (0.4 if n <= 6 else 0.36)
            if lt < start:
                continue
            a = E.ease((lt - start) / 0.45)
            if amount == int(amount):
                shown = amount if a >= 1 else max(1, int(round(amount * E.ease_out((lt - start) / 0.6))))
            else:
                shown = None
            val = f"{shown if shown is not None else fmt_amount(amount)} {unit}"
            hc(layer, y0 + i * step, name, val, GREEN if unit in ("г", "мл") else BRICK, a)
    return scene


def make_step(label, lines, cards, draw_fn):
    """label — заголовок шага, lines — две строки пояснения, cards — две подсказки."""
    def scene(layer, lt, dur, t):
        d = ImageDraw.Draw(layer)
        ctext(d, 300, label, E.F_BOLD, 36, dim(0.4), 3)
        draw_fn(layer, lt, dur, t, E.ease(lt / (dur * 0.8)))
        ctext(d, 1240, lines[0], E.F_REG, 42, dim(0.1))
        ctext(d, 1304, lines[1], E.F_REG, 42, dim(0.35))
        hc(layer, 1420, cards[0][0], cards[0][1], cards[0][2], E.ease(lt / 0.5))
        hc(layer, 1550, cards[1][0], cards[1][1], cards[1][2], E.ease((lt - 0.5) / 0.5))
    return scene


def make_final(draw_dish, lines, fact):
    def scene(layer, lt, dur, t):
        d = ImageDraw.Draw(layer)
        draw_dish(layer, lt, t)
        a = E.ease((lt - 0.2) / 0.5)
        ctext(d, 1290, "ГОТОВО", E.F_BLACK, 100, E.fade(BRICK, a), 3)
        ctext(d, 1425, lines[0], E.F_REG, 42, E.fade(CREAM, a))
        ctext(d, 1481, lines[1], E.F_REG, 42, E.fade(CREAM, a))
        hc(layer, 1572, fact[0], fact[1], BRICK, E.ease((lt - 0.4) / 0.5))
        hc(layer, 1690, "Полный рецепт", "на сайте", GREEN, E.ease((lt - 0.9) / 0.6))
    return scene


# ---------- запуск ----------
def run(slug, scenes):
    out_dir = os.environ.get("REEL_OUT", "out")
    preview = os.environ.get("REEL_PREVIEW")

    bounds, acc = [], 0.0
    for dur, fn, label in scenes:
        bounds.append((acc, acc + dur, fn, dur, label))
        acc += dur
    assert abs(acc - DUR) < 0.05, f"длительность сцен {acc}, ожидалось {DUR}"

    base = E.build_background()
    grain = E.build_grain()

    def render(t):
        frame = base.copy().convert("RGBA")
        layer = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        label = ""
        for start, end, fn, dur, lab in bounds:
            if start <= t < end or (t >= DUR and end == acc):
                fn(layer, t - start, dur, t)
                label = lab
                break
        frame.alpha_composite(layer)
        frame.alpha_composite(grain)
        E.draw_chrome(ImageDraw.Draw(frame), t, label)
        return frame.convert("RGB")

    if preview:
        os.makedirs(preview, exist_ok=True)
        for k, (start, end, fn, dur, lab) in enumerate(bounds):
            t = min(start + dur * 0.9, DUR - 1 / FPS)
            render(t).save(f"{preview}/{slug}_{k}.png")
        print("превью готово")
        return

    # кадры идут прямо в ffmpeg по трубе — на диск ничего не пишется
    # (PNG с плёночным зерном весят ~2 МБ, 480 штук забивают диск)
    os.makedirs(out_dir, exist_ok=True)
    proc = subprocess.Popen([
        "ffmpeg", "-y", "-loglevel", "error",
        "-f", "rawvideo", "-pix_fmt", "rgb24", "-s", f"{W}x{H}", "-framerate", str(FPS), "-i", "-",
        "-c:v", "libx264", "-pix_fmt", "yuv420p", "-crf", "24",
        "-movflags", "+faststart", f"{out_dir}/{slug}.mp4",
    ], stdin=subprocess.PIPE)
    for i in range(TOTAL):
        proc.stdin.write(render(i / FPS).tobytes())
    proc.stdin.close()
    if proc.wait() != 0:
        raise SystemExit("ffmpeg завершился с ошибкой")
    print("готово", slug)


# ---------- предметы ----------
def disc(layer, cx, cy, rx, thick, top, side_hi, side_lo, ry=None):
    """Цилиндр-шайба: боковая стенка + верхний эллипс."""
    ry = ry or rx * 0.36
    E.shaded_ellipse(layer, [cx - rx, cy + thick - ry, cx + rx, cy + thick + ry], side_lo, side_lo)
    E.shaded_poly(layer, [(cx - rx, cy), (cx + rx, cy), (cx + rx, cy + thick), (cx - rx, cy + thick)],
                  side_hi, side_lo)
    E.shaded_ellipse(layer, [cx - rx, cy + thick - ry, cx + rx, cy + thick + ry], side_lo,
                     E.mix(side_lo, (0, 0, 0), 0.2))
    E.shaded_poly(layer, [(cx - rx, cy), (cx + rx, cy), (cx + rx, cy + thick), (cx - rx, cy + thick)],
                  side_hi, side_lo)
    E.shaded_ellipse(layer, [cx - rx, cy - ry, cx + rx, cy + ry], E.mix(top, (255, 255, 255), 0.12), top)


def draw_pot(layer, cx, cy, scale=1.0, content=None, content_dark=None, swirl=0.0, bubbles=0.0, t=0.0):
    """Кастрюля с вертикальными стенками и ручками."""
    d = ImageDraw.Draw(layer)
    rx, ry, h = 250 * scale, 78 * scale, 240 * scale
    E.cast_shadow(layer, cx + 24 * scale, cy + h + ry * 0.7, rx * 1.05, ry=ry * 0.8, alpha=165,
                  blur=int(30 * scale))
    # ручки
    for x0, x1 in ((cx - rx - 46 * scale, cx - rx + 6 * scale), (cx + rx - 6 * scale, cx + rx + 46 * scale)):
        E.rounded(d, [x0, cy + 22 * scale, x1, cy + 52 * scale], int(12 * scale),
                  fill=E.mix(STEEL, (30, 30, 34), 0.5))
    # корпус
    E.shaded_ellipse(layer, [cx - rx, cy + h - ry, cx + rx, cy + h + ry],
                     E.mix(STEEL, (0, 0, 0), 0.55), E.mix(STEEL, (0, 0, 0), 0.7))
    E.shaded_poly(layer, [(cx - rx, cy), (cx + rx, cy), (cx + rx, cy + h), (cx - rx, cy + h)],
                  E.mix(STEEL, (255, 255, 255), 0.2), E.mix(STEEL, (24, 24, 28), 0.7))
    E.soft(layer, lambda dd: dd.rectangle([cx + rx * 0.45, cy, cx + rx, cy + h], fill=(0, 0, 0, 80)),
           int(16 * scale))
    E.gloss(layer, [cx - rx * 0.86, cy + h * 0.1, cx - rx * 0.62, cy + h * 0.82], alpha=70, blur=10)
    # ободок
    E.shaded_ellipse(layer, [cx - rx, cy - ry, cx + rx, cy + ry],
                     E.mix(STEEL, (255, 255, 255), 0.35), E.mix(STEEL, (60, 60, 66), 0.5))
    E.shaded_ellipse(layer, [cx - rx * 0.93, cy - ry * 0.86, cx + rx * 0.93, cy + ry * 0.86],
                     E.mix(STEEL, (0, 0, 0), 0.7), E.mix(STEEL, (0, 0, 0), 0.8))
    if content:
        bx0, by0, bx1, by1 = cx - rx * 0.88, cy - ry * 0.74, cx + rx * 0.88, cy + ry * 0.78
        E.shaded_ellipse(layer, [bx0, by0, bx1, by1], content,
                         content_dark or E.mix(content, (0, 0, 0), 0.4))
        E.gloss(layer, [cx - rx * 0.55, cy - ry * 0.5, cx - rx * 0.1, cy - ry * 0.18], alpha=70, blur=9)
        if bubbles > 0:
            for i in range(9):
                ph = (t * 0.9 + i * 0.37) % 1.0
                a = i * 2.4
                xx = cx + math.cos(a) * rx * 0.62 * ((i % 4 + 1) / 4.2)
                yy = cy + math.sin(a) * ry * 0.5
                r = (4 + ph * 9) * scale
                if ph < 0.85:
                    d.ellipse([xx - r, yy - r * 0.7, xx + r, yy + r * 0.7],
                              outline=E.mix(content, (255, 255, 255), 0.7), width=2)


def spaghetti_in(layer, cx, cy, rx, ry, color, amount=1.0, phase=0.0):
    """Спагетти кольцами внутри эллипса (кастрюля/вид сверху)."""
    d = ImageDraw.Draw(layer)
    n = int(14 * amount)
    for i in range(n):
        a = i * 0.9 + phase
        rr = 0.25 + 0.62 * ((i * 7) % 11) / 11
        xx, yy = cx + math.cos(a) * rx * rr * 0.7, cy + math.sin(a) * ry * rr * 0.7
        w, h = rx * 0.34, ry * 0.3
        st = int(math.degrees(a) * 2) % 360
        d.arc([xx - w, yy - h, xx + w, yy + h], st, st + 160, fill=color, width=8)


def cube(layer, x, y, s, col, bake=0.0):
    """Кубик в изометрии: верх светлее, грани темнее; bake карамелизует края."""
    c = E.mix(col, (150, 76, 24), bake * 0.45)
    d = ImageDraw.Draw(layer)
    top = [(x, y - s * 0.55), (x + s, y - s * 0.05), (x, y + s * 0.45), (x - s, y - s * 0.05)]
    left = [(x - s, y - s * 0.05), (x, y + s * 0.45), (x, y + s * 1.05), (x - s, y + s * 0.55)]
    right = [(x + s, y - s * 0.05), (x, y + s * 0.45), (x, y + s * 1.05), (x + s, y + s * 0.55)]
    d.polygon(left, fill=E.mix(c, (0, 0, 0), 0.28))
    d.polygon(right, fill=E.mix(c, (0, 0, 0), 0.45))
    d.polygon(top, fill=E.mix(c, (255, 232, 180), 0.18))
    d.line([top[0], top[1], top[2], top[3], top[0]], fill=E.mix(c, (90, 40, 10), 0.4 + bake * 0.3), width=2)


def draw_tray(layer, cx, cy, scale=1.0, cubes=None, bake=0.0, warm=0.0):
    """Противень с кубиками тыквы (cubes — цвет кубика)."""
    s = scale
    E.cast_shadow(layer, cx + 24 * s, cy + 190 * s, 400 * s, ry=62 * s, alpha=165, blur=int(30 * s))
    # борт и дно
    E.shaded_poly(layer, [(cx - 400 * s, cy - 120 * s), (cx + 400 * s, cy - 120 * s),
                          (cx + 440 * s, cy + 150 * s), (cx - 440 * s, cy + 150 * s)],
                  E.mix(STEEL, (255, 255, 255), 0.15), E.mix(STEEL, (30, 30, 34), 0.6))
    E.shaded_poly(layer, [(cx - 380 * s, cy - 104 * s), (cx + 380 * s, cy - 104 * s),
                          (cx + 412 * s, cy + 120 * s), (cx - 412 * s, cy + 120 * s)],
                  E.mix((86, 84, 88), (176, 84, 36), warm * 0.5), E.mix((46, 44, 48), (120, 54, 24), warm * 0.5))
    E.shaded_poly(layer, [(cx - 440 * s, cy + 150 * s), (cx + 440 * s, cy + 150 * s),
                          (cx + 430 * s, cy + 176 * s), (cx - 430 * s, cy + 176 * s)],
                  E.mix(STEEL, (0, 0, 0), 0.3), E.mix(STEEL, (0, 0, 0), 0.65))
    E.gloss(layer, [cx - 370 * s, cy - 118 * s, cx - 60 * s, cy - 92 * s], alpha=90, blur=8)
    if cubes:
        rnd = random.Random(7)
        pos = []
        for r in range(3):
            for c in range(5):
                pos.append((cx + (c - 2) * 150 * s + (r % 2) * 40 * s + rnd.uniform(-14, 14) * s,
                            cy - 42 * s + r * 70 * s + rnd.uniform(-6, 6) * s))
        for (x, y) in sorted(pos, key=lambda p: p[1]):
            cube(layer, x, y, 52 * s, cubes, bake)


def draw_blender(layer, cx, cy, scale=1.0, fill=None, smooth=0.0, spin=0.0):
    """Блендерный стакан на основании; fill — цвет супа."""
    s = scale
    d = ImageDraw.Draw(layer)
    E.cast_shadow(layer, cx + 22 * s, cy + 330 * s, 190 * s, ry=34 * s, alpha=170, blur=int(24 * s))
    # основание
    E.shaded_poly(layer, [(cx - 150 * s, cy + 200 * s), (cx + 150 * s, cy + 200 * s),
                          (cx + 178 * s, cy + 330 * s), (cx - 178 * s, cy + 330 * s)],
                  (74, 72, 78), (22, 22, 26))
    E.rounded(d, [cx - 28 * s, cy + 244 * s, cx + 28 * s, cy + 276 * s], int(14 * s), fill=BRICK)
    # стакан (стекло)
    jug = [(cx - 160 * s, cy - 260 * s), (cx + 160 * s, cy - 260 * s),
           (cx + 118 * s, cy + 200 * s), (cx - 118 * s, cy + 200 * s)]
    E.soft(layer, lambda dd: dd.polygon(jug, fill=(200, 215, 222, 70)), 2)
    # суп
    top_y = cy - 40 * s
    k = (top_y - (cy - 260 * s)) / (460 * s)
    half_top = 160 * s - (160 - 118) * s * k
    if fill:
        E.shaded_poly(layer, [(cx - half_top, top_y), (cx + half_top, top_y),
                              (cx + 118 * s, cy + 200 * s), (cx - 118 * s, cy + 200 * s)],
                      E.mix(fill, (255, 255, 255), 0.06), E.mix(fill, (0, 0, 0), 0.45))
        E.shaded_ellipse(layer, [cx - half_top, top_y - 22 * s, cx + half_top, top_y + 22 * s],
                         E.mix(fill, (255, 255, 255), 0.12), fill)
        # кусочки — исчезают по мере пюрирования
        rnd = random.Random(3)
        for i in range(16):
            if i / 16 > 1 - smooth:
                continue
            a = spin * 6 + i * 1.7
            xx = cx + math.cos(a) * half_top * 0.6 * ((i % 4 + 1) / 4)
            yy = top_y + 70 * s + (i % 5) * 18 * s + math.sin(a) * 8 * s
            r = (9 + (i % 3) * 3) * s
            d.ellipse([xx - r, yy - r * 0.8, xx + r, yy + r * 0.8], fill=E.mix(fill, (255, 200, 120), 0.25))
        # воронка
        E.soft(layer, lambda dd: dd.ellipse([cx - 40 * s, top_y - 12 * s, cx + 40 * s, top_y + 10 * s],
                                            fill=(0, 0, 0, 70)), 6)
        for i in range(4):
            a = spin * 10 + i * 1.57
            xx, yy = cx + math.cos(a) * half_top * 0.5, top_y + math.sin(a) * 8 * s
            d.ellipse([xx - 22 * s, yy - 4 * s, xx + 22 * s, yy + 4 * s], fill=E.mix(fill, (255, 255, 255), 0.16))
    # блик на стекле и обод
    E.gloss(layer, [cx - 138 * s, cy - 230 * s, cx - 108 * s, cy + 150 * s], alpha=90, blur=8)
    E.soft(layer, lambda dd: dd.line([jug[0], jug[3]], fill=(240, 246, 250, 130), width=4), 1)
    E.soft(layer, lambda dd: dd.line([jug[1], jug[2]], fill=(240, 246, 250, 100), width=4), 1)
    E.shaded_ellipse(layer, [cx - 166 * s, cy - 276 * s, cx + 166 * s, cy - 244 * s],
                     E.mix(STEEL, (255, 255, 255), 0.3), E.mix(STEEL, (60, 60, 66), 0.5))
    E.shaded_poly(layer, [(cx - 130 * s, cy - 296 * s), (cx + 130 * s, cy - 296 * s),
                          (cx + 150 * s, cy - 262 * s), (cx - 150 * s, cy - 262 * s)],
                  (66, 64, 70), (28, 28, 32))


def chickpea(layer, x, y, r, toast=0.0):
    c = E.mix((226, 196, 142), (176, 112, 52), toast)
    d = ImageDraw.Draw(layer)
    d.ellipse([x - r, y - r * 0.9, x + r, y + r * 0.9], fill=E.mix(c, (0, 0, 0), 0.25))
    d.ellipse([x - r * 0.9, y - r * 0.9, x + r * 0.85, y + r * 0.75], fill=c)
    d.ellipse([x - r * 0.55, y - r * 0.7, x - r * 0.05, y - r * 0.3], fill=E.mix(c, (255, 244, 214), 0.5))


def egg(layer, cx, cy, s=1.0, cooked=1.0, yolk_hi=True):
    """Яйцо-глазунья: белок-клякса и желток."""
    white = E.mix((226, 214, 188), (252, 250, 244), cooked)
    shade = E.mix((150, 128, 100), (214, 208, 196), cooked)
    E.shaded_ellipse(layer, [cx - 74 * s, cy - 50 * s, cx + 80 * s, cy + 54 * s], white, shade)
    E.shaded_ellipse(layer, [cx - 48 * s, cy - 62 * s, cx + 50 * s, cy + 30 * s], white, shade)
    E.shaded_ellipse(layer, [cx + 10 * s, cy - 30 * s, cx + 92 * s, cy + 40 * s], white, shade)
    E.shaded_ellipse(layer, [cx - 30 * s, cy - 34 * s, cx + 30 * s, cy + 22 * s],
                     (255, 196, 64), (222, 128, 18))
    if yolk_hi:
        E.gloss(layer, [cx - 20 * s, cy - 28 * s, cx + 2 * s, cy - 12 * s], alpha=200, blur=3)


def glass_lid(layer, cx, cy, rx, ry, lift=0.0):
    """Прозрачная стеклянная крышка с металлическим ободком и ручкой."""
    cy = cy - lift
    E.soft(layer, lambda dd: dd.ellipse([cx - rx, cy - ry, cx + rx, cy + ry], fill=(206, 220, 226, 60),
                                        outline=(226, 232, 236, 200), width=7), 1.5)
    E.gloss(layer, [cx - rx * 0.7, cy - ry * 0.7, cx - rx * 0.2, cy - ry * 0.3], alpha=70, blur=12)
    d = ImageDraw.Draw(layer)
    d.ellipse([cx - 22, cy - 12, cx + 22, cy + 12], fill=(54, 52, 56))
    d.ellipse([cx - 14, cy - 10, cx + 8, cy], fill=(130, 128, 134))


def powder(layer, cx, cy, rx, ry, n=34, seed=1, col=(250, 247, 240)):
    rnd = random.Random(seed)
    d = ImageDraw.Draw(layer)
    for _ in range(n):
        a, r = rnd.uniform(0, 6.28), math.sqrt(rnd.random())
        x, y = cx + math.cos(a) * rx * r, cy + math.sin(a) * ry * r
        rr = rnd.uniform(2, 4.2)
        d.ellipse([x - rr, y - rr, x + rr, y + rr], fill=col + (220,))


def falling(layer, t, x0, x1, y0, y1, color, n=14, size=6, speed=0.85, alpha=200, seed=2):
    """Сыплется сверху: мука, сыр, специи."""
    rnd = random.Random(seed)
    items = [(rnd.uniform(x0, x1), rnd.random()) for _ in range(n)]

    def paint(dd):
        for i, (xx, off) in enumerate(items):
            ph = (t * speed + off) % 1.0
            yy = y0 + ph * (y1 - y0)
            rr = size * (0.7 + (i % 3) * 0.25)
            dd.ellipse([xx - rr, yy - rr, xx + rr, yy + rr], fill=color + (int(alpha * (1 - ph * 0.6)),))
    E.soft(layer, paint, 2.5)


def timer_ring(layer, cx, cy, r, progress, text, accent=BRICK):
    """Круглый таймер: подложка, дуга прогресса, число."""
    d = ImageDraw.Draw(layer)
    E.cast_shadow(layer, cx + 10, cy + r + 8, r * 0.9, ry=r * 0.2, alpha=130, blur=14)
    d.ellipse([cx - r, cy - r, cx + r, cy + r], fill=(30, 26, 20, 240), outline=(255, 255, 255, 40), width=3)
    d.arc([cx - r + 12, cy - r + 12, cx + r - 12, cy + r - 12], -90, -90 + 360 * progress,
          fill=accent, width=12)
    f = E.F_BLACK(int(r * 0.44))
    tw = d.textlength(text, font=f)
    d.text((cx - tw / 2, cy - r * 0.27), text, font=f, fill=CREAM)


def plate(layer, cx, cy, scale=1.0):
    P.draw_plate(layer, cx, cy, scale)


def oil_slick(layer, cx, cy, rx, ry):
    E.shaded_ellipse(layer, [cx - rx, cy - ry, cx + rx, cy + ry], (150, 108, 40), (98, 64, 22))
    E.gloss(layer, [cx - rx * 0.6, cy - ry * 0.6, cx - rx * 0.1, cy - ry * 0.2], alpha=80, blur=10)
