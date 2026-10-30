"""
Рилс «Шакшука с фетой» — 20 секунд, 1080x1920.
Данные из site/index.html (slug shakshuka-s-fetoy); граммовки — на 2 порции.
"""
import math
import random

from PIL import ImageDraw

import make_reel as E
import reel_kit as K
import make_pasta_reel as P

W = E.W
SLUG = "shakshuka-s-fetoy"
SAUCE, SAUCE_D = (190, 64, 36), (104, 30, 16)
GREEN, BRICK = E.GREEN, E.BRICK
CY = 890

ING = [
    ("Яйца", 3, "шт"),
    ("Томаты в собственном соку", 400, "г"),
    ("Перец болгарский", 1, "шт"),
    ("Лук", 1, "шт"),
    ("Фета", 60, "г"),
    ("Зира молотая", 0.5, "ч. л."),
]

EGG_POS = ((-120, -40), (118, -30), (-4, 72))


def pan(layer, cy, sauce=SAUCE, sauce_d=SAUCE_D, fill=0.85, swirl=0.0, scale=1.0):
    P.draw_skillet(layer, W / 2, cy, scale, sauce=sauce, sauce_dark=sauce_d, fill=fill, swirl=swirl)


def feta(layer, cx, cy, n, seed=4, p=1.0):
    rnd = random.Random(seed)
    d = ImageDraw.Draw(layer)
    for i in range(n):
        x, y = cx + rnd.uniform(-190, 190), cy + rnd.uniform(-110, 110)
        if (x - cx) ** 2 / 200 ** 2 + (y - cy) ** 2 / 120 ** 2 > 1:
            continue
        s = rnd.uniform(9, 15)
        d.rectangle([x - s, y - s * 0.7, x + s, y + s * 0.7], fill=(250, 248, 240))
        d.rectangle([x - s, y + s * 0.2, x + s, y + s * 0.7], fill=(222, 218, 206))


def hero(layer, lt, rise, t, al):
    pan(layer, 880 + rise, swirl=0.5)
    for dx, dy in EGG_POS:
        K.egg(layer, W / 2 + dx * 1.0, 880 + rise + dy, 1.0)
    feta(layer, W / 2, 880 + rise, 16)
    E.draw_steam(layer, W / 2, 720 + rise, t, strength=0.45 * al, spread=150)


def dish_ing(layer, lt, t):
    pan(layer, 1520, scale=0.58, swirl=lt)
    for dx, dy in EGG_POS:
        K.egg(layer, W / 2 + dx * 0.58, 1520 + dy * 0.58, 0.58)


def s_veg(layer, lt, dur, t, p):
    pan(layer, CY, sauce=(184, 138, 60), sauce_d=(110, 74, 28), fill=0.9, swirl=lt)
    d = ImageDraw.Draw(layer)
    rnd = random.Random(8)
    for i in range(30):
        a, r = rnd.uniform(0, 6.28), math.sqrt(rnd.random())
        x = W / 2 + math.cos(a) * 205 * r
        y = CY + math.sin(a) * 135 * r
        if i % 2:  # перец
            col = E.mix((222, 60, 40), (196, 70, 36), p)
            d.rounded_rectangle([x - 20, y - 8, x + 20, y + 8], radius=6, fill=col)
            d.ellipse([x - 12, y - 6, x + 2, y - 1], fill=E.mix(col, (255, 200, 180), 0.45))
        else:  # лук
            col = E.mix((244, 238, 214), (222, 184, 98), p * 0.8)
            d.ellipse([x - 18, y - 7, x + 18, y + 7], fill=col)
    E.draw_steam(layer, W / 2, 740, t, strength=0.4, spread=140)


def s_tomato(layer, lt, dur, t, p):
    thick = E.ease(lt / (dur * 0.85))
    pan(layer, CY, sauce=E.mix((214, 96, 58), SAUCE, thick), sauce_d=E.mix((140, 50, 30), SAUCE_D, thick),
        fill=0.55 + 0.35 * thick, swirl=lt * 1.6)
    if lt < dur * 0.5:
        K.falling(layer, t, W / 2 - 150, W / 2 + 150, 640, 860, (188, 134, 62), n=18, size=4.5)
    E.draw_steam(layer, W / 2, 740, t, strength=0.5, spread=140)


