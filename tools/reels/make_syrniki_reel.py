"""
Рилс «Сырники на рисовой муке» — 20 секунд, 1080x1920.
Данные из site/index.html (slug syrniki-risovaya-muka); граммовки — на 3 порции.
"""
import math
import random

from PIL import ImageDraw

import make_reel as E
import reel_kit as K
import make_pasta_reel as P

W = E.W
SLUG = "syrniki-risovaya-muka"
GREEN, BRICK = E.GREEN, E.BRICK
CURD = (244, 238, 222)
CURD_D = (170, 160, 138)
GOLD_T = (206, 140, 58)
RAW_T = (240, 232, 214)

ING = [
    ("Творог 9%", 450, "г"),
    ("Рисовая мука", 75, "г"),
    ("Яйцо", 1, "шт"),
    ("Сахар", 3, "ч. л."),
    ("Ванильный сахар", 0.75, "ч. л."),
    ("Масло для жарки", 1.5, "ст. л."),
]


def syrnik(layer, cx, cy, s=1.0, gold=0.0, flour=0.0):
    top = E.mix(RAW_T, GOLD_T, gold)
    hi = E.mix((232, 222, 198), (222, 160, 76), gold)
    lo = E.mix((176, 164, 140), (120, 70, 24), gold)
    K.disc(layer, cx, cy, 120 * s, 40 * s, top, hi, lo)
    E.gloss(layer, [cx - 80 * s, cy - 30 * s, cx - 10 * s, cy - 8 * s], alpha=int(70 + 40 * gold), blur=7)
    if flour > 0:
        K.powder(layer, cx, cy, 90 * s, 28 * s, n=int(30 * flour), seed=int(cx), col=(252, 250, 244))


def hero(layer, lt, rise, t, al):
    K.plate(layer, W / 2, 900 + rise)
    syrnik(layer, W / 2 - 40, 900 + rise + 20, 1.0, 1.0)
    syrnik(layer, W / 2 + 20, 900 + rise - 20, 1.0, 1.0)
    syrnik(layer, W / 2 - 10, 900 + rise - 62, 1.0, 0.9)
    K.powder(layer, W / 2 - 10, 900 + rise - 62, 90, 28, n=26, seed=3)
    E.draw_steam(layer, W / 2, 740 + rise, t, strength=0.4 * al, spread=130)


def dish_ing(layer, lt, t):
    E.draw_bowl(layer, W / 2, 1480, scale=0.62, content=CURD, content_dark=CURD_D, swirl=lt)


def s_sieve(layer, lt, dur, t, p):
    cx, by = W / 2, 1000
    E.draw_bowl(layer, cx, by, scale=1.0, content=CURD, content_dark=CURD_D, swirl=0.0)
    d = ImageDraw.Draw(layer)
    sy = 620  # сито над миской
    # ручка
    d.line([(cx + 190, sy + 20), (cx + 400, sy - 80)], fill=(150, 150, 156), width=12)
    # корзина сита
    E.soft(layer, lambda dd: dd.polygon([(cx - 210, sy), (cx + 210, sy), (cx + 140, sy + 120), (cx - 140, sy + 120)],
                                        fill=(200, 205, 210, 70)), 1.5)
    for k in range(-8, 9):
        d.line([(cx + k * 24, sy), (cx + k * 16, sy + 120)], fill=(180, 184, 190, 150), width=2)
    for yy in (30, 62, 94):
        w = 210 - yy * 0.58
        d.line([(cx - w, sy + yy), (cx + w, sy + yy)], fill=(180, 184, 190, 150), width=2)
    # творог в сите — убывает
    mound = 1 - p * 0.8
    E.shaded_ellipse(layer, [cx - 170, sy - 50 * mound - 10, cx + 170, sy + 18], CURD, CURD_D)
    # струйки сквозь сито
    def strands(dd):
        for i in range(9):
            ph = (t * 0.9 + i * 0.23) % 1.0
            xx = cx - 100 + i * 25
            y0 = sy + 118 + ph * 150
            dd.line([(xx, y0), (xx, y0 + 34)], fill=(248, 244, 232, int(220 * (1 - ph))), width=6)
    E.soft(layer, strands, 1.5)
    E.rounded(d, [cx - 214, sy - 8, cx + 214, sy + 8], 8, fill=(190, 192, 198))


def s_mix(layer, lt, dur, t, p):
    mix = E.ease(lt / (dur * 0.7))
    cx, by = W / 2 - 60, 960
    E.draw_bowl(layer, cx, by, scale=0.9, content=E.mix(CURD, (246, 236, 200), mix), content_dark=CURD_D,
                swirl=lt * 2.2)
    d = ImageDraw.Draw(layer)
    if lt < dur * 0.6:
        K.falling(layer, t, cx - 120, cx + 120, 640, 900, (250, 248, 242), n=14, size=6)
    # таймер выдержки
    K.timer_ring(layer, 880, 760, 105, E.ease(lt / dur), "10 мин" if False else "10:00")


