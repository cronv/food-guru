"""
Рилс «Паста качо-э-пепе» — 20 секунд, 1080x1920.
Данные из site/index.html (slug pasta-cacio-e-pepe); граммовки — на 2 порции.
"""
import math
import random

from PIL import ImageDraw

import make_reel as E
import reel_kit as K
import make_pasta_reel as P

W = E.W
SLUG = "pasta-cacio-e-pepe"
GREEN, BRICK = E.GREEN, E.BRICK
CHEESE = (242, 224, 172)
CHEESE_D = (176, 148, 92)
SAUCE_C, SAUCE_CD = (236, 208, 150), (168, 130, 76)
PEPPER = (36, 32, 30)

ING = [
    ("Спагетти", 200, "г"),
    ("Пекорино романо", 90, "г"),
    ("Чёрный перец горошком", 1, "ч. л."),
    ("Соль крупная", 1, "ч. л."),
]


def pepper_specks(layer, cx, cy, rx, ry, n, seed=6):
    rnd = random.Random(seed)
    d = ImageDraw.Draw(layer)
    for _ in range(n):
        a, r = rnd.uniform(0, 6.28), math.sqrt(rnd.random())
        x, y = cx + math.cos(a) * rx * r, cy + math.sin(a) * ry * r
        s = rnd.uniform(1.8, 3.4)
        d.ellipse([x - s, y - s, x + s, y + s], fill=PEPPER)


def peppercorn(layer, x, y, r=11):
    d = ImageDraw.Draw(layer)
    d.ellipse([x - r, y - r, x + r, y + r], fill=(30, 27, 26))
    d.ellipse([x - r * 0.6, y - r * 0.7, x - r * 0.05, y - r * 0.2], fill=(96, 90, 86))


def shavings(layer, cx, cy, rx, ry, n, seed=12):
    rnd = random.Random(seed)
    d = ImageDraw.Draw(layer)
    for _ in range(n):
        a, r = rnd.uniform(0, 6.28), math.sqrt(rnd.random())
        x, y = cx + math.cos(a) * rx * r, cy + math.sin(a) * ry * r
        w = rnd.uniform(10, 20)
        d.ellipse([x - w, y - 4, x + w, y + 4], fill=(250, 240, 206))


def pasta_plate(layer, cy, sc=1.0):
    K.plate(layer, W / 2, cy, sc)
    P.draw_pasta_nest(layer, W / 2, cy - 20, scale=1.15 * sc)
    pepper_specks(layer, W / 2, cy - 20, 150 * sc, 70 * sc, 55)
    shavings(layer, W / 2, cy - 20, 130 * sc, 60 * sc, 14)


def hero(layer, lt, rise, t, al):
    pasta_plate(layer, 900 + rise)
    E.draw_steam(layer, W / 2, 740 + rise, t, strength=0.45 * al, spread=130)


def dish_ing(layer, lt, t):
    pasta_plate(layer, 1520, 0.6)


def s_boil(layer, lt, dur, t, p):
    K.draw_pot(layer, W / 2, 740, 1.05, content=(184, 200, 204), content_dark=(120, 140, 148), bubbles=1.0, t=t)
    K.spaghetti_in(layer, W / 2, 740, 262, 82, (226, 186, 112), amount=min(1.0, 0.3 + lt), phase=lt * 0.4)
    E.draw_steam(layer, W / 2, 700, t, strength=0.7, spread=190)


def s_pepper(layer, lt, dur, t, p):
    P.draw_skillet(layer, W / 2, 890, 1.0, fill=0.0)
    rnd = random.Random(2)
    for i in range(22):
        a, r = rnd.uniform(0, 6.28), math.sqrt(rnd.random())
        hop = abs(math.sin(lt * 6 + i * 1.7)) * 8
        peppercorn(layer, W / 2 + math.cos(a) * 200 * r, 890 + math.sin(a) * 130 * r - hop)
    E.draw_steam(layer, W / 2, 740, t, strength=0.3 * p, spread=150)


