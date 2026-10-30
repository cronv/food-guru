"""
Рилс «Шоколадный фондан» — 20 секунд, 1080x1920, 24 кадра в секунду.

Рендер объёмный: направленный свет сверху-слева, градиентные заливки,
блики, мягкие тени на столешнице, плёночное зерно и виньетка.
Иллюстрации оригинальные, рисуются кодом — фотостоки не используются.
"""
import math
import os
import subprocess

from PIL import Image, ImageDraw, ImageFilter, ImageOps

W, H = 1080, 1920
FPS = 24
DUR = 20.0
TOTAL = int(DUR * FPS)
# Каталог кадров и готового ролика можно переопределить — так скрипт работает в Docker
# (см. README, «Рендер в Docker»). По умолчанию пути прежние.
REEL_DIR = os.environ.get("REEL_DIR", "/home/claude/reel")
OUT = os.path.join(REEL_DIR, "frames")
# Подпись в углу кадра и на обложке превью (tools/og/make_og.py).
BRAND = os.environ.get("REEL_BRAND", "FOOD GURU")
TABLE_Y = 1168  # линия столешницы: объекты стоят на ней

BG_TOP = (18, 16, 12)
BG_BOT = (30, 26, 20)
TABLE = (44, 37, 28)
TABLE_HI = (64, 53, 39)
CREAM = (247, 242, 231)
MUTED = (166, 157, 137)
GREEN = (140, 201, 160)
BRICK = (232, 129, 79)
CHOC_D = (42, 23, 14)
CHOC = (82, 45, 25)
CHOC_L = (138, 84, 48)
GOLD = (214, 160, 78)
CERAMIC = (226, 216, 198)
STEEL = (198, 198, 204)

FDIR = os.environ.get("REEL_FONTS", "/usr/share/fonts/opentype/inter")
from PIL import ImageFont  # noqa: E402


def font(name, size):
    return ImageFont.truetype(os.path.join(FDIR, name), size)


F_BLACK = lambda s: font("InterDisplay-Black.otf", s)
F_BOLD = lambda s: font("Inter-Bold.otf", s)
F_REG = lambda s: font("Inter-Regular.otf", s)


# ---------- математика ----------
def ease(t):
    t = max(0.0, min(1.0, t))
    return t * t * (3 - 2 * t)


def ease_out(t):
    t = max(0.0, min(1.0, t))
    return 1 - (1 - t) ** 3


def mix(c1, c2, t):
    t = max(0.0, min(1.0, t))
    return tuple(int(a + (b - a) * t) for a, b in zip(c1, c2))


def fade(c, alpha):
    return mix(BG_BOT, c, max(0.0, min(1.0, alpha)))


# ---------- слои и материалы ----------
_grad_cache = {}


def vgrad(size, c_top, c_bot):
    """Вертикальный градиент нужного размера — с кэшем, иначе 480 кадров считаются вечно."""
    key = (size, c_top, c_bot)
    if key not in _grad_cache:
        ramp = Image.linear_gradient("L").resize(size)
        _grad_cache[key] = ImageOps.colorize(ramp, c_top, c_bot).convert("RGBA")
    return _grad_cache[key]


def shaded_ellipse(layer, box, c_top, c_bot):
    """Эллипс с вертикальным градиентом — даёт объём вместо плоской заливки."""
    x0, y0, x1, y1 = [int(v) for v in box]
    w, h = max(1, x1 - x0), max(1, y1 - y0)
    mask = Image.new("L", (w, h), 0)
    ImageDraw.Draw(mask).ellipse([0, 0, w - 1, h - 1], fill=255)
    layer.paste(vgrad((w, h), c_top, c_bot), (x0, y0), mask)


def shaded_poly(layer, pts, c_top, c_bot):
    xs = [p[0] for p in pts]
    ys = [p[1] for p in pts]
    x0, y0, x1, y1 = int(min(xs)), int(min(ys)), int(max(xs)) + 1, int(max(ys)) + 1
    w, h = max(1, x1 - x0), max(1, y1 - y0)
    mask = Image.new("L", (w, h), 0)
    ImageDraw.Draw(mask).polygon([(p[0] - x0, p[1] - y0) for p in pts], fill=255)
    layer.paste(vgrad((w, h), c_top, c_bot), (x0, y0), mask)


