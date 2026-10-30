<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Book;
use App\Models\ClubItem;
use App\Models\Media;
use App\Models\Product;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Commerce\WalletService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Демо для кошелька, книг, магазина и закрытой академии. Обложки рисуются
 * здесь же простыми SVG — чужих картинок нет. Видео мастер-классов — наши
 * рилсы, скопированные на закрытый диск: заменить на настоящие из админки.
 *
 * Только для разработки и витрины.
 */
class CommerceSeeder extends Seeder
{
    private User $uploader;

    public function run(): void
    {
        $this->uploader = User::where('role', UserRole::Admin)->first() ?? User::first();

        SubscriptionPlan::create(['code' => 'month', 'title' => 'Месяц', 'months' => 1, 'price' => 99000, 'position' => 1]);
        SubscriptionPlan::create(['code' => 'quarter', 'title' => '3 месяца', 'months' => 3, 'price' => 249000, 'position' => 2]);
        SubscriptionPlan::create(['code' => 'year', 'title' => 'Год', 'months' => 12, 'price' => 790000, 'position' => 3]);

        $this->books();
        $this->products();
        $this->club();

        // Демо-читателю — 3 000 ₽ на балансе, чтобы купить и проверить без оплаты.
        if ($reader = User::where('email', 'user@food-guru.local')->first()) {
            app(WalletService::class)->credit($reader, 300000, WalletTransaction::ADJUSTMENT, 'Приветственный бонус');
        }
    }

