"""
Обложка для превью ссылки в Telegram и мессенджерах — 1200x630.
Рисуется тем же движком, что и рилсы: tools/reels/make_reel.py.

    docker run --rm -v "<репозиторий>:/work" -w /work/tools/og \
      -e OG_OUT=/work/frontend/projects/site/public/og-cover.jpg reels-render python make_og.py
"""
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "reels"))

from PIL import Image, ImageDraw  # noqa: E402

import make_reel as E  # noqa: E402

W, H = 1200, 630
OUT = os.environ.get("OG_OUT", "og-cover.jpg")


def main():
    # фон движка вертикальный — берём его центральную часть и доливаем столешницу
    img = Image.new("RGBA", (W, H), E.BG_BOT + (255,))
    d = ImageDraw.Draw(img)

    # мягкий тёплый свет сверху слева
    glow = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    gd = ImageDraw.Draw(glow)
    for i in range(14):
        r = 760 - i * 46
        gd.ellipse([330 - r, 180 - r * 0.9, 330 + r, 180 + r * 0.9], fill=(96, 72, 44, 8))
    from PIL import ImageFilter
    img.alpha_composite(glow.filter(ImageFilter.GaussianBlur(36)))

    # столешница
    table = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    td = ImageDraw.Draw(table)
    td.rectangle([0, 430, W, H], fill=E.TABLE + (255,))
    for i in range(50):
        a = int(40 * (1 - i / 50))
        td.line([(0, 430 + i), (W, 430 + i)], fill=E.TABLE_HI + (a,))
    img.alpha_composite(table.filter(ImageFilter.GaussianBlur(1.2)))

    layer = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    E.draw_fondant_cut(layer, 950, 350, scale=0.85, flow=1.0, steam_t=0.4)
    img.alpha_composite(layer)

    d = ImageDraw.Draw(img)
    d.text((70, 150), E.BRAND, font=E.F_BOLD(30), fill=E.mix(E.CREAM, E.BG_BOT, 0.4))
    d.text((70, 215), "Рецепты, которые", font=E.F_BLACK(64), fill=E.CREAM)
    d.text((70, 290), "получаются", font=E.F_BLACK(64), fill=E.CREAM)
    d.text((70, 365), "с первого раза", font=E.F_BLACK(64), fill=E.BRICK)
    d.text((70, 480), "Граммовки · видеорецепты · отзывы с фото",
           font=E.F_REG(26), fill=E.MUTED)

    img.convert("RGB").save(OUT, quality=88, optimize=True)
    print("обложка:", OUT)


if __name__ == "__main__":
    main()