def soft(layer, draw_fn, blur=18):
    """
    Рисует что-то на отдельном слое и размывает — тени, блики, пар.
    Размывается только область вокруг нарисованного: блюр полного кадра
    на каждый вызов делает рендер 480 кадров неприлично долгим.
    """
    tmp = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    draw_fn(ImageDraw.Draw(tmp))
    bbox = tmp.getbbox()
    if not bbox:
        return
    pad = int(blur * 3) + 4
    x0 = max(0, bbox[0] - pad)
    y0 = max(0, bbox[1] - pad)
    x1 = min(W, bbox[2] + pad)
    y1 = min(H, bbox[3] + pad)
    patch = tmp.crop((x0, y0, x1, y1)).filter(ImageFilter.GaussianBlur(blur))
    layer.alpha_composite(patch, (x0, y0))


def cast_shadow(layer, cx, y, rx, ry=None, alpha=150, blur=26):
    """Контактная тень на столешнице — без неё предмет висит в воздухе."""
    ry = ry or rx * 0.26
    soft(layer, lambda d: d.ellipse([cx - rx, y - ry, cx + rx, y + ry], fill=(0, 0, 0, alpha)), blur)


def gloss(layer, box, alpha=90, blur=14):
    # координаты могут прийти перевёрнутыми (поворот, спираль) — нормализуем
    x0, y0, x1, y1 = box
    nb = [min(x0, x1), min(y0, y1), max(x0, x1), max(y0, y1)]
    if nb[2] - nb[0] < 1 or nb[3] - nb[1] < 1:
        return
    soft(layer, lambda d: d.ellipse(nb, fill=(255, 250, 240, alpha)), blur)


def center_text(d, y, text, f, fill, spacing=0):
    if spacing:
        total = sum(d.textlength(ch, font=f) + spacing for ch in text) - spacing
        x = (W - total) / 2
        for ch in text:
            d.text((x, y), ch, font=f, fill=fill)
            x += d.textlength(ch, font=f) + spacing
        return
    w = d.textlength(text, font=f)
    d.text(((W - w) / 2, y), text, font=f, fill=fill)


def rounded(d, box, r, fill, outline=None, width=2):
    d.rounded_rectangle(box, radius=r, fill=fill, outline=outline, width=width)


# ---------- сцена: фон и столешница ----------
def build_background():
    bg = vgrad((W, H), BG_TOP, BG_BOT).copy()

    # тёплый ключевой свет сверху-слева
    glow = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    gd = ImageDraw.Draw(glow)
    for i in range(16):
        r = 1180 - i * 62
        gd.ellipse([W * 0.34 - r, 560 - r * 1.05, W * 0.34 + r, 560 + r * 1.05],
                   fill=(96, 72, 44, 7))
    bg.alpha_composite(glow.filter(ImageFilter.GaussianBlur(40)))

    # столешница
    table = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    td = ImageDraw.Draw(table)
    td.rectangle([0, TABLE_Y, W, H], fill=TABLE + (255,))
    # блик вдоль ближнего края стола
    for i in range(70):
        a = int(42 * (1 - i / 70))
        td.line([(0, TABLE_Y + i), (W, TABLE_Y + i)], fill=TABLE_HI + (a,))
    # древесные прожилки
    for i in range(120):
        y = TABLE_Y + 8 + (i * 37) % (H - TABLE_Y - 10)
        x0 = (i * 211) % W
        td.line([(x0, y), (x0 + 120 + (i % 7) * 40, y)],
                fill=mix(TABLE, TABLE_HI, 0.5) + (26,), width=2)
    bg.alpha_composite(table.filter(ImageFilter.GaussianBlur(1.2)))

    # мягкая тень под линией стола, чтобы край не был резким
    edge = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    ImageDraw.Draw(edge).rectangle([0, TABLE_Y - 30, W, TABLE_Y + 6], fill=(0, 0, 0, 90))
    bg.alpha_composite(edge.filter(ImageFilter.GaussianBlur(22)))

    # виньетка
    vign = Image.new("L", (W, H), 0)
    ImageDraw.Draw(vign).ellipse([-W * 0.3, -H * 0.12, W * 1.3, H * 1.12], fill=255)
    vign = vign.filter(ImageFilter.GaussianBlur(220))
    dark = Image.new("RGBA", (W, H), (0, 0, 0, 255))
    bg = Image.composite(bg, Image.alpha_composite(bg, dark.copy()), vign)

    return bg.convert("RGB")


def build_grain():
    n = Image.effect_noise((W, H), 26).convert("L")
    return Image.merge("RGBA", (n, n, n, Image.new("L", (W, H), 16)))