    private function books(): void
    {
        $books = [
            [
                'slug' => 'nozh-i-doska', 'title' => 'Нож и доска', 'author' => 'Мария Левина', 'price' => 0, 'sub' => false,
                'colors' => ['#17704A', '#E6F2EA'],
                'lead' => 'Короткий учебник нарезки: хват, стойка, пять базовых форм.',
                'chapters' => [
                    ['Как держать нож', "Большой и указательный пальцы держат клинок у основания, остальные обхватывают рукоять. Так нож становится продолжением руки, а не рычагом.\n\nВторая рука — «коготь»: подушечки пальцев подогнуты, костяшки упираются в плоскость клинка и задают толщину ломтика."],
                    ['Доска не должна ездить', "Подложите под доску влажное полотенце. Это первое, что делают на любой профессиональной кухне, и последнее, о чём думают дома.\n\nДеревянная доска бережёт кромку, пластиковая — удобна для сырого мяса: её можно мыть горячей водой."],
                    ['Пять форм', "Брюнуаз — кубик 3 мм, жюльен — соломка 3×3×50 мм, макседуан — кубик 1 см, шифонад — тонкая лента из сложенных листьев, конкассе — грубо рубленные томаты без кожицы и семян.\n\nОдинаковый размер нужен не для красоты: куски одной формы готовятся за одно время."],
                ],
            ],
            [
                'slug' => 'supy-na-kazhdyy-den', 'title' => 'Супы на каждый день', 'author' => 'Редакция Food Guru', 'price' => 0, 'sub' => false,
                'colors' => ['#C2410C', '#FCEBE6'],
                'lead' => 'Восемь супов, которые не требуют бульона с утра.',
                'chapters' => [
                    ['Зачем нужна база', 'Почти любой суп начинается одинаково: лук, морковь и сельдерей медленно томятся в масле. Пять минут здесь дают больше вкуса, чем час варки потом.'],
                    ['Крем-супы без сливок', 'Бархатную текстуру даёт не жирность, а крахмал и блендер. Картофелина или горсть риса в кастрюле делают суп густым без единой ложки сливок.'],
                    ['Хруст на тарелке', 'Обжаренный нут, семечки, гренки из вчерашнего хлеба — суп становится блюдом, когда в нём есть контраст текстур.'],
                ],
            ],
            [
                'slug' => 'testo-bez-strakha', 'title' => 'Тесто без страха', 'author' => 'Мария Левина', 'price' => 49000, 'sub' => true,
                'colors' => ['#8A5A2B', '#F7EFE1'],
                'lead' => 'Дрожжевое, песочное, заварное: что происходит внутри и как это контролировать.',
                'chapters' => [
                    ['Мука — это белок', 'Сила муки — это количество белка. Для хлеба нужна мука от 12% белка, для песочного теста — не больше 10%. Посмотрите на упаковку, прежде чем винить рецепт.', true],
                    ['Дрожжевое тесто', "Дрожжам нужны тепло, время и сахар — в этом порядке. Тесто, поднявшееся вдвое за ночь в холодильнике, вкуснее того, что поднялось за час у батареи.\n\nПроверка готовности: ямка от пальца медленно заполняется, но не до конца."],
                    ['Песочное тесто', "Холод — главный ингредиент. Масло, мука и даже миска должны быть холодными, иначе тесто станет плотным, а не рассыпчатым.\n\nЗамешивайте быстро и сразу в холодильник: глютену нельзя давать развиться."],
                    ['Заварное тесто', 'Мука заваривается в кипящей воде с маслом, пока не отстанет от стенок комом. Яйца вводятся по одному: тесто готово, когда стекает с лопатки треугольником.'],
                ],
            ],
            [
                'slug' => 'sousy-restorannoy-kukhni', 'title' => 'Соусы ресторанной кухни', 'author' => 'Шеф Алексей Громов', 'price' => 89000, 'sub' => true,
                'colors' => ['#2A1C14', '#E8C77A'],
                'lead' => 'Пять материнских соусов и тридцать производных от них.',
                'chapters' => [
                    ['Почему пять', 'Бешамель, велюте, эспаньоль, томатный и голландез — от них происходит почти всё, что подают к мясу, рыбе и овощам во французской кухне.', true],
                    ['Ру — основа загустения', 'Мука, обжаренная в масле поровну по весу. Белое ру — для бешамеля, золотистое — для велюте, коричневое — для эспаньоля. Чем темнее ру, тем слабее оно загущает.'],
                    ['Голландез без страха', 'Эмульсия желтков и масла держится при 60–65 °C. Выше — желтки свернутся, ниже — масло отделится. Термометр решает всё.'],
                    ['Монтировать маслом', 'Холодное масло кусочками в соус, снятый с огня, — и соус блестит. Это последний шаг почти любого ресторанного соуса.'],
                ],
            ],
            [
                'slug' => 'zagotovki-po-sezonam', 'title' => 'Заготовки по сезонам', 'author' => 'Наталья Белова', 'price' => 39000, 'sub' => false,
                'colors' => ['#5B6B2E', '#EEF1DF'],
                'lead' => 'Квашение, маринады и варенье — с граммовками и безопасностью.',
                'chapters' => [
                    ['Безопасность прежде всего', 'Стерилизация банок, кислотность маринада и соль для квашения — не перестраховка. Ботулизм не меняет вкус и запах продукта.', true],
                    ['Квашеная капуста', '2% соли от веса капусты — и ничего больше. Комнатная температура пять дней, затем холод.'],
                    ['Маринады', 'Уксус 9% разводится так, чтобы в рассоле было не меньше 0,6% кислоты. Ниже — заготовка не хранится.'],
                ],
            ],
        ];

        // Год издания и страна — для фильтров раздела книг.
        $editions = [
            'nozh-i-doska' => [2024, 'Россия'],
            'supy-na-kazhdyy-den' => [2025, 'Россия'],
            'testo-bez-strakha' => [2023, 'Россия'],
            'sousy-restorannoy-kukhni' => [2021, 'Франция'],
            'zagotovki-po-sezonam' => [2019, 'Беларусь'],
        ];

        foreach ($books as $i => $b) {
            $book = Book::create([
                'slug' => $b['slug'],
                'title' => $b['title'],
                'author_name' => $b['author'],
                'year' => $editions[$b['slug']][0],
                'country' => $editions[$b['slug']][1],
                'lead' => $b['lead'],
                'description' => $b['lead'],
                'price' => $b['price'],
                'in_subscription' => $b['sub'],
                'is_published' => true,
                'published_at' => now()->subDays(30 - $i * 5),
                'cover_media_id' => $this->svg("books/{$b['slug']}.svg", $this->bookCover($b['title'], $b['author'], ...$b['colors']), 600, 900, "Обложка книги «{$b['title']}»")->id,
            ]);

            foreach ($b['chapters'] as $n => $c) {
                $book->chapters()->create([
                    'position' => $n + 1,
                    'title' => $c[0],
                    'body' => $c[1],
                    // У бесплатной всё открыто; у платной — помеченная глава или первая.
                    'is_preview' => $c[2] ?? false,
                ]);
            }
        }
    }

