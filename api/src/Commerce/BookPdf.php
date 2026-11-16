<?php

declare(strict_types=1);

namespace App\Commerce;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * PDF книги из глав. Платная книга получает водяной знак
 * с почтой покупателя на каждой странице: разошедшийся по сети файл можно
 * отследить, а честному читателю он не мешает.
 */
final class BookPdf
{
    /**
     * @param array<string, mixed>       $book
     * @param list<array<string, mixed>> $chapters по порядку
     */
    public function render(array $book, array $chapters, ?string $buyerEmail): string
    {
        $options = new Options();
        // Никаких внешних ресурсов: всё, что нужно, — в самом HTML.
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->html($book, $chapters), 'UTF-8');
        $pdf->setPaper('A5');
        $pdf->render();

        $canvas = $pdf->getCanvas();
        // DejaVu идёт в комплекте dompdf — без него кириллица не отрисуется.
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans') ?? throw new \RuntimeException('В dompdf нет шрифта DejaVu Sans.');
        $mark = 0 !== (int) $book['price'] && null !== $buyerEmail ? "Куплено: {$buyerEmail}" : 'Food Guru';
        $canvas->page_text(36, $canvas->get_height() - 28, $mark.' · {PAGE_NUM}/{PAGE_COUNT}', $font, 7, [0.45, 0.4, 0.36]);

        return (string) $pdf->output();
    }

    /**
     * @param array<string, mixed>       $book
     * @param list<array<string, mixed>> $chapters
     */
    private function html(array $book, array $chapters): string
    {
        $e = static fn (mixed $s): string => htmlspecialchars((string) $s, \ENT_QUOTES, 'UTF-8');
        $body = '';
        foreach ($chapters as $chapter) {
            $paragraphs = array_filter(array_map('trim', preg_split('/\n{2,}/u', (string) $chapter['body']) ?: []));
            $text = implode('', array_map(static fn (string $p): string => '<p>'.nl2br($e($p)).'</p>', $paragraphs));
            $body .= '<h2>'.$e($chapter['title']).'</h2>'.$text;
        }

        return <<<HTML
            <!doctype html>
            <html lang="ru"><head><meta charset="utf-8">
            <style>
              @page { margin: 48px 36px 52px; }
              body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5pt; line-height: 1.55; color: #2A1C14; }
              .title { page-break-after: always; padding-top: 140px; }
              .title h1 { font-size: 24pt; line-height: 1.15; margin: 0 0 12px; }
              .title p { color: #6B5748; }
              h2 { font-size: 15pt; page-break-before: always; margin: 0 0 14px; }
              h2:first-of-type { page-break-before: avoid; }
              p { margin: 0 0 9px; text-align: justify; }
            </style></head>
            <body>
              <div class="title"><h1>{$e($book['title'])}</h1><p>{$e($book['author_name'])}</p><p>Food Guru</p></div>
              {$body}
            </body></html>
            HTML;
    }
}