# ---------- предметы ----------
def draw_ramekin(layer, cx, cy, scale=1.0, fill_level=0.0, warm=0.0, shadow=True):
    """Керамическая форма: конус с рёбрами, бликом на ободке и тенью."""
    d = ImageDraw.Draw(layer)
    rw, rh = 210 * scale, 150 * scale
    body_hi = mix(CERAMIC, (255, 252, 244), 0.5)
    body_lo = mix(CERAMIC, (78, 70, 58), 0.55)
    body_hi = mix(body_hi, (252, 206, 158), warm * 0.45)
    body_lo = mix(body_lo, (150, 92, 54), warm * 0.45)

    if shadow:
        cast_shadow(layer, cx + 22 * scale, cy + rh * 0.66, rw * 1.08,
                    alpha=160, blur=int(26 * scale))

    # корпус
    shaded_poly(layer, [(cx - rw, cy - rh * 0.5), (cx + rw, cy - rh * 0.5),
                        (cx + rw * 0.78, cy + rh * 0.62), (cx - rw * 0.78, cy + rh * 0.62)],
                body_hi, body_lo)
    # рёбра
    for i in range(-5, 6):
        x = cx + rw * 0.16 * i
        shade = 0.10 + 0.16 * abs(i + 1.4) / 6
        d.line([(x, cy - rh * 0.42), (cx + (x - cx) * 0.78, cy + rh * 0.58)],
               fill=mix(body_lo, (0, 0, 0), shade), width=max(2, int(3 * scale)))
    # правая сторона в тени
    soft(layer, lambda dd: dd.polygon(
        [(cx + rw * 0.4, cy - rh * 0.5), (cx + rw, cy - rh * 0.5),
         (cx + rw * 0.78, cy + rh * 0.62), (cx + rw * 0.33, cy + rh * 0.62)],
        fill=(0, 0, 0, 70)), int(18 * scale))

    # ободок
    shaded_ellipse(layer, [cx - rw, cy - rh * 0.5 - 20 * scale, cx + rw, cy - rh * 0.5 + 20 * scale],
                   mix(body_hi, (255, 255, 250), 0.4), body_lo)
    # внутренность
    inner_top = mix(CHOC_D, CHOC, 0.35) if fill_level > 0 else mix(body_lo, (0, 0, 0), 0.35)
    inner_bot = mix(CHOC_D, (0, 0, 0), 0.3) if fill_level > 0 else mix(body_lo, (0, 0, 0), 0.6)
    shaded_ellipse(layer, [cx - rw * 0.86, cy - rh * 0.5 - 13 * scale,
                           cx + rw * 0.86, cy - rh * 0.5 + 13 * scale], inner_top, inner_bot)
    if fill_level > 0:
        gloss(layer, [cx - rw * 0.42, cy - rh * 0.56, cx - rw * 0.02, cy - rh * 0.47],
              alpha=60, blur=8)
    # блик на ободке
    gloss(layer, [cx - rw * 0.86, cy - rh * 0.62, cx - rw * 0.18, cy - rh * 0.5], alpha=110, blur=9)


def draw_bowl(layer, cx, cy, scale=1.0, content=None, content_dark=None, swirl=0.0, shadow=True):
    """Стеклянная миска: полусфера с градиентом, тенью и бликом."""
    d = ImageDraw.Draw(layer)
    rw, rh = 250 * scale, 120 * scale

    if shadow:
        cast_shadow(layer, cx + 26 * scale, cy + rh * 1.78, rw * 1.02, alpha=165,
                    blur=int(30 * scale))

    # чаша
    bowl_box = [cx - rw, cy - rh, cx + rw, cy + rh * 1.9]
    bw, bh = int(bowl_box[2] - bowl_box[0]), int(bowl_box[3] - bowl_box[1])
    mask = Image.new("L", (bw, bh), 0)
    ImageDraw.Draw(mask).chord([0, 0, bw - 1, bh - 1], 0, 180, fill=255)
    layer.paste(vgrad((bw, bh), mix(CERAMIC, (255, 255, 250), 0.25),
                      mix(CERAMIC, (52, 46, 38), 0.72)), (int(bowl_box[0]), int(bowl_box[1])), mask)

    # содержимое
    if content:
        shaded_ellipse(layer, [cx - rw * 0.9, cy - rh * 0.3, cx + rw * 0.9, cy + rh * 0.3],
                       content, content_dark or mix(content, (0, 0, 0), 0.45))
        if swirl > 0:
            for i in range(6):
                a = swirl * 4.4 + i * 1.05
                rr = rw * 0.6 * (0.3 + 0.12 * i)
                xx = cx + math.cos(a) * rr
                yy = cy + math.sin(a) * rr * 0.3
                d.ellipse([xx - 30 * scale, yy - 10 * scale, xx + 30 * scale, yy + 10 * scale],
                          fill=mix(content, (255, 255, 255), 0.14))
        # зеркальный блик на поверхности
        gloss(layer, [cx - rw * 0.56, cy - rh * 0.26, cx - rw * 0.04, cy - rh * 0.04],
              alpha=80, blur=11)

    # ободок и блик на стекле
    shaded_ellipse(layer, [cx - rw, cy - rh * 0.34, cx + rw, cy + rh * 0.34],
                   mix(CERAMIC, (255, 255, 252), 0.45), mix(CERAMIC, (90, 82, 70), 0.5))
    if content:
        shaded_ellipse(layer, [cx - rw * 0.9, cy - rh * 0.3, cx + rw * 0.9, cy + rh * 0.3],
                       content, content_dark or mix(content, (0, 0, 0), 0.45))
        gloss(layer, [cx - rw * 0.5, cy - rh * 0.24, cx - rw * 0.08, cy - rh * 0.06],
              alpha=70, blur=10)
    gloss(layer, [cx - rw * 0.92, cy + rh * 0.2, cx - rw * 0.5, cy + rh * 1.3], alpha=48, blur=16)