    private function products(): void
    {
        $items = [
            ['nozh-shefa-20', 'Нож шефа 20 см', 'Ножи', 459000, 549000, 12, 'knife', 'Сталь X50CrMoV15, рукоять из ореха. Универсальный нож, с которого начинают кухню.'],
            ['nozh-ovoshchnoy', 'Нож овощной 9 см', 'Ножи', 149000, null, 25, 'knife', 'Короткий клинок для чистки и мелкой работы на весу.'],
            ['doska-buk', 'Доска из бука 40×28', 'Доски', 259000, null, 8, 'board', 'Торцевая склейка: бережёт кромку ножа, не скользит.'],
            ['skovoroda-chugun-26', 'Чугунная сковорода 26 см', 'Посуда', 389000, null, 4, 'pan', 'Прокалённая на заводе. Держит температуру — идеальна для стейков и шакшуки.'],
            ['kastryulya-3l', 'Кастрюля 3 л с толстым дном', 'Посуда', 329000, 379000, 6, 'pot', 'Трёхслойное дно: супы и соусы не пригорают.'],
            ['venchik', 'Венчик 30 см', 'Инструменты', 59000, null, 40, 'whisk', 'Десять прутьев из нержавеющей стали — для эмульсий и крема.'],
            ['termometr', 'Цифровой термометр-щуп', 'Инструменты', 179000, null, 3, 'thermo', 'От −50 до 300 °C за три секунды. Для голландеза, карамели и мяса.'],
            ['vesy', 'Кухонные весы до 5 кг', 'Инструменты', 199000, null, 0, 'scale', 'Шаг 1 г. Наши рецепты — в граммах, и это не случайно.'],
        ];

        // Материал, страна, цвет — для фильтров магазина.
        $props = [
            'nozh-shefa-20' => ['Нержавеющая сталь', 'Германия', 'Стальной'],
            'nozh-ovoshchnoy' => ['Нержавеющая сталь', 'Германия', 'Стальной'],
            'doska-buk' => ['Дерево', 'Россия', 'Натуральный'],
            'skovoroda-chugun-26' => ['Чугун', 'Россия', 'Чёрный'],
            'kastryulya-3l' => ['Нержавеющая сталь', 'Италия', 'Стальной'],
            'venchik' => ['Нержавеющая сталь', 'Китай', 'Стальной'],
            'termometr' => ['Пластик', 'Китай', 'Красный'],
            'vesy' => ['Пластик', 'Китай', 'Белый'],
        ];

        foreach ($items as [$slug, $title, $cat, $price, $old, $stock, $icon, $lead]) {
            [$material, $country, $color] = $props[$slug];

            Product::create([
                'slug' => $slug,
                'title' => $title,
                'category' => $cat,
                'material' => $material,
                'country' => $country,
                'color' => $color,
                'lead' => $lead,
                'description' => $lead."\n\nДоставка курьером по городу, Почтой России или самовывоз.",
                'sku' => 'FG-'.strtoupper(substr(md5($slug), 0, 6)),
                'price' => $price,
                'old_price' => $old,
                'stock' => $stock,
                'is_published' => true,
                'cover_media_id' => $this->svg("products/{$slug}.svg", $this->productArt($icon), 800, 800, $title)->id,
            ]);
        }
    }

