"""
Рилс «Паста с креветками» — 20 секунд, 1080x1920.

Движок (фон, материалы, тени, подсказки) переиспользуется из make_reel.py:
новый рецепт — это новый файл со сценами, а не копия рендера.
"""
import math
import os
import subprocess

from PIL import Image, ImageDraw

import make_reel as E

W, H, FPS, DUR = E.W, E.H, E.FPS, E.DUR
TOTAL = int(DUR * FPS)
OUT = os.path.join(E.REEL_DIR, "frames_pasta")

PAN = (58, 58, 64)
PAN_HI = (112, 112, 120)
SAUCE = (176, 62, 38)
SAUCE_D = (92, 30, 18)
CREAM_S = (226, 198, 160)
SHRIMP = (214, 122, 78)
SHRIMP_HI = (242, 168, 122)
NOODLE = (226, 186, 112)
BASIL = (84, 124, 74)
GARLIC = (238, 230, 210)

CREAM = E.CREAM
MUTED = E.MUTED
GREEN = E.GREEN
BRICK = E.BRICK
BG_BOT = E.BG_BOT


# ---------- предметы ----------
def draw_skillet(layer, cx, cy, scale=1.0, sauce=None, sauce_dark=None, fill=0.0, swirl=0.0):
    """Сковорода сверху-сбоку: корпус, ручка, содержимое."""
    d = ImageDraw.Draw(layer)
    rx, ry = 300 * scale, 205 * scale

    E.cast_shadow(layer, cx + 26 * scale, cy + ry * 0.92, rx * 1.02,
                  ry=ry * 0.3, alpha=165, blur=int(30 * scale))

    # ручка уходит вправо-вверх
    E.shaded_poly(layer, [(cx + rx * 0.86, cy - ry * 0.26), (cx + rx * 1.62, cy - ry * 0.74),
                          (cx + rx * 1.70, cy - ry * 0.56), (cx + rx * 0.92, cy - ry * 0.04)],
                  E.mix(PAN_HI, (255, 255, 255), 0.2), (26, 26, 30))

    # корпус
    E.shaded_ellipse(layer, [cx - rx, cy - ry, cx + rx, cy + ry],
                     E.mix(PAN_HI, (255, 255, 255), 0.25), (22, 22, 26))
    # внутренняя чаша
    E.shaded_ellipse(layer, [cx - rx * 0.90, cy - ry * 0.88, cx + rx * 0.90, cy + ry * 0.88],
                     E.mix(PAN, (0, 0, 0), 0.35), E.mix(PAN, (0, 0, 0), 0.72))

    if sauce and fill > 0:
        f = 0.52 + 0.33 * fill
        E.shaded_ellipse(layer, [cx - rx * f, cy - ry * f, cx + rx * f, cy + ry * f],
                         sauce, sauce_dark or E.mix(sauce, (0, 0, 0), 0.5))
        # блик на поверхности соуса
        E.gloss(layer, [cx - rx * f * 0.56, cy - ry * f * 0.56,
                        cx - rx * f * 0.08, cy - ry * f * 0.2], alpha=70, blur=14)
        if swirl > 0:
            for i in range(6):
                a = swirl * 3.6 + i * 1.05
                rr = rx * f * (0.26 + 0.1 * i)
                xx = cx + math.cos(a) * rr
                yy = cy + math.sin(a) * rr * 0.68
                d.ellipse([xx - 26 * scale, yy - 15 * scale, xx + 26 * scale, yy + 15 * scale],
                          fill=E.mix(sauce, (255, 255, 255), 0.13))

    # блик по ободку
    E.gloss(layer, [cx - rx * 0.92, cy - ry * 1.02, cx - rx * 0.1, cy - ry * 0.72],
            alpha=110, blur=12)


