"""
Рилс «Тыквенный крем-суп с хрустящим нутом» — 20 секунд, 1080x1920.
Данные взяты из site/index.html (slug tykvennyy-krem-sup); граммовки — на 4 порции.
Запуск см. README.md (Docker): python make_tykvennyy_reel.py
"""
import math
import random

from PIL import ImageDraw

import make_reel as E
import reel_kit as K
import make_pasta_reel as P

W = E.W
SLUG = "tykvennyy-krem-sup"
PUMP = (232, 138, 48)
SOUP = (226, 140, 50)
SOUP_D = (140, 74, 24)
GREEN, BRICK = E.GREEN, E.BRICK

# рецепт на 1 порцию ×4
ING = [
    ("Тыква, очищенная", 800, "г"),
    ("Нут отварной", 240, "г"),
    ("Лук репчатый", 1, "шт"),
    ("Чеснок", 2, "зубчика"),
    ("Овощной бульон", 700, "мл"),
    ("Оливковое масло", 3, "ст. л."),
    ("Копчёная паприка", 1, "ч. л."),
    ("Имбирь свежий", 16, "г"),
]


def soup_bowl(layer, cx, cy, s, t, chick=1.0, swirl=0.0):
    E.draw_bowl(layer, cx, cy, scale=s, content=SOUP, content_dark=SOUP_D, swirl=swirl)
    rw, rh = 250 * s, 120 * s
    rnd = random.Random(11)
    n = int(11 * chick)
    for i in range(n):
        a = rnd.uniform(0, 6.28)
        r = math.sqrt(rnd.random())
        x, y = cx + math.cos(a) * rw * 0.55 * r, cy + math.sin(a) * rh * 0.17 * r - 2 * s
        K.chickpea(layer, x, y, 17 * s, toast=0.85)
    if chick > 0.5:
        d = ImageDraw.Draw(layer)
        for dx, dy in ((-60, 8), (70, -6), (10, 16)):
            d.ellipse([cx + dx * s - 9 * s, cy + dy * s - 4 * s, cx + dx * s + 9 * s, cy + dy * s + 4 * s],
                      fill=(214, 168, 70))


def hero(layer, lt, rise, t, al):
    soup_bowl(layer, W / 2, 920 + rise, 1.2, t, swirl=0.4)
    E.draw_steam(layer, W / 2, 760 + rise, t, strength=0.5 * al, spread=150)


def dish_ing(layer, lt, t):
    soup_bowl(layer, W / 2, 1500, 0.62, t, swirl=lt)


def s_bake(layer, lt, dur, t, p):
    K.draw_tray(layer, W / 2, 900, 1.0, cubes=PUMP, bake=p, warm=0.5 * p)
    E.draw_steam(layer, W / 2, 770, t, strength=0.35 * p, spread=330)


def s_onion(layer, lt, dur, t, p):
    P.draw_skillet(layer, W / 2, 890, 1.0, sauce=(168, 124, 52), sauce_dark=(104, 70, 26), fill=0.9,
                   swirl=lt * 1.0)
    d = ImageDraw.Draw(layer)
    rnd = random.Random(5)
    for i in range(34):
        a, r = rnd.uniform(0, 6.28), math.sqrt(rnd.random())
        x = W / 2 + math.cos(a) * 205 * r + math.sin(lt * 2 + i) * 4
        y = 890 + math.sin(a) * 135 * r
        col = E.mix((244, 238, 214), (222, 184, 98), p * 0.8)
        d.ellipse([x - 20, y - 8, x + 20, y + 8], fill=col)
        d.ellipse([x - 14, y - 6, x + 6, y], fill=E.mix(col, (255, 255, 255), 0.5))
    for i in range(6):  # имбирь
        a = i * 1.1
        x, y = W / 2 + math.cos(a) * 120, 890 + math.sin(a) * 78
        d.ellipse([x - 7, y - 7, x + 7, y + 7], fill=(226, 186, 96))
    for i in range(3):  # чеснок
        a = i * 2.1 + 0.4
        x, y = W / 2 + math.cos(a) * 150, 890 + math.sin(a) * 90
        d.ellipse([x - 9, y - 6, x + 9, y + 6], fill=(240, 232, 206))
    E.draw_steam(layer, W / 2, 740, t, strength=0.45, spread=140)


def s_blend(layer, lt, dur, t, p):
    sm = E.ease(lt / (dur * 0.75))
    K.draw_blender(layer, W / 2, 830, 1.0, fill=E.mix((206, 120, 44), SOUP, sm), smooth=sm, spin=lt)
    E.draw_steam(layer, W / 2, 520, t, strength=0.4, spread=120, scale=0.8)


def s_chick(layer, lt, dur, t, p):
    P.draw_skillet(layer, W / 2, 890, 1.0, fill=0.0)
    rnd = random.Random(21)
    for i in range(24):
        a, r = rnd.uniform(0, 6.28), math.sqrt(rnd.random())
        hop = abs(math.sin(lt * 5 + i * 1.3)) * 10 * (1 if i % 3 == 0 else 0.3)
        x = W / 2 + math.cos(a) * 215 * r
        y = 890 + math.sin(a) * 140 * r - hop
        K.chickpea(layer, x, y, 17, toast=p)
    K.falling(layer, t, W / 2 - 200, W / 2 + 200, 660, 860, (196, 84, 40), n=16, size=4, alpha=190)
    E.draw_steam(layer, W / 2, 740, t, strength=0.3 * p, spread=140)


def dish_final(layer, lt, t):
    soup_bowl(layer, W / 2, 920, 1.2 + 0.04 * E.ease(lt / 1.2), t, swirl=0.4)
    E.draw_steam(layer, W / 2, 760, t, strength=0.5, spread=150)


SCENES = [
    (3.4, K.make_title(hero, "ТЫКВЕННЫЙ", "КРЕМ-СУП",
                       "с хрустящим нутом · 35 минут · 4 порции", ("Секрет", "хруст от нута")), ""),
    (4.2, K.make_ingredients("4 ПОРЦИИ", ING, dish_ing), "1/5"),
    (2.9, K.make_step("ШАГ 1 · ЗАПЕЧЬ ТЫКВУ",
                      ("Кубики 3 см — на противень, с маслом.", "Края станут мягкими и карамельными."),
                      [("Духовка", "200 °C", BRICK), ("Время", "20 минут", GREEN)], s_bake), "2/5"),
    (2.6, K.make_step("ШАГ 2 · ЛУК",
                      ("Лук с имбирём и чесноком — в сковороду.", "Только до прозрачности, без румяной корочки."),
                      [("Огонь", "средний", BRICK), ("Лук, имбирь, чеснок", "5 минут", GREEN)], s_onion), "3/5"),
    (2.5, K.make_step("ШАГ 3 · БЛЕНДЕР",
                      ("Тыква, нут и горячий бульон — в блендер.", "Пробейте до полностью гладкой текстуры."),
                      [("Бульон, горячий", "700 мл", GREEN), ("Блендер", "до гладкости", BRICK)], s_blend), "4/5"),
    (2.6, K.make_step("ШАГ 4 · ХРУСТЯЩИЙ НУТ",
                      ("Нут обсушите, на сухую сковороду с паприкой.", "Жарьте до хруста."),
                      [("Сковорода", "сухая", BRICK), ("Нут с паприкой", "4–5 минут", GREEN)], s_chick), "5/5"),
    (1.8, K.make_final(dish_final, ("Сверху хрустящий нут", "и капля оливкового масла."),
                       ("Нут для подачи", "треть, 80 г")), ""),
]

if __name__ == "__main__":
    K.run(SLUG, SCENES)