    private function club(): void
    {
        $videos = collect(File::glob(database_path('seeders/assets/reels/*.mp4')))->values();
        $dishes = database_path('seeders/assets/dishes');

        $items = [
            ['masterclass', 'Стейк рибай: от прожарки до соуса', 'Шеф Алексей Громов', 48,
                'Закрытый мастер-класс: обратная прожарка, отдых мяса и соус на сковороде за три минуты.',
                "Разбираем три способа довести стейк до нужной прожарки и почему обратная прожарка надёжнее всего дома.\n\nВ конце — соус на тех же соках: шалот, вино, холодное масло.", 'pasta-krevetki-tomat'],
            ['masterclass', 'Шоколадный фондан ресторанной подачи', 'Мария Левина', 35,
                'Темперирование, кнели и тарелка, которую не стыдно сфотографировать.',
                'Фондан из открытого рецепта — это база. Здесь разбираем подачу: мороженое кнелью, крамбл и соус из маракуйи.', 'shokoladnyy-fondan'],
            ['video', 'Мокрый и сухой маринады: разбор', 'Шеф Алексей Громов', 18,
                'Что на самом деле проникает в мясо и сколько времени это занимает.',
                'Соль проникает в мясо, а специи остаются на поверхности. Отсюда два правила маринада, которые разбираем на примерах.', 'tykvennyy-krem-sup'],
            ['recipe', 'Ризотто с белыми грибами и пармезаном', 'Шеф Алексей Громов', 45,
                'Рецепт из меню ресторана: бульон из обрезков грибов и мантекатура.',
                'Главное в ризотто — не рис, а бульон и последний шаг: мантекатура холодным маслом и пармезаном вне огня.', null],
            ['recipe', 'Тартар из говядины с желтком конфи', 'Мария Левина', 30,
                'Ресторанная классика с безопасной работой с сырым мясом.',
                'Для тартара берут только вырезку, которую режут ножом, а не мясорубкой. Желток томится в масле при 63 °C — получается густым, как крем.', null],
            ['material', 'Технологические карты для домашней кухни', 'Редакция Food Guru', null,
                'Как вести карту блюда: выход, брутто, нетто и себестоимость.',
                'Шаблон технологической карты и пример заполнения для трёх блюд. Помогает повторять результат и считать продукты на компанию.', null],
        ];

        $recipeData = [
            'Ризотто с белыми грибами и пармезаном' => [
                'ingredients' => ['Рис арборио — 320 г', 'Белые грибы — 300 г', 'Шалот — 2 шт', 'Сухое белое вино — 100 мл', 'Грибной бульон — 1,2 л', 'Масло сливочное холодное — 60 г', 'Пармезан — 70 г'],
                'steps' => ['Обрезки грибов залейте водой и варите 30 минут — это бульон.', 'Шалот пассеруйте до прозрачности, добавьте рис и обжарьте 2 минуты.', 'Влейте вино, выпарите. Бульон добавляйте по половнику, помешивая.', 'Через 16 минут добавьте обжаренные грибы.', 'Снимите с огня, вмешайте холодное масло и пармезан. Накройте на 2 минуты.'],
            ],
            'Тартар из говядины с желтком конфи' => [
                'ingredients' => ['Говяжья вырезка — 300 г', 'Желтки — 4 шт', 'Оливковое масло — 200 мл', 'Корнишоны — 40 г', 'Каперсы — 20 г', 'Дижонская горчица — 1 ч. л.', 'Шалот — 1 шт'],
                'steps' => ['Желтки томите в масле при 63 °C 45 минут.', 'Вырезку охладите и нарежьте ножом кубиком 3–4 мм.', 'Смешайте мясо с мелко нарезанными шалотом, корнишонами, каперсами и горчицей.', 'Выложите кольцом, сверху — желток конфи.'],
            ],
        ];

        foreach ($items as $n => [$kind, $title, $chef, $minutes, $lead, $body, $coverFrom]) {
            $cover = null;
            if ($coverFrom && is_file("{$dishes}/{$coverFrom}-process.svg")) {
                $cover = $this->svg("club/{$coverFrom}.svg", file_get_contents("{$dishes}/{$coverFrom}-process.svg"), 1200, 900, $title);
            } else {
                $cover = $this->svg('club/cover-'.($n + 1).'.svg', $this->wideCover($title, $chef), 1200, 900, $title);
            }

            $item = ClubItem::create([
                'slug' => Str::slug($title),
                'kind' => $kind,
                'title' => $title,
                'lead' => $lead,
                'body' => $body,
                'chef_name' => $chef,
                'duration_minutes' => $minutes,
                'cover_media_id' => $cover->id,
                'data' => $recipeData[$title] ?? null,
                'is_published' => true,
                'published_at' => now()->subDays(10 - $n),
            ]);

            if (in_array($kind, ['masterclass', 'video'], true) && $videos->isNotEmpty()) {
                $source = $videos[$n % $videos->count()];
                $path = 'club/'.$item->slug.'.mp4';
                Storage::disk('local')->put($path, file_get_contents($source));
                $item->forceFill(['video_disk' => 'local', 'video_path' => $path])->save();
            }
        }
    }

    private function svg(string $path, string $svg, int $w, int $h, string $alt): Media
    {
        Storage::disk('public')->put($path, $svg);

        return Media::create([
            'uploaded_by' => $this->uploader->id,
            'path' => $path,
            'disk' => 'public',
            'width' => $w,
            'height' => $h,
            'size' => strlen($svg),
            'mime' => 'image/svg+xml',
            'alt' => $alt,
        ]);
    }

    /** Типографская обложка 600×900: название, автор, полоса цвета. */
    private function bookCover(string $title, ?string $author, string $bg, string $ink): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_XML1);
        $lines = $this->wrap($title, 14);
        $text = '';
        foreach ($lines as $i => $line) {
            $y = 300 + $i * 66;
            $text .= "<text x='60' y='{$y}' font-family='Georgia, serif' font-size='56' font-weight='700' fill='{$ink}'>{$e($line)}</text>";
        }

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 900" width="600" height="900">
  <rect width="600" height="900" fill="{$bg}"/>
  <rect x="0" y="0" width="22" height="900" fill="{$ink}" opacity=".18"/>
  <text x="60" y="120" font-family="Arial, sans-serif" font-size="22" letter-spacing="6" fill="{$ink}" opacity=".8">FOOD GURU</text>
  <rect x="60" y="150" width="90" height="4" fill="{$ink}"/>
  {$text}
  <text x="60" y="830" font-family="Arial, sans-serif" font-size="26" fill="{$ink}" opacity=".85">{$e($author)}</text>