def draw_steam(layer, cx, base_y, t, strength=1.0, spread=130, scale=1.0):
    if strength <= 0.02:
        return

    def paint(d):
        for i in range(5):
            ph = (t * 0.55 + i * 0.21) % 1.0
            yy = base_y - ph * 320 * scale
            xx = cx + (i - 2) * spread * 0.42 + math.sin(ph * 5 + i * 2) * 42 * scale
            a = int(150 * (1 - ph) * strength)
            if a <= 3:
                continue
            r = (24 + ph * 54) * scale
            d.ellipse([xx - r, yy - r * 1.25, xx + r, yy + r * 1.25], fill=(255, 248, 236, a))

    soft(layer, paint, int(26 * scale))


def draw_whisk(layer, cx, cy, scale=1.0, angle=0.0):
    d = ImageDraw.Draw(layer)
    hx = cx + math.sin(angle) * 30 * scale
    # ручка
    shaded_poly(layer, [(hx - 12 * scale, cy - 250 * scale), (hx + 12 * scale, cy - 250 * scale),
                        (hx + 10 * scale, cy - 70 * scale), (hx - 10 * scale, cy - 70 * scale)],
                mix(STEEL, (255, 255, 255), 0.35), mix(STEEL, (40, 40, 44), 0.6))
    for i in range(5):
        off = (i - 2) * 24 * scale
        shade = 0.1 + 0.12 * abs(i - 1)
        d.arc([hx - 64 * scale + off * 0.5, cy - 74 * scale,
               hx + 64 * scale + off * 0.5, cy + 74 * scale],
              -18, 198, fill=mix(STEEL, (30, 30, 34), shade), width=max(2, int(7 * scale)))
    gloss(layer, [hx - 10 * scale, cy - 240 * scale, hx + 2 * scale, cy - 90 * scale],
          alpha=120, blur=6)