def s_cheese(layer, lt, dur, t, p):
    sm = E.ease(lt / (dur * 0.8))
    E.draw_bowl(layer, W / 2, 990, scale=1.1, content=E.mix((248, 236, 196), CHEESE, sm), content_dark=CHEESE_D,
                swirl=lt * 2.0)
    if lt < dur * 0.6:
        K.falling(layer, t, W / 2 - 170, W / 2 + 170, 600, 960, (252, 244, 212), n=22, size=6, speed=1.1)
    # терка
    d = ImageDraw.Draw(layer)
    E.shaded_poly(layer, [(W / 2 - 150, 560), (W / 2 + 150, 560), (W / 2 + 190, 700), (W / 2 - 190, 700)],
                  E.mix(E.STEEL, (255, 255, 255), 0.3), E.mix(E.STEEL, (40, 40, 44), 0.55))
    for r in range(3):
        for c in range(7):
            d.ellipse([W / 2 - 110 + c * 36 + r * 8, 590 + r * 34, W / 2 - 98 + c * 36 + r * 8, 602 + r * 34],
                      fill=(40, 40, 44))


def s_toss(layer, lt, dur, t, p):
    P.draw_skillet(layer, W / 2, 890, 1.0, sauce=E.mix((214, 190, 140), SAUCE_C, p), sauce_dark=SAUCE_CD,
                   fill=0.85, swirl=lt * 2.2)
    P.draw_pasta_nest(layer, W / 2, 890, scale=0.95)
    pepper_specks(layer, W / 2, 890, 170, 90, 50)
    # значок «огонь выключен»: перечёркнутое пламя
    d = ImageDraw.Draw(layer)
    cx, cy = 880, 640
    d.ellipse([cx - 62, cy - 62, cx + 62, cy + 62], fill=(30, 26, 20, 240), outline=(255, 255, 255, 40), width=3)
    d.polygon([(cx, cy - 34), (cx + 22, cy + 6), (cx + 12, cy + 32), (cx - 12, cy + 32), (cx - 22, cy + 6),
               (cx - 8, cy - 8)], fill=BRICK)
    d.line([(cx - 44, cy + 40), (cx + 44, cy - 40)], fill=(240, 240, 236), width=8)
    E.draw_steam(layer, W / 2, 730, t, strength=0.3, spread=140)


def dish_final(layer, lt, t):
    pasta_plate(layer, 900, 1.0 + 0.04 * E.ease(lt / 1.2))
    E.draw_steam(layer, W / 2, 740, t, strength=0.45, spread=130)


SCENES = [
    (3.4, K.make_title(hero, "ПАСТА", "КАЧО-Э-ПЕПЕ",
                       "три ингредиента · 30 минут · 2 порции", ("Секрет", "правильная эмульсия")), ""),
    (4.2, K.make_ingredients("2 ПОРЦИИ", ING, dish_ing), "1/5"),
    (2.9, K.make_step("ШАГ 1 · СПАГЕТТИ",
                      ("Варите в небольшом количестве воды.", "Она должна стать по-настоящему крахмалистой."),
                      [("Спагетти", "200 г", GREEN), ("Воды", "немного", BRICK)], s_boil), "2/5"),
    (2.6, K.make_step("ШАГ 2 · ПЕРЕЦ",
                      ("Перец крупно раздавите.", "Прогрейте до аромата."),
                      [("Сковорода", "сухая", BRICK), ("Время", "около минуты", GREEN)], s_pepper), "3/5"),
    (2.5, K.make_step("ШАГ 3 · СЫРНАЯ ПАСТА",
                      ("Сыр натрите мелко, разотрите", "с тёплой водой из-под пасты."),
                      [("Пекорино, мелко", "90 г", GREEN), ("Тёплая вода", "пара ложек", BRICK)], s_cheese), "4/5"),
    (2.6, K.make_step("ШАГ 4 · СОБРАТЬ",
                      ("Почти готовые спагетти — к перцу и сыру.", "Мешайте энергично, пока соус не заблестит."),
                      [("Огонь", "выключен", BRICK), ("Соус", "глянцевый", GREEN)], s_toss), "5/5"),
    (1.8, K.make_final(dish_final, ("Главное — не перегреть сыр,", "иначе он свернётся комками."),
                       ("Сыр", "только вне огня")), ""),
]

if __name__ == "__main__":
    K.run(SLUG, SCENES)