</svg>
SVG;
    }

    /** Горизонтальная обложка 1200×900 для материалов без иллюстрации блюда. */
    private function wideCover(string $title, ?string $author): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_XML1);
        $text = '';
        foreach ($this->wrap($title, 22) as $i => $line) {
            $y = 400 + $i * 92;
            $text .= "<text x='110' y='{$y}' font-family='Georgia, serif' font-size='78' font-weight='700' fill='#E8C77A'>{$e($line)}</text>";
        }

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 900" width="1200" height="900">
  <rect width="1200" height="900" fill="#2A1C14"/>
  <circle cx="1040" cy="160" r="260" fill="#E8C77A" opacity=".07"/>
  <text x="110" y="230" font-family="Arial, sans-serif" font-size="28" letter-spacing="8" fill="#E8C77A" opacity=".8">ЗАКРЫТАЯ АКАДЕМИЯ</text>
  <rect x="110" y="262" width="120" height="5" fill="#E8C77A"/>
  {$text}
  <text x="110" y="790" font-family="Arial, sans-serif" font-size="34" fill="#C9B9A6">{$e($author)}</text>
</svg>
SVG;
    }

    private function wrap(string $text, int $width): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', $text) as $word) {
            $try = $line === '' ? $word : "{$line} {$word}";
            if (mb_strlen($try) > $width && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }

        return $line === '' ? $lines : [...$lines, $line];
    }

    /** Простой предмет на тёплом фоне. Рисунки свои, без сторонних картинок. */
    private function productArt(string $icon): string
    {
        $shapes = [
            'knife' => '<path d="M170 560 L560 250 Q600 220 620 250 Q630 270 600 300 L250 620 Z" fill="#D9DCE0"/><path d="M170 560 L250 620 L190 680 Q160 700 140 680 L120 660 Q100 640 120 610 Z" fill="#7A4B2A"/>',
            'board' => '<rect x="170" y="230" width="460" height="340" rx="36" fill="#C8955A"/><circle cx="580" cy="280" r="20" fill="#F7EFE1"/><path d="M220 300 H560 M220 380 H560 M220 460 H560" stroke="#B07C45" stroke-width="10"/>',
            'pan' => '<circle cx="360" cy="420" r="190" fill="#2F2F33"/><circle cx="360" cy="420" r="150" fill="#45454B"/><rect x="540" y="395" width="200" height="50" rx="25" fill="#2F2F33"/>',
            'pot' => '<rect x="210" y="300" width="380" height="280" rx="30" fill="#BFC4CA"/><rect x="190" y="280" width="420" height="40" rx="18" fill="#9AA0A7"/><rect x="140" y="340" width="80" height="30" rx="15" fill="#9AA0A7"/><rect x="580" y="340" width="80" height="30" rx="15" fill="#9AA0A7"/>',
            'whisk' => '<rect x="385" y="470" width="30" height="230" rx="15" fill="#7A4B2A"/><path d="M400 470 C300 400 300 200 400 160 C500 200 500 400 400 470 M400 470 C340 400 350 220 400 160 C450 220 460 400 400 470" fill="none" stroke="#C9CDD2" stroke-width="10"/>',
            'thermo' => '<rect x="250" y="200" width="200" height="140" rx="30" fill="#E3401F"/><rect x="280" y="230" width="140" height="70" rx="10" fill="#1E1E22"/><text x="350" y="280" font-family="Arial" font-size="40" fill="#7CF29A" text-anchor="middle">63°</text><rect x="342" y="340" width="16" height="360" rx="8" fill="#C9CDD2"/>',
            'scale' => '<rect x="200" y="330" width="400" height="260" rx="40" fill="#F2F2F2" stroke="#D0D0D0" stroke-width="6"/><rect x="300" y="470" width="200" height="70" rx="12" fill="#1E1E22"/><text x="400" y="520" font-family="Arial" font-size="40" fill="#7CF29A" text-anchor="middle">250 g</text><rect x="240" y="290" width="320" height="40" rx="20" fill="#D0D0D0"/>',
        ];

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 800" width="800" height="800">'
            .'<rect width="800" height="800" fill="#F3E9D8"/><ellipse cx="400" cy="680" rx="260" ry="28" fill="#000" opacity=".08"/>'
            .($shapes[$icon] ?? '').'</svg>';
    }
}