def draw_oven(layer, cx, cy, scale=1.0, glow=0.0, timer_text=None, d_font=None, t=0.0):
    d = ImageDraw.Draw(layer)
    w, h = 320 * scale, 300 * scale

    cast_shadow(layer, cx + 20 * scale, cy + h + 26 * scale, w * 1.05, alpha=150, blur=30)

    # корпус
    shaded_poly(layer, [(cx - w, cy - h), (cx + w, cy - h), (cx + w, cy + h), (cx - w, cy + h)],
                mix((86, 82, 72), (120, 114, 100), 0.4), (38, 35, 29))
    rounded(d, [cx - w, cy - h, cx + w, cy + h], int(36 * scale), fill=None,
            outline=mix((120, 114, 100), (0, 0, 0), 0.2), width=max(2, int(3 * scale)))

    # стекло
    gx0, gy0, gx1, gy1 = cx - w * 0.82, cy - h * 0.55, cx + w * 0.82, cy + h * 0.72
    shaded_poly(layer, [(gx0, gy0), (gx1, gy0), (gx1, gy1), (gx0, gy1)],
                mix((28, 23, 18), (176, 96, 44), glow),
                mix((18, 15, 12), (122, 62, 28), glow))

    # жар от нижнего тэна
    if glow > 0.05:
        def heat(dd):
            for i in range(5):
                rr = (w * 0.66) * (0.34 + i * 0.15)
                dd.ellipse([cx - rr, cy + h * 0.56 - rr * 0.4, cx + rr, cy + h * 0.56 + rr * 0.4],
                           fill=(250, 176, 96, int(38 * glow)))
        soft(layer, heat, 30)

    # решётка
    d.line([(cx - w * 0.68, cy + h * 0.5), (cx + w * 0.68, cy + h * 0.5)],
           fill=mix((60, 54, 46), (0, 0, 0), 0.3), width=max(2, int(6 * scale)))

    # форма внутри
    draw_ramekin(layer, cx, cy + h * 0.3, scale=0.6 * scale, fill_level=0.85,
                 warm=glow, shadow=False)

    # отражение на стекле — диагональная полоса
    def refl(dd):
        dd.polygon([(gx0 + w * 0.1, gy1), (gx0 + w * 0.6, gy0),
                    (gx0 + w * 0.95, gy0), (gx0 + w * 0.45, gy1)],
                   fill=(255, 250, 240, 26))
    soft(layer, refl, 18)

    # ручка
    shaded_poly(layer, [(cx - w * 0.78, cy - h * 0.86), (cx + w * 0.78, cy - h * 0.86),
                        (cx + w * 0.78, cy - h * 0.68), (cx - w * 0.78, cy - h * 0.68)],
                mix(STEEL, (255, 255, 255), 0.3), mix(STEEL, (36, 34, 30), 0.62))

    if timer_text and d_font:
        tw = d.textlength(timer_text, font=d_font)
        d.text((cx - tw / 2, cy - h * 0.46), timer_text, font=d_font, fill=CREAM)


def draw_fondant_cut(layer, cx, cy, scale=1.0, flow=0.0, steam_t=0.0):
    """Разрезанный фондан: пористый бисквит, глянцевая начинка, пудра."""
    d = ImageDraw.Draw(layer)
    r = 190 * scale

    cast_shadow(layer, cx + 24 * scale, cy + r * 0.86, r * 2.0, alpha=170, blur=34)

    # тарелка
    shaded_ellipse(layer, [cx - r * 2.1, cy + r * 0.2, cx + r * 2.1, cy + r * 1.06],
                   mix(CERAMIC, (255, 255, 252), 0.4), mix(CERAMIC, (70, 63, 52), 0.55))
    shaded_ellipse(layer, [cx - r * 1.72, cy + r * 0.3, cx + r * 1.72, cy + r * 0.94],
                   mix(CERAMIC, (255, 255, 250), 0.2), mix(CERAMIC, (96, 87, 72), 0.4))
    gloss(layer, [cx - r * 1.9, cy + r * 0.42, cx - r * 1.1, cy + r * 0.62], alpha=70, blur=14)

    # растёкшийся соус
    if flow > 0:
        sp = r * (0.55 + 1.2 * ease_out(flow))
        shaded_ellipse(layer, [cx - sp, cy + r * 0.42, cx + sp, cy + r * 0.42 + 70 * scale * (0.5 + flow)],
                       mix(CHOC, GOLD, 0.2), mix(CHOC_D, CHOC, 0.5))
        gloss(layer, [cx - sp * 0.6, cy + r * 0.5, cx - sp * 0.1, cy + r * 0.62], alpha=70, blur=10)

    # корпус
    shaded_poly(layer, [(cx - r, cy - r * 0.72), (cx + r, cy - r * 0.72),
                        (cx + r * 0.88, cy + r * 0.6), (cx - r * 0.88, cy + r * 0.6)],
                mix(CHOC_D, CHOC, 0.55), mix(CHOC_D, (0, 0, 0), 0.35))
    # верх
    shaded_ellipse(layer, [cx - r, cy - r * 0.88, cx + r, cy - r * 0.56],
                   mix(CHOC, CHOC_L, 0.35), mix(CHOC_D, CHOC, 0.5))

    # пористая крошка на срезе
    for i in range(70):
        a = (i * 2.399)
        xx = cx + math.cos(a) * r * 0.78 * ((i % 11) / 11)
        yy = cy - r * 0.5 + (i % 17) / 17 * r * 1.0
        rr = (1.6 + (i % 4)) * scale
        d.ellipse([xx - rr, yy - rr, xx + rr, yy + rr],
                  fill=mix(CHOC_D, CHOC_L, 0.25 + (i % 5) * 0.09))

    # срез
    soft(layer, lambda dd: dd.polygon(
        [(cx - 6, cy - r * 0.8), (cx + 14 * scale, cy - r * 0.78),
         (cx + 14 * scale, cy + r * 0.6), (cx - 6, cy + r * 0.6)],
        fill=(0, 0, 0, 120)), 6)

    # жидкий центр
    if flow > 0:
        cw = r * 0.46
        shaded_ellipse(layer, [cx - cw, cy - r * 0.22, cx + cw, cy + r * 0.26],
                       mix(CHOC_L, GOLD, 0.3), mix(CHOC, (0, 0, 0), 0.25))
        drip_h = r * 0.78 * ease_out(flow)
        shaded_poly(layer, [(cx - cw * 0.42, cy + r * 0.08), (cx + cw * 0.42, cy + r * 0.08),
                            (cx + cw * 0.17, cy + r * 0.08 + drip_h), (cx - cw * 0.17, cy + r * 0.08 + drip_h)],
                    mix(CHOC_L, GOLD, 0.22), mix(CHOC, (0, 0, 0), 0.2))
        shaded_ellipse(layer, [cx - cw * 0.22, cy + r * 0.08 + drip_h - 13 * scale,
                               cx + cw * 0.22, cy + r * 0.08 + drip_h + 13 * scale],
                       mix(CHOC_L, GOLD, 0.25), mix(CHOC, (0, 0, 0), 0.2))
        gloss(layer, [cx - cw * 0.6, cy - r * 0.16, cx - cw * 0.1, cy + r * 0.02], alpha=95, blur=8)

    # сахарная пудра
    for i in range(26):
        a = i * 1.37
        rad = r * (0.2 + 0.72 * ((i % 7) / 7))
        xx = cx + math.cos(a) * rad
        yy = cy - r * 0.74 + math.sin(a) * 18 * scale
        rr = (2.2 + (i % 3)) * scale
        d.ellipse([xx - rr, yy - rr, xx + rr, yy + rr], fill=(250, 247, 240, 220))

    draw_steam(layer, cx, cy - r * 0.9, steam_t, strength=0.5, spread=90, scale=0.8)