def draw_shrimp(layer, cx, cy, scale=1.0, rot=0.0, cooked=1.0):
    """Креветка: запятая из сегментов, хвост, блик."""
    col = E.mix((196, 150, 128), SHRIMP, cooked)
    hi = E.mix((224, 196, 176), SHRIMP_HI, cooked)
    pts = []
    for i in range(9):
        a = rot + i * 0.36
        r = (54 - i * 3.4) * scale
        pts.append((cx + math.cos(a) * r * 1.5, cy + math.sin(a) * r * 1.2))
    d = ImageDraw.Draw(layer)
    for i, (x, y) in enumerate(pts):
        rr = (23 - i * 1.7) * scale
        d.ellipse([x - rr, y - rr, x + rr, y + rr], fill=E.mix(col, (0, 0, 0), 0.06 * (i % 2)))
    # хвост
    tx, ty = pts[-1]
    d.polygon([(tx, ty), (tx + 30 * scale * math.cos(rot + 3.4), ty + 26 * scale * math.sin(rot + 3.4)),
               (tx + 34 * scale * math.cos(rot + 4.0), ty + 30 * scale * math.sin(rot + 4.0))],
              fill=E.mix(col, (255, 255, 255), 0.2))
    E.gloss(layer, [pts[1][0] - 18 * scale, pts[1][1] - 20 * scale,
                    pts[3][0] + 10 * scale, pts[3][1] - 4 * scale], alpha=int(90 * cooked), blur=7)


def draw_pasta_nest(layer, cx, cy, scale=1.0, gloss_on=True):
    """Гнездо пасты из дуг."""
    d = ImageDraw.Draw(layer)
    for i in range(14):
        a = i * 0.45
        rr = (120 - i * 4) * scale
        box = [cx - rr * 1.5, cy - rr * 0.7, cx + rr * 1.5, cy + rr * 0.7]
        d.arc(box, int(math.degrees(a)) % 360, (int(math.degrees(a)) + 210) % 360,
              fill=E.mix(NOODLE, (0, 0, 0), 0.06 * (i % 3)), width=max(3, int(11 * scale)))
    if gloss_on:
        E.gloss(layer, [cx - 110 * scale, cy - 56 * scale, cx - 10 * scale, cy - 10 * scale],
                alpha=60, blur=12)


def draw_plate(layer, cx, cy, scale=1.0):
    E.cast_shadow(layer, cx + 24 * scale, cy + 92 * scale, 300 * scale,
                  ry=70 * scale, alpha=170, blur=34)
    E.shaded_ellipse(layer, [cx - 320 * scale, cy - 92 * scale, cx + 320 * scale, cy + 112 * scale],
                     E.mix(E.CERAMIC, (255, 255, 252), 0.4), E.mix(E.CERAMIC, (70, 63, 52), 0.55))
    E.shaded_ellipse(layer, [cx - 250 * scale, cy - 68 * scale, cx + 250 * scale, cy + 86 * scale],
                     E.mix(E.CERAMIC, (255, 255, 250), 0.18), E.mix(E.CERAMIC, (96, 87, 72), 0.42))
    E.gloss(layer, [cx - 290 * scale, cy - 40 * scale, cx - 170 * scale, cy + 10 * scale],
            alpha=80, blur=14)


def draw_basil(layer, cx, cy, scale=1.0, rot=0.0):
    d = ImageDraw.Draw(layer)
    w, h = 34 * scale, 17 * scale
    pts = []
    for i in range(14):
        a = i / 13 * math.pi * 2
        pts.append((cx + math.cos(a) * w * math.cos(rot) - math.sin(a) * h * math.sin(rot),
                    cy + math.cos(a) * w * math.sin(rot) + math.sin(a) * h * math.cos(rot)))
    d.polygon(pts, fill=BASIL)
    d.line([(cx - w * math.cos(rot), cy - w * math.sin(rot)),
            (cx + w * math.cos(rot), cy + w * math.sin(rot))],
           fill=E.mix(BASIL, (255, 255, 255), 0.35), width=max(1, int(2 * scale)))


