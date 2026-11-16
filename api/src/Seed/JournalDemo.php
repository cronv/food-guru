<?php

declare(strict_types=1);

namespace App\Seed;

use App\Admin\JournalFiles;
use App\Enum\JournalPeriod;
use App\Enum\PublishStatus;
use App\Support\Slug;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Демонстрационные выпуски журнала.
 *
 * PDF собирается здесь же, байт за байтом: библиотека вёрстки ради витрины не
 * нужна. Файлы — заглушки с латинским текстом: встроенные шрифты PDF кириллицу
 * не содержат.
 */
final class JournalDemo
{
    public function __construct(
        private readonly Connection $db,
        private readonly DemoStore $store,
        private readonly JournalFiles $files,
    ) {
    }

    public function seed(): void
    {
        $editorId = $this->editorId();
        if (null === $editorId) {
            return;
        }

        $samples = [
            ['Гайд месяца: что готовить в январе', JournalPeriod::Month, '2026-01-01',
                'Сезонные продукты, пять рецептов и список покупок на месяц.',
                "Январь — месяц корнеплодов и цитрусов.\n\nВ выпуске: что брать на рынке, как хранить, пять рецептов на неделю вперёд и список покупок, который можно распечатать.", PublishStatus::Published],
            ['Гайд недели: супы на бульоне', JournalPeriod::Week, '2026-01-12',
                'Как сварить бульон, который не надо спасать специями.',
                "Разбираем бульон по шагам: кости, вода, температура, время.\n\nВ конце — три супа на одной основе.", PublishStatus::Published],
            ['Гайд недели: тесто без машинки', JournalPeriod::Week, '2026-01-19',
                'Пропорции, замес руками и признаки готового теста.',
                'Короткий выпуск на шесть страниц: пропорции, замес, отдых, раскатка.', PublishStatus::Published],
            ['Гайд месяца: заготовки на зиму', JournalPeriod::Month, '2025-11-01',
                'Что закрывать в ноябре и сколько это хранится.',
                'Выпуск прошлого года — остаётся в архиве и открывается фильтром по году.', PublishStatus::Published],
        ];

        foreach ($samples as [$title, $period, $date, $lead, $description, $status]) {
            $issueId = $this->insertIssue($editorId, $title, $period, $date, $lead, $description);

            $path = $this->writeDemoPdf($period, $date);
            $issue = $this->db->fetchAssociative('SELECT * FROM journal_issues WHERE id = ?', [$issueId]);
            if (false === $issue) {
                throw new \RuntimeException("Выпуск журнала {$issueId} не найден после вставки.");
            }
            $this->files->attach($issue, new UploadedFile($path, 'guide.pdf', 'application/pdf', null, true), $editorId);

            $this->db->update('journal_issues', [
                'status' => $status->value,
                'published_at' => (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d H:i:s'),
            ], ['id' => $issueId]);
        }

        // Черновик — чтобы в админке было что доводить до публикации.
        $this->insertIssue($editorId, 'Гайд недели: ферментация', JournalPeriod::Week, '2026-02-02', 'Готовится к публикации.', null);
    }

    private function editorId(): ?int
    {
        $id = $this->db->fetchOne("SELECT id FROM users WHERE role = 'editor_lead' ORDER BY id LIMIT 1")
            ?: $this->db->fetchOne("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");

        return false === $id ? null : (int) $id;
    }

    private function insertIssue(int $authorId, string $title, JournalPeriod $period, string $date, string $lead, ?string $description): int
    {
        $issueDate = $this->normalize(new \DateTimeImmutable($date, new \DateTimeZone('UTC')), $period);
        $now = $this->store->at();

        $this->db->insert('journal_issues', [
            'author_id' => $authorId,
            'slug' => Slug::make($title) ?: 'issue',
            'title' => $title,
            '`lead`' => $lead,
            'description' => $description,
            'period' => $period->value,
            'issue_date' => $issueDate->format('Y-m-d'),
            'issue_year' => (int) $issueDate->format('Y'),
            'issue_month' => (int) $issueDate->format('n'),
            'status' => PublishStatus::Draft->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Любую дату внутри периода приводим к его началу — как JournalPeriod::normalize. */
    private function normalize(\DateTimeImmutable $date, JournalPeriod $period): \DateTimeImmutable
    {
        return JournalPeriod::Week === $period
            ? $date->modify('-'.((int) $date->format('N') - 1).' days')
            : $date->modify('first day of this month');
    }

    /**
     * Минимальный одностраничный PDF 1.4 на A4. Смещения объектов считаются по мере
     * сборки: таблица xref обязана указывать на реальные байты.
     */
    private function writeDemoPdf(JournalPeriod $period, string $date): string
    {
        $heading = JournalPeriod::Month === $period ? 'GUIDE OF THE MONTH' : 'GUIDE OF THE WEEK';
        $stamp = (new \DateTimeImmutable($date))->format('d.m.Y');

        $content = implode("\n", [
            '0.886 0.251 0.122 rg',
            '0 672 595 170 re f',
            '1 1 1 rg',
            'BT /F1 34 Tf 56 742 Td (FOOD GURU) Tj ET',
            "BT /F1 15 Tf 56 706 Td ({$heading}) Tj ET",
            '0.165 0.110 0.078 rg',
            "BT /F1 22 Tf 56 600 Td ({$stamp}) Tj ET",
            'BT /F1 13 Tf 56 560 Td (Demo issue. Real guides are uploaded by the editors.) Tj ET',
            '0.118 0.541 0.341 rg',
            '56 120 180 8 re f',
        ]);

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                .'/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            4 => '<< /Length '.\strlen($content)." >>\nstream\n{$content}\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = \strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xrefAt = \strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(\count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        foreach (array_keys($objects) as $number) {
            $pdf .= \sprintf("%010d 00000 n \n", $offsets[$number]);
        }
        $pdf .= 'trailer'."\n".'<< /Size '.(\count($objects) + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefAt}\n%%EOF\n";

        // tempnam создаёт файл сам — пишем в него, чтобы пустой файл не остался в tmp.
        $path = tempnam(sys_get_temp_dir(), 'journal');
        if (false === $path) {
            throw new \RuntimeException('Не удалось создать временный файл для PDF журнала.');
        }
        file_put_contents($path, $pdf);

        return $path;
    }
}