# ---------- постоянные элементы ----------
def draw_chrome(d, t, step_label):
    bar_y = H - 96
    d.rounded_rectangle([64, bar_y, W - 64, bar_y + 10], radius=5, fill=(70, 63, 52))
    w = (W - 128) * (t / DUR)
    if w > 10:
        d.rounded_rectangle([64, bar_y, 64 + w, bar_y + 10], radius=5, fill=BRICK)
    d.text((64, 72), BRAND, font=F_BOLD(30), fill=mix(CREAM, BG_BOT, 0.35))
    if step_label:
        tw = d.textlength(step_label, font=F_BOLD(30))
        d.text((W - 64 - tw, 72), step_label, font=F_BOLD(30), fill=mix(CREAM, BG_BOT, 0.45))


def hint_card(layer, y, title, value, accent=GREEN, alpha=1.0):
    if alpha <= 0.02:
        return
    card = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    cd = ImageDraw.Draw(card)
    a = int(232 * alpha)
    cd.rounded_rectangle([92, y, W - 92, y + 108], radius=26, fill=(30, 26, 20, a))
    cd.rounded_rectangle([92, y, W - 92, y + 108], radius=26, outline=(255, 255, 255, int(26 * alpha)), width=2)
    cd.text((132, y + 34), title, font=F_REG(42), fill=CREAM + (int(255 * alpha),))
    tw = cd.textlength(value, font=F_BOLD(44))
    cd.text((W - 132 - tw, y + 32), value, font=F_BOLD(44), fill=accent + (int(255 * alpha),))
    layer.alpha_composite(card)


# ---------- сцены ----------
def scene_title(layer, lt, dur, t):
    d = ImageDraw.Draw(layer)
    a = ease(lt / 0.6)
    out = 1.0 if lt < dur - 0.4 else 1 - ease((lt - (dur - 0.4)) / 0.4)
    al = min(a, out)
    rise = (1 - ease_out(lt / 0.9)) * 50

    draw_ramekin(layer, W / 2, 980 + rise * 0.4, scale=1.5, fill_level=0.9, warm=0.08)
    draw_steam(layer, W / 2, 830, t, strength=0.35 * al, spread=120)

    center_text(d, 1250 + rise, "ШОКОЛАДНЫЙ", F_BLACK(96), fade(CREAM, al), spacing=2)
    center_text(d, 1360 + rise, "ФОНДАН", F_BLACK(96), fade(BRICK, al), spacing=2)
    center_text(d, 1500 + rise, "жидкий центр · 25 минут · 2 порции", F_REG(40), fade(MUTED, al))
    if lt > 1.2:
        b = ease((lt - 1.2) / 0.5)
        center_text(d, 1610, "СОХРАНИТЕ РЕЦЕПТ", F_BOLD(32), fade(GREEN, b * al), spacing=4)