def s_shape(layer, lt, dur, t, p):
    cx = W / 2
    E.shaded_ellipse(layer, [cx - 390, 760, cx + 390, 1130], (178, 140, 96), (112, 80, 48))
    E.gloss(layer, [cx - 330, 800, cx - 100, 860], alpha=50, blur=14)
    K.powder(layer, cx, 960, 330, 120, n=70, seed=9)
    for i, (dx, dy) in enumerate(((-170, 0), (170, 0), (0, 120))):
        q = E.ease((lt - 0.1 - i * 0.45) / 0.5)
        if q <= 0:
            continue
        sc = 0.55 + 0.45 * q
        syrnik(layer, cx + dx, 880 + dy, sc, 0.0, flour=1.0)
    # размер: скобка толщины справа от первой шайбы
    d = ImageDraw.Draw(layer)
    if lt > 0.6:
        x = cx + 170 + 135
        d.line([(x, 880), (x, 920)], fill=GREEN, width=4)
        d.line([(x - 8, 880), (x + 8, 880)], fill=GREEN, width=4)
        d.line([(x - 8, 920), (x + 8, 920)], fill=GREEN, width=4)
        d.text((x + 16, 884), "≈ 2 см", font=E.F_BOLD(30), fill=GREEN)


def s_fry(layer, lt, dur, t, p):
    P.draw_skillet(layer, W / 2, 890, 1.0, sauce=(150, 104, 40), sauce_dark=(90, 56, 20), fill=0.95)
    for dx, dy in ((-100, -30), (110, -25), (0, 70)):
        syrnik(layer, W / 2 + dx, 880 + dy, 0.62, gold=p)
    if lt > dur * 0.35:
        K.glass_lid(layer, W / 2, 890, 290, 200, lift=(1 - E.ease_out((lt - dur * 0.35) / 0.5)) * 160)
    E.draw_steam(layer, W / 2, 730, t, strength=0.55, spread=150)


def dish_final(layer, lt, t):
    K.plate(layer, W / 2, 900)
    syrnik(layer, W / 2 - 45, 925, 1.0, 1.0)
    syrnik(layer, W / 2 + 30, 880, 1.0, 1.0)
    syrnik(layer, W / 2 - 10, 840, 1.0, 0.9)
    K.powder(layer, W / 2 - 10, 840, 90, 28, n=30, seed=5)
    d = ImageDraw.Draw(layer)
    for dx, dy in ((100, 940), (130, 925), (72, 948)):  # ягоды
        d.ellipse([W / 2 + dx - 16, dy - 16, W / 2 + dx + 16, dy + 16], fill=(180, 36, 56))
        d.ellipse([W / 2 + dx - 9, dy - 11, W / 2 + dx - 1, dy - 5], fill=(240, 150, 160))
    E.draw_steam(layer, W / 2, 720, t, strength=0.4, spread=130)


SCENES = [
    (3.4, K.make_title(hero, "СЫРНИКИ", "РИСОВАЯ МУКА",
                       "без глютена · 20 минут · 3 порции", ("Секрет", "протереть творог")), ""),
    (4.2, K.make_ingredients("3 ПОРЦИИ", ING, dish_ing), "1/5"),
    (2.9, K.make_step("ШАГ 1 · ПРОТЕРЕТЬ ТВОРОГ",
                      ("Творог — через сито.", "Единственный шаг, который нельзя пропускать."),
                      [("Творог 9%", "450 г", GREEN), ("Сито", "обязательно", BRICK)], s_sieve), "2/5"),
    (2.6, K.make_step("ШАГ 2 · ЗАМЕСИТЬ",
                      ("Творог, яйцо, сахар и ваниль, затем мука.", "Дайте тесту постоять."),
                      [("Рисовая мука", "75 г", GREEN), ("Тесту отдохнуть", "10 минут", BRICK)], s_mix), "3/5"),
    (2.5, K.make_step("ШАГ 3 · СФОРМИРОВАТЬ",
                      ("Шайбы толщиной около 2 см.", "Каждую обваляйте в муке."),
                      [("Толщина", "около 2 см", GREEN), ("Обвалять", "в рисовой муке", BRICK)], s_shape), "4/5"),
    (2.6, K.make_step("ШАГ 4 · ЖАРИТЬ",
                      ("Средний огонь, под крышкой.", "Затем переверните на другую сторону."),
                      [("Огонь", "средний", BRICK), ("Каждая сторона", "3–4 минуты", GREEN)], s_fry), "5/5"),
    (1.8, K.make_final(dish_final, ("Пышные внутри,", "с карамельной корочкой."),
                       ("Без глютена", "рисовая мука")), ""),
]

if __name__ == "__main__":
    K.run(SLUG, SCENES)