def s_wells(layer, lt, dur, t, p):
    pan(layer, CY)
    d = ImageDraw.Draw(layer)
    for i, (dx, dy) in enumerate(EGG_POS):
        start = 0.15 + i * 0.4
        if lt < start:
            continue
        q = E.ease((lt - start) / 0.5)
        if q < 1:
            r = 70 * q
            E.shaded_ellipse(layer, [W / 2 + dx - r, CY + dy - r * 0.7, W / 2 + dx + r, CY + dy + r * 0.7],
                             (90, 26, 14), (140, 44, 24))
        drop = (1 - E.ease_out((lt - start - 0.45) / 0.5)) * 260 if lt > start + 0.45 else 260
        if lt > start + 0.45:
            K.egg(layer, W / 2 + dx, CY + dy - drop, 1.0, cooked=0.0)
        else:
            E.shaded_ellipse(layer, [W / 2 + dx - 60, CY + dy - 38, W / 2 + dx + 60, CY + dy + 38],
                             (90, 26, 14), (140, 44, 24))
    E.draw_steam(layer, W / 2, 740, t, strength=0.4, spread=140)


def s_cover(layer, lt, dur, t, p):
    pan(layer, CY)
    for dx, dy in EGG_POS:
        K.egg(layer, W / 2 + dx, CY + dy, 1.0, cooked=p)
    lift = (1 - E.ease_out(lt / 0.6)) * 200
    K.glass_lid(layer, W / 2, CY, 280, 190, lift)
    if lt > dur * 0.82:
        a = min(1.0, (lt - dur * 0.82) / 0.3)
        if a > 0.3:
            feta(layer, W / 2, CY, 18)
    E.draw_steam(layer, W / 2, 740, t, strength=0.55, spread=150)


def dish_final(layer, lt, t):
    pan(layer, 900, swirl=0.0)
    for dx, dy in EGG_POS:
        K.egg(layer, W / 2 + dx, 900 + dy, 1.0)
    feta(layer, W / 2, 900, 22)
    d = ImageDraw.Draw(layer)
    for dx, dy in ((-60, 20), (90, 10), (-130, 60)):  # зира
        d.ellipse([W / 2 + dx - 3, 900 + dy - 3, W / 2 + dx + 3, 900 + dy + 3], fill=(150, 98, 40))
    E.draw_steam(layer, W / 2, 740, t, strength=0.45, spread=150)


SCENES = [
    (3.4, K.make_title(hero, "ШАКШУКА", "С ФЕТОЙ",
                       "завтрак на одной сковороде · 25 минут · 2 порции", ("Секрет", "жидкий желток")), ""),
    (4.2, K.make_ingredients("2 ПОРЦИИ", ING, dish_ing), "1/5"),
    (2.9, K.make_step("ШАГ 1 · ЛУК И ПЕРЕЦ",
                      ("Лук и перец — в сковороду.", "Жарьте до мягкости."),
                      [("Лук + перец", "1 шт + 1 шт", GREEN), ("Время", "7–8 минут", BRICK)], s_veg), "2/5"),
    (2.6, K.make_step("ШАГ 2 · ЗИРА И ТОМАТЫ",
                      ("Зира — на полминуты, затем томаты.", "Тушите, пока соус не загустеет."),
                      [("Зира", "полминуты", BRICK), ("Томаты", "400 г", GREEN)], s_tomato), "3/5"),
    (2.5, K.make_step("ШАГ 3 · ЯЙЦА",
                      ("В соусе сделайте углубления", "и разбейте в них яйца."),
                      [("Яйца", "3 шт", GREEN), ("Углубления", "по одному на яйцо", BRICK)], s_wells), "4/5"),
    (2.6, K.make_step("ШАГ 4 · ПОД КРЫШКОЙ",
                      ("Накройте крышкой, пока белок не схватится.", "Сверху раскрошите фету."),
                      [("Под крышкой", "5–6 минут", BRICK), ("Желток", "жидкий", GREEN)], s_cover), "5/5"),
    (1.8, K.make_final(dish_final, ("Снимайте с огня чуть раньше,", "чем кажется нужным."),
                       ("Фета, сверху", "60 г")), ""),
]

if __name__ == "__main__":
    K.run(SLUG, SCENES)