ING = [
    ("Тёмный шоколад 70%", 100, "г"),
    ("Сливочное масло", 80, "г"),
    ("Яйцо", 2, "шт"),
    ("Сахар", 60, "г"),
    ("Мука", 30, "г"),
    ("Соль", 1, "щепотка"),
]


def scene_ingredients(layer, lt, dur, t):
    d = ImageDraw.Draw(layer)
    center_text(d, 300, "ИНГРЕДИЕНТЫ НА 2 ПОРЦИИ", F_BOLD(36), mix(CREAM, BG_BOT, 0.4), spacing=3)
    draw_bowl(layer, W / 2, 1480, scale=0.82, content=mix(CHOC_D, CHOC, 0.5),
              content_dark=mix(CHOC_D, (0, 0, 0), 0.4), swirl=lt)

    for i, (name, amount, unit) in enumerate(ING):
        start = 0.25 + i * 0.42
        if lt < start:
            continue
        a = ease((lt - start) / 0.45)
        shown = amount if a >= 1 else max(1, int(round(amount * ease_out((lt - start) / 0.6))))
        hint_card(layer, 400 + i * 130, name, f"{shown} {unit}",
                  GREEN if unit == "г" else BRICK, a)


def scene_melt(layer, lt, dur, t):
    d = ImageDraw.Draw(layer)
    center_text(d, 300, "ШАГ 1 · РАСТОПИТЬ", F_BOLD(36), mix(CREAM, BG_BOT, 0.4), spacing=3)

    melt = ease(lt / (dur * 0.75))
    draw_bowl(layer, W / 2, 940, scale=1.18,
              content=mix(CHOC_D, mix(CHOC, CHOC_L, 0.45), melt),
              content_dark=mix(CHOC_D, (0, 0, 0), 0.4), swirl=lt * 1.4)
    draw_steam(layer, W / 2, 870, t, strength=0.8 * melt, spread=140)

    center_text(d, 1250, "Шоколад и масло — на водяную баню.", F_REG(44), mix(CREAM, BG_BOT, 0.1))
    center_text(d, 1316, "Мешайте до гладкости, не кипятите.", F_REG(44), mix(CREAM, BG_BOT, 0.35))
    hint_card(layer, 1420, "Температура", "не выше 50 °C", BRICK, ease((lt - 0.6) / 0.5))
    hint_card(layer, 1550, "Время", "4 минуты", GREEN, ease((lt - 1.0) / 0.5))


def scene_whip(layer, lt, dur, t):
    d = ImageDraw.Draw(layer)
    center_text(d, 300, "ШАГ 2 · ВЗБИТЬ", F_BOLD(36), mix(CREAM, BG_BOT, 0.4), spacing=3)

    lift = abs(math.sin(lt * 7)) * 14
    draw_bowl(layer, W / 2, 960, scale=1.18, content=mix(GOLD, (255, 248, 232), 0.55),
              content_dark=mix(GOLD, (90, 60, 20), 0.35), swirl=lt * 3.2)
    draw_whisk(layer, W / 2, 880 - lift, scale=1.15, angle=math.sin(lt * 14) * 0.5)

    center_text(d, 1250, "Яйца с сахаром — добела.", F_REG(44), mix(CREAM, BG_BOT, 0.1))
    center_text(d, 1316, "Масса светлеет и густеет.", F_REG(44), mix(CREAM, BG_BOT, 0.35))
    hint_card(layer, 1420, "Яйца + сахар", "2 шт + 60 г", GREEN, ease(lt / 0.5))
    hint_card(layer, 1550, "Взбивать", "3 минуты", BRICK, ease((lt - 0.5) / 0.5))