# ---------- сцены ----------
ING = [
    ("Спагетти", 180, "г"),
    ("Креветки очищенные", 240, "г"),
    ("Томаты в соку", 200, "г"),
    ("Сливки 20%", 100, "мл"),
    ("Чеснок", 2, "зубчика"),
    ("Базилик", 6, "листьев"),
]


def scene_title(layer, lt, dur, t):
    d = ImageDraw.Draw(layer)
    a = E.ease(lt / 0.6)
    out = 1.0 if lt < dur - 0.4 else 1 - E.ease((lt - (dur - 0.4)) / 0.4)
    al = min(a, out)
    rise = (1 - E.ease_out(lt / 0.9)) * 50

    draw_skillet(layer, W / 2, 870 + rise * 0.4, scale=1.0, sauce=SAUCE,
                 sauce_dark=SAUCE_D, fill=1.0, swirl=lt * 0.8)
    draw_pasta_nest(layer, W / 2, 880 + rise * 0.4, scale=1.0)
    draw_shrimp(layer, W / 2 - 110, 830 + rise * 0.4, scale=0.8, rot=0.6)
    draw_shrimp(layer, W / 2 + 120, 930 + rise * 0.4, scale=0.8, rot=2.8)
    E.draw_steam(layer, W / 2, 700, t, strength=0.4 * al, spread=150)

    E.center_text(d, 1230 + rise, "ПАСТА С", E.F_BLACK(92), E.fade(CREAM, al), spacing=2)
    E.center_text(d, 1335 + rise, "КРЕВЕТКАМИ", E.F_BLACK(92), E.fade(BRICK, al), spacing=2)
    E.center_text(d, 1475 + rise, "одна сковорода · 25 минут · 2 порции",
                  E.F_REG(38), E.fade(MUTED, al))
    if lt > 1.2:
        b = E.ease((lt - 1.2) / 0.5)
        E.center_text(d, 1585, "СОХРАНИТЕ РЕЦЕПТ", E.F_BOLD(32), E.fade(GREEN, b * al), spacing=4)


def scene_ingredients(layer, lt, dur, t):
    d = ImageDraw.Draw(layer)
    E.center_text(d, 300, "ИНГРЕДИЕНТЫ НА 2 ПОРЦИИ", E.F_BOLD(36),
                  E.mix(CREAM, BG_BOT, 0.4), spacing=3)
    draw_skillet(layer, W / 2, 1480, scale=0.62, sauce=SAUCE, sauce_dark=SAUCE_D,
                 fill=0.9, swirl=lt)

    for i, (name, amount, unit) in enumerate(ING):
        start = 0.25 + i * 0.42
        if lt < start:
            continue
        a = E.ease((lt - start) / 0.45)
        shown = amount if a >= 1 else max(1, int(round(amount * E.ease_out((lt - start) / 0.6))))
        E.hint_card(layer, 400 + i * 130, name, f"{shown} {unit}",
                    GREEN if unit in ("г", "мл") else BRICK, a)


def scene_garlic(layer, lt, dur, t):
    d = ImageDraw.Draw(layer)
    E.center_text(d, 300, "ШАГ 1 · ЧЕСНОК В МАСЛЕ", E.F_BOLD(36),
                  E.mix(CREAM, BG_BOT, 0.4), spacing=3)

    heat = E.ease(lt / (dur * 0.7))
    draw_skillet(layer, W / 2, 880, scale=1.0,
                 sauce=E.mix((196, 160, 92), (214, 180, 104), heat),
                 sauce_dark=E.mix((120, 92, 44), (96, 72, 32), heat), fill=0.35, swirl=lt * 1.2)

    # пластины чеснока подрагивают в масле
    for i in range(6):
        a = i * 1.05 + math.sin(lt * 5 + i) * 0.12
        rr = 110 + (i % 3) * 36
        xx = W / 2 + math.cos(a) * rr
        yy = 880 + math.sin(a) * rr * 0.62
        d.ellipse([xx - 22, yy - 13, xx + 22, yy + 13],
                  fill=E.mix(GARLIC, (190, 150, 90), heat * 0.35))
        d.ellipse([xx - 9, yy - 6, xx + 5, yy + 2], fill=(255, 252, 244))

    E.draw_steam(layer, W / 2, 760, t, strength=0.45 * heat, spread=130)

    E.center_text(d, 1240, "Пластины чеснока — в тёплое масло.", E.F_REG(42),
                  E.mix(CREAM, BG_BOT, 0.1))
    E.center_text(d, 1304, "До аромата, не до золотистого цвета.", E.F_REG(42),
                  E.mix(CREAM, BG_BOT, 0.35))
    E.hint_card(layer, 1420, "Чеснок", "2 зубчика", GREEN, E.ease(lt / 0.5))
    E.hint_card(layer, 1550, "Время", "1 минута", BRICK, E.ease((lt - 0.5) / 0.5))


