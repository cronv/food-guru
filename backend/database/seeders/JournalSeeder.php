<?php

namespace Database\Seeders;

use App\Enums\JournalPeriod;
use App\Enums\PublishStatus;
use App\Enums\UserRole;
use App\Models\JournalIssue;
use App\Models\User;
use App\Services\JournalFileService;
use App\Support\Slug;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Демонстрационные выпуски журнала.
 *
 * PDF собирается прямо здесь, байт за байтом: тянуть ради витрины
 * библиотеку вёрстки ни к чему, а пустой раздел ничего не показывает.
 * Файлы — заглушки с латинским текстом: встроенные шрифты PDF кириллицу
 * не содержат, а подключать свой шрифт ради демо не стоит.
 */
class JournalSeeder extends Seeder
{
    public function run(): void
    {
        $editor = User::where('role', UserRole::EditorLead)->first()
            ?? User::where('role', UserRole::Admin)->first();

        if (! $editor) {
            return;
        }

        $files = app(JournalFileService::class);

        $samples = [
            ['Гайд месяца: что готовить в январе', JournalPeriod::Month, '2026-01-01',
                'Сезонные продукты, пять рецептов и список покупок на месяц.',
                "Январь — месяц корнеплодов и цитрусов.\n\nВ выпуске: что брать на рынке, как хранить, пять рецептов на неделю вперёд и список покупок, который можно распечатать."],
            ['Гайд недели: супы на бульоне', JournalPeriod::Week, '2026-01-12',
                'Как сварить бульон, который не надо спасать специями.',
                "Разбираем бульон по шагам: кости, вода, температура, время.\n\nВ конце — три супа на одной основе."],
            ['Гайд недели: тесто без машинки', JournalPeriod::Week, '2026-01-19',
                'Пропорции, замес руками и признаки готового теста.',
                'Короткий выпуск на шесть страниц: пропорции, замес, отдых, раскатка.'],
            ['Гайд месяца: заготовки на зиму', JournalPeriod::Month, '2025-11-01',
                'Что закрывать в ноябре и сколько это хранится.',
                'Выпуск прошлого года — остаётся в архиве и открывается фильтром по году.'],
        ];

        foreach ($samples as [$title, $period, $date, $lead, $description]) {
            $issue = new JournalIssue([
                'author_id' => $editor->id,
                'slug' => Slug::unique(JournalIssue::class, $title, 'issue'),
                'title' => $title,
                'lead' => $lead,
                'description' => $description,
                'period' => $period,
                'issue_date' => Carbon::parse($date),
                'status' => PublishStatus::Draft,
            ]);

            $issue->syncPeriodColumns();
            $issue->save();

            $path = $this->writeDemoPdf($title, $period, Carbon::parse($date));

            $files->attach(
                $issue,
                new UploadedFile($path, 'guide.pdf', 'application/pdf', null, true),
                $editor,
            );

            @unlink($path);

            $issue->forceFill([
                'status' => PublishStatus::Published,
                'published_at' => Carbon::parse($date)->addDays(2),
            ])->save();
        }

        // Черновик — чтобы в админке было что доводить до публикации.
        $draft = new JournalIssue([
            'author_id' => $editor->id,
            'slug' => Slug::unique(JournalIssue::class, 'Гайд недели: ферментация', 'issue'),
            'title' => 'Гайд недели: ферментация',
            'lead' => 'Готовится к публикации.',
            'period' => JournalPeriod::Week,
            'issue_date' => Carbon::parse('2026-02-02'),
            'status' => PublishStatus::Draft,
        ]);

        $draft->syncPeriodColumns();
        $draft->save();
    }

    /**
     * Минимальный одностраничный PDF 1.4 на A4.
     * Смещения объектов считаются по мере сборки — таблица xref обязана
     * указывать на реальные байты, иначе просмотрщики файл не примут.
     */
    private function writeDemoPdf(string $title, JournalPeriod $period, Carbon $date): string
    {
        $heading = $period === JournalPeriod::Month ? 'GUIDE OF THE MONTH' : 'GUIDE OF THE WEEK';
        $stamp = $date->format('d.m.Y');

        $content = <<<PDF
        0.886 0.251 0.122 rg
        0 672 595 170 re f
        1 1 1 rg
        BT /F1 34 Tf 56 742 Td (FOOD GURU) Tj ET
        BT /F1 15 Tf 56 706 Td ($heading) Tj ET
        0.165 0.110 0.078 rg
        BT /F1 22 Tf 56 600 Td ($stamp) Tj ET
        BT /F1 13 Tf 56 560 Td (Demo issue. Real guides are uploaded by the editors.) Tj ET
        0.118 0.541 0.341 rg
        56 120 180 8 re f
        PDF;

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                .'/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            4 => '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xrefAt = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        foreach ($objects as $number => $_) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }

        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefAt}\n%%EOF\n";

        /*
         * tempnam() сам создаёт файл, поэтому пишем ровно в него, а не в
         * «путь + .pdf»: иначе исходный пустой файл остался бы в /tmp навсегда.
         * Расширение здесь роли не играет — UploadedFile получает имя отдельно.
         */
        $path = tempnam(sys_get_temp_dir(), 'journal');
        file_put_contents($path, $pdf);

        return $path;
    }
}