def scene_fold(layer, lt, dur, t):
    d = ImageDraw.Draw(layer)
    center_text(d, 300, "ШАГ 3 · СОЕДИНИТЬ", F_BOLD(36), mix(CREAM, BG_BOT, 0.4), spacing=3)

    blend = ease(lt / (dur * 0.7))
    draw_bowl(layer, W / 2, 960, scale=1.18,
              content=mix(mix(GOLD, (255, 248, 232), 0.5), mix(CHOC_D, CHOC, 0.6), blend),
              content_dark=mix(CHOC_D, (0, 0, 0), 0.35), swirl=lt * 2.0)

    # просеянная мука
    def flour(dd):
        for i in range(14):
            ph = (lt * 0.85 + i * 0.07) % 1.0
            yy = 660 + ph * 250
            xx = W / 2 + math.sin(i * 2.1) * 190
            if ph < 0.88:
                rr = 5 + (i % 3)
                dd.ellipse([xx - rr, yy - rr, xx + rr, yy + rr],
                           fill=(252, 248, 238, int(190 * (1 - ph))))
    soft(layer, flour, 4)

    center_text(d, 1250, "Влейте шоколад в яичную массу,", F_REG(44), mix(CREAM, BG_BOT, 0.1))
    center_text(d, 1316, "просейте муку и вмешайте лопаткой.", F_REG(44), mix(CREAM, BG_BOT, 0.35))
    hint_card(layer, 1420, "Мука, просеять", "30 г", GREEN, ease(lt / 0.5))
    hint_card(layer, 1550, "Мешать", "снизу вверх", BRICK, ease((lt - 0.5) / 0.5))


def scene_bake(layer, lt, dur, t):
    d = ImageDraw.Draw(layer)
    center_text(d, 300, "ШАГ 4 · ВЫПЕКАТЬ", F_BOLD(36), mix(CREAM, BG_BOT, 0.4), spacing=3)

    glow = ease(lt / 0.8)
    left = max(0, 9 - int(lt / (dur / 9.5)))
    draw_oven(layer, W / 2, 840, scale=1.1, glow=glow,
              timer_text=f"{left}:00", d_font=F_BLACK(96), t=t)

    center_text(d, 1250, "Разогретая духовка, верх-низ.", F_REG(44), mix(CREAM, BG_BOT, 0.1))
    center_text(d, 1316, "Края схватились, центр дрожит — готово.", F_REG(40), mix(CREAM, BG_BOT, 0.35))
    hint_card(layer, 1420, "Температура", "200 °C", BRICK, ease(lt / 0.4))
    hint_card(layer, 1550, "Время", "9 минут, не дольше", GREEN, ease((lt - 0.4) / 0.5))


def scene_final(layer, lt, dur, t):
    d = ImageDraw.Draw(layer)
    flow = ease_out(lt / 1.5)
    draw_fondant_cut(layer, W / 2, 930, scale=1.05, flow=flow, steam_t=t)

    a = ease((lt - 0.2) / 0.5)
    center_text(d, 1330, "ГОТОВО", F_BLACK(100), fade(BRICK, a), spacing=3)
    center_text(d, 1470, "Подавайте сразу: центр густеет", F_REG(42), fade(CREAM, a))
    center_text(d, 1526, "за пару минут.", F_REG(42), fade(CREAM, a))

    b = ease((lt - 1.0) / 0.6)
    if b > 0.02:
        hint_card(layer, 1620, "Полный рецепт", "на сайте", GREEN, b)


SCENES = [
    (3.4, scene_title, ""),
    (4.2, scene_ingredients, "1/5"),
    (2.9, scene_melt, "2/5"),
    (2.6, scene_whip, "3/5"),
    (2.5, scene_fold, "4/5"),
    (2.6, scene_bake, "5/5"),
    (1.8, scene_final, ""),
]


def main():
    os.makedirs(OUT, exist_ok=True)
    base = build_background()
    grain = build_grain()

    bounds, acc = [], 0.0
    for dur, fn, label in SCENES:
        bounds.append((acc, acc + dur, fn, dur, label))
        acc += dur
    assert abs(acc - DUR) < 0.3, f"длительность сцен {acc}, ожидалось {DUR}"

    for i in range(TOTAL):
        t = i / FPS
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
        draw_chrome(ImageDraw.Draw(frame), t, label)
        frame.convert("RGB").save(f"{OUT}/f{i:04d}.png")

    subprocess.run([
        "ffmpeg", "-y", "-loglevel", "error",
        "-framerate", str(FPS), "-i", f"{OUT}/f%04d.png",
        "-c:v", "libx264", "-pix_fmt", "yuv420p", "-crf", "22",
        "-movflags", "+faststart",
        os.path.join(REEL_DIR, "fondan-reels.mp4")
    ], check=True)
    print("готово")


if __name__ == "__main__":
    main()
