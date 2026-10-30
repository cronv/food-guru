<?php

namespace Database\Seeders;

use App\Enums\RecipeStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\Review;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $staff = [
            ['Игорь Петров', 'admin@food-guru.local', UserRole::Admin],
            ['Ольга Волкова', 'lead@food-guru.local', UserRole::EditorLead],
            ['Мария Левина', 'manager@food-guru.local', UserRole::ContentManager],
            ['Анна Ковалёва', 'user@food-guru.local', UserRole::User],
        ];

        $users = [];

        foreach ($staff as [$name, $email, $role]) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'is_active' => true,
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();

            $users[$role->value] = $user;
        }

        // Неподтверждённый аккаунт — чтобы было на чём проверять экран подтверждения.
        User::create([
            'name' => 'Сергей Данилов',
            'email' => 'pending@food-guru.local',
            'role' => UserRole::User,
        ]);

        $categories = collect([
            'Завтраки', 'Супы', 'Горячее', 'Салаты', 'Выпечка', 'Десерты',
        ])->map(fn ($title, $i) => Category::create([
            'slug' => Str::slug(Str::ascii($title)) ?: 'category-'.$i,
            'title' => $title,
            'position' => $i,
        ]));

        $tags = collect([
            'вегетарианское', 'без глютена', 'до 30 минут', 'на ужин', 'сыр', 'грузинская кухня',
        ])->map(fn ($title, $i) => Tag::create([
            'slug' => Str::slug(Str::ascii($title)) ?: 'tag-'.$i,
            'title' => $title,
        ]));

        // Настоящие рецепты из прототипа: ингредиенты, шаги, подсказки.
        // Выгружаются командой `node tools/dishes/export_recipes.mjs`.
        $samples = json_decode(
            file_get_contents(database_path('seeders/assets/recipes.json')), true, flags: JSON_THROW_ON_ERROR
        );

        // Содержимое вкладок шагов (совет, замена, секрет шефа) — отдельным файлом:
        // recipes.json перезаписывается выгрузкой из прототипа, а этих текстов там нет.
        $stepTabs = json_decode(
            file_get_contents(database_path('seeders/assets/step-tabs.json')), true, flags: JSON_THROW_ON_ERROR
        );

        foreach ($samples as $i => $sample) {
            $status = RecipeStatus::from($sample['status']);

            $recipe = Recipe::create([
                'author_id' => $users[UserRole::ContentManager->value]->id,
                'category_id' => $categories->firstWhere('title', $sample['category'])?->id,
                'slug' => $sample['slug'],
                'title' => $sample['title'],
                'lead' => $sample['lead'],
                'time_minutes' => $sample['minutes'],
                'servings' => $sample['servings'],
                'difficulty' => $sample['difficulty'],
                'status' => $status,
                'published_at' => $status === RecipeStatus::Published ? now()->subDays($i) : null,
                'og_description' => $sample['title'].' — пошаговый рецепт.',
                'calories' => $sample['nutrition']['calories'],
                'protein' => $sample['nutrition']['protein'],
                'fat' => $sample['nutrition']['fat'],
                'carbs' => $sample['nutrition']['carbs'],
                'is_vegetarian' => $sample['vegetarian'],
                'is_gluten_free' => $sample['gluten_free'],
                'moderation_note' => $status === RecipeStatus::Rejected
                    ? 'Уточните граммовку маскарпоне и добавьте фото финального шага.'
                    : null,
            ]);

            $recipe->tags()->attach($this->tagIds($sample['tags']));

            // У рецептов на модерации и в черновиках текста в прототипе нет —
            // для них остаются заглушки, чтобы редактору было что открыть.
            $ingredients = $sample['ingredients'] ?: collect(range(1, 4))->map(fn ($n) => [
                'name' => 'Ингредиент '.$n, 'amount' => 50 * $n / $sample['servings'], 'unit' => 'г',
            ])->all();
            $steps = $sample['steps'] ?: collect(range(1, 3))->map(fn ($n) => [
                'body' => 'Шаг '.$n.'. Текст-заглушка для разработки.', 'hint' => null,
            ])->all();

            foreach ($ingredients as $position => $row) {
                $recipe->ingredients()->create([
                    'name' => $row['name'],
                    'amount' => $row['amount'],
                    'unit' => $row['unit'],
                    'position' => $position + 1,
                ]);
            }

            foreach ($steps as $position => $row) {
                $tabs = $stepTabs[$sample['slug']][(string) ($position + 1)] ?? [];

                $recipe->steps()->create([
                    'position' => $position + 1,
                    'body' => $row['body'],
                    'hint' => $row['hint'],
                    'tip' => $tabs['tip'] ?? null,
                    'substitution' => $tabs['substitution'] ?? null,
                    'chef_secret' => $tabs['chef_secret'] ?? null,
                ]);
            }

            $this->attachDishArt($recipe, $users[UserRole::ContentManager->value]);
            $this->attachReel($recipe, $sample['reel']);
        }

        // Пара рецептов в избранном у обычного пользователя.
        $users[UserRole::User->value]->favorites()->attach(
            Recipe::published()->take(3)->pluck('id'),
            ['created_at' => now()]
        );

        $this->seedCollections();
        $this->seedReviews($users[UserRole::User->value]);

        // Читатели с сохранениями, оценками и отзывами — для блоков главной.
        $this->call(CommunitySeeder::class);
        // Книги, магазин, закрытая академия, 3 000 ₽ демо-читателю.
        $this->call(CommerceSeeder::class);

        // Конкурсы, академия и баллы живут в своём сидере — он длиннее этого.
        $this->call(ContestsAcademySeeder::class);
        $this->call(JournalSeeder::class);
    }

    /** @param  list<string>  $titles */
    private function tagIds(array $titles): array
    {
        return collect($titles)->map(fn ($title) => Tag::firstOrCreate(
            ['slug' => Str::slug(Str::ascii($title)) ?: Str::random(8)],
            ['title' => $title],
        )->id)->all();
    }

    /**
     * Обложка и два кадра галереи («ингредиенты», «в процессе») из нарисованных
     * иллюстраций. Файлы делает `node tools/dishes/make_dishes.mjs`; если для
     * рецепта их нет, он просто остаётся без картинок.
     */
    private function attachDishArt(Recipe $recipe, User $uploader): void
    {
        $dir = database_path('seeders/assets/dishes');
        $frames = [
            'cover' => 'Готовое блюдо',
            'ingredients' => 'Ингредиенты',
            'process' => 'В процессе',
        ];

        $gallery = [];

        foreach ($frames as $kind => $caption) {
            $source = "{$dir}/{$recipe->slug}-{$kind}.svg";

            if (! is_file($source)) {
                continue;
            }

            $path = "dishes/{$recipe->slug}-{$kind}.svg";
            Storage::disk('public')->put($path, file_get_contents($source));

            $media = Media::create([
                'uploaded_by' => $uploader->id,
                'path' => $path,
                'disk' => 'public',
                'width' => 1200,
                'height' => 900,
                'size' => filesize($source),
                'mime' => 'image/svg+xml',
                'alt' => "{$recipe->title} — {$caption}",
            ]);

            if ($kind === 'cover') {
                $recipe->forceFill(['cover_media_id' => $media->id])->save();
            } else {
                $gallery[$media->id] = ['caption' => $caption, 'position' => count($gallery)];
            }
        }

        if ($gallery) {
            $recipe->gallery()->attach($gallery);
        }
    }

    /**
     * Видеорецепт из assets/reels: сначала <slug>.mp4, затем файл под именем
     * из прототипа. Нет файла — нет ролика, рецепт от этого не страдает.
     */
    private function attachReel(Recipe $recipe, ?string $named): void
    {
        $dir = database_path('seeders/assets/reels');

        foreach (array_filter(["{$recipe->slug}.mp4", $named]) as $file) {
            if (! is_file("{$dir}/{$file}")) {
                continue;
            }

            $path = "reels/{$file}";
            Storage::disk('public')->put($path, file_get_contents("{$dir}/{$file}"));
            $recipe->forceFill(['reel_path' => $path])->save();

            return;
        }
    }

    private function seedCollections(): void
    {
        $sets = [
            ['Что приготовить в октябре', 'oktyabr',
                'Тыква, грибы и чечевица — всё, что сейчас стоит дёшево и пахнет как надо.',
                ['Тыквенный крем-суп с хрустящим нутом', 'Грибной суп со сливками', 'Чечевичный суп с томатами']],
            ['Ужин на одной сковороде', 'odna-skovoroda',
                'Когда мыть три кастрюли нет сил. Всё готовится в одной посуде.',
                ['Шакшука с фетой', 'Бёдра в медово-горчичном соусе', 'Паста качо-э-пепе']],
            ['Завтраки выходного дня', 'zavtraki',
                'То, ради чего стоит встать на полчаса раньше.',
                ['Сырники на рисовой муке', 'Шакшука с фетой']],
            ['Паста на ужин за полчаса', 'pasta-na-uzhin',
                'Пока варятся спагетти, готов и соус.',
                ['Паста с креветками в томатно-сливочном соусе', 'Паста качо-э-пепе']],
            ['Без глютена и без сложностей', 'bez-glyutena',
                'Обычная домашняя еда, в которой просто нет пшеничной муки.',
                ['Сырники на рисовой муке', 'Киноа с печёной свёклой и фетой', 'Бёдра в медово-горчичном соусе', 'Чечевичный суп с томатами']],
            ['Сладкое, ради которого стоит включить духовку', 'sladkoe',
                'Десерты с граммовками до грамма — чтобы получилось с первого раза.',
                ['Шоколадный фондан с жидким центром', 'Сырники на рисовой муке']],
        ];

        foreach ($sets as [$title, $slug, $lead, $titles]) {
            $collection = Collection::create([
                'slug' => $slug,
                'title' => $title,
                'lead' => $lead,
                'is_published' => true,
            ]);

            $ids = Recipe::whereIn('title', $titles)->pluck('id')->all();
            $collection->recipes()->attach(
                collect($ids)->mapWithKeys(fn ($id, $i) => [$id => ['position' => $i]])->all()
            );
        }
    }

    private function seedReviews(User $reader): void
    {
        $samples = [
            ['Тыквенный крем-суп с хрустящим нутом', 5,
                'Нут действительно стоит обжарить отдельно — без него суп получается скучным.'],
            ['Паста качо-э-пепе', 4,
                'С первого раза сыр свернулся комками. Со второго понял: снимать с огня до того, как добавлять.'],
            ['Сырники на рисовой муке', 5,
                'Протереть творог через сито — шаг, который правда нельзя пропускать.'],
        ];

        foreach ($samples as [$title, $rating, $body]) {
            $recipe = Recipe::where('title', $title)->first();
            if (! $recipe) {
                continue;
            }

            Review::create([
                'recipe_id' => $recipe->id,
                'user_id' => $reader->id,
                'rating' => $rating,
                'body' => $body,
                'status' => 'published',
            ]);
        }
    }
}