def scene_tomato(layer, lt, dur, t):
    d = ImageDraw.Draw(layer)
    E.center_text(d, 300, "ШАГ 2 · ТОМАТЫ", E.F_BOLD(36), E.mix(CREAM, BG_BOT, 0.4), spacing=3)

    thick = E.ease(lt / (dur * 0.8))
    draw_skillet(layer, W / 2, 880, scale=1.0,
                 sauce=E.mix((206, 92, 56), SAUCE, thick),
                 sauce_dark=E.mix((132, 48, 28), SAUCE_D, thick),
                 fill=0.55 + 0.2 * thick, swirl=lt * 1.6)
    E.draw_steam(layer, W / 2, 740, t, strength=0.5, spread=140)

    E.center_text(d, 1240, "Томаты и хлопья чили — в сковороду.", E.F_REG(42),
                  E.mix(CREAM, BG_BOT, 0.1))
    E.center_text(d, 1304, "Тушите, пока соус не потемнеет.", E.F_REG(42),
                  E.mix(CREAM, BG_BOT, 0.35))
    E.hint_card(layer, 1420, "Томаты", "200 г", GREEN, E.ease(lt / 0.5))
    E.hint_card(layer, 1550, "Тушить", "6 минут", BRICK, E.ease((lt - 0.5) / 0.5))


def scene_cream(layer, lt, dur, t):
    d = ImageDraw.Draw(layer)
    E.center_text(d, 300, "ШАГ 3 · СЛИВКИ", E.F_BOLD(36), E.mix(CREAM, BG_BOT, 0.4), spacing=3)

    blend = E.ease(lt / (dur * 0.75))
    draw_skillet(layer, W / 2, 880, scale=1.0,
                 sauce=E.mix(SAUCE, CREAM_S, blend * 0.45),
                 sauce_dark=E.mix(SAUCE_D, (150, 110, 70), blend * 0.4),
                 fill=0.78, swirl=lt * 2.2)

    # струйка сливок
    if lt < dur * 0.6:
        def pour(dd):
            for i in range(10):
                yy = 640 + i * 26 + (lt * 260) % 26
                dd.ellipse([W / 2 - 16, yy - 13, W / 2 + 16, yy + 13],
                           fill=(246, 238, 220, 220))
        E.soft(layer, pour, 5)

    E.center_text(d, 1240, "Влейте сливки и прогрейте.", E.F_REG(42), E.mix(CREAM, BG_BOT, 0.1))
    E.center_text(d, 1304, "До кипения — и сразу убавьте огонь.", E.F_REG(42),
                  E.mix(CREAM, BG_BOT, 0.35))
    E.hint_card(layer, 1420, "Сливки 20%", "100 мл", GREEN, E.ease(lt / 0.5))
    E.hint_card(layer, 1550, "Важно", "долго не кипятить", BRICK, E.ease((lt - 0.5) / 0.5))


def scene_shrimp(layer, lt, dur, t):
    d = ImageDraw.Draw(layer)
    E.center_text(d, 300, "ШАГ 4 · КРЕВЕТКИ", E.F_BOLD(36), E.mix(CREAM, BG_BOT, 0.4), spacing=3)

    cooked = E.ease(lt / (dur * 0.8))
    draw_skillet(layer, W / 2, 880, scale=1.0, sauce=E.mix(SAUCE, CREAM_S, 0.4),
                 sauce_dark=E.mix(SAUCE_D, (150, 110, 70), 0.35), fill=0.78, swirl=lt * 1.4)

    # креветки опускаются в соус и розовеют
    drop = (1 - E.ease_out(min(1.0, lt / 0.7))) * 220
    for i, (dx, dy, rot) in enumerate(((-150, -40, 0.4), (10, 40, 2.4), (160, -20, 4.1))):
        draw_shrimp(layer, W / 2 + dx, 880 + dy - drop * (1 - i * 0.2),
                    scale=0.9, rot=rot + math.sin(lt * 3 + i) * 0.1, cooked=cooked)

    E.draw_steam(layer, W / 2, 730, t, strength=0.5, spread=150)

    E.center_text(d, 1240, "Креветки — последними в соус.", E.F_REG(42), E.mix(CREAM, BG_BOT, 0.1))
    E.center_text(d, 1304, "Стали плотными и розовыми — готово.", E.F_REG(42),
                  E.mix(CREAM, BG_BOT, 0.35))
    E.hint_card(layer, 1420, "Креветки", "240 г", GREEN, E.ease(lt / 0.4))
    E.hint_card(layer, 1550, "Время", "2 минуты, не больше", BRICK, E.ease((lt - 0.4) / 0.5))


def scene_final(layer, lt, dur, t):
    d = ImageDraw.Draw(layer)
    sc = 1.0 + 0.05 * E.ease(lt / 1.2)
    draw_plate(layer, W / 2, 900, scale=sc)
    draw_pasta_nest(layer, W / 2, 880, scale=1.05 * sc)
    draw_shrimp(layer, W / 2 - 120, 840, scale=0.85 * sc, rot=0.5)
    draw_shrimp(layer, W / 2 + 110, 910, scale=0.85 * sc, rot=2.7)
    draw_basil(layer, W / 2 - 20, 800, scale=sc, rot=-0.3)
    draw_basil(layer, W / 2 + 60, 960, scale=0.9 * sc, rot=0.5)
    E.draw_steam(layer, W / 2, 760, t, strength=0.45, spread=120, scale=0.9)

    a = E.ease((lt - 0.2) / 0.5)
    E.center_text(d, 1300, "ГОТОВО", E.F_BLACK(100), E.fade(BRICK, a), spacing=3)
    E.center_text(d, 1440, "Базилик — в самом конце,", E.F_REG(42), E.fade(CREAM, a))
    E.center_text(d, 1496, "уже без огня.", E.F_REG(42), E.fade(CREAM, a))

    b = E.ease((lt - 1.0) / 0.6)
    if b > 0.02:
        E.hint_card(layer, 1600, "Полный рецепт", "на сайте", GREEN, b)


SCENES = [
    (3.4, scene_title, ""),
    (4.2, scene_ingredients, "1/5"),
    (2.9, scene_garlic, "2/5"),
    (2.6, scene_tomato, "3/5"),
    (2.5, scene_cream, "4/5"),
    (2.6, scene_shrimp, "5/5"),
    (1.8, scene_final, ""),
]


def main():
    os.makedirs(OUT, exist_ok=True)
    base = E.build_background()
    grain = E.build_grain()

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
        E.draw_chrome(ImageDraw.Draw(frame), t, label)
        frame.convert("RGB").save(f"{OUT}/f{i:04d}.png")

    subprocess.run([
        "ffmpeg", "-y", "-loglevel", "error",
        "-framerate", str(FPS), "-i", f"{OUT}/f%04d.png",
        "-c:v", "libx264", "-pix_fmt", "yuv420p", "-crf", "22",
        "-movflags", "+faststart",
        os.path.join(E.REEL_DIR, "pasta-reels.mp4")
    ], check=True)
    print("готово")


if __name__ == "__main__":
    main()
