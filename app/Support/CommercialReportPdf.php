<?php

namespace App\Support;

class CommercialReportPdf
{
    private const WIDTH = 841.89;

    private const HEIGHT = 595.28;

    private string $stream = '';

    private float $y = 0;

    /**
     * @param  array{inicio: string, fim: string, unidade_id: ?int, vendedor_id: ?int, unidade: string, vendedor: string}  $filters
     * @param  list<array{0: string, 1: list<array<string, int|string>>}>  $groups
     */
    public function generate(array $filters, array $groups): string
    {
        $pages = [];
        $this->startPage($filters);

        foreach ($groups as [$title, $rows]) {
            if ($this->y < 90) {
                $pages[] = $this->stream;
                $this->startPage($filters);
            }

            $this->section($title);
            $this->tableHeader();

            foreach ($rows as $row) {
                if ($this->y < 48) {
                    $pages[] = $this->stream;
                    $this->startPage($filters);
                    $this->stream .= $this->text(
                        '0.04 0.30 0.57',
                        35,
                        $this->y,
                        10,
                        true,
                        $title.' (continuação)',
                    );
                    $this->y -= 23;
                    $this->tableHeader();
                }

                $this->tableRow($row);
            }

            $this->y -= 16;
        }

        $pages[] = $this->stream;

        return $this->encodePdf($pages);
    }

    /**
     * @param  array{inicio: string, fim: string, unidade_id: ?int, vendedor_id: ?int, unidade: string, vendedor: string}  $filters
     */
    private function startPage(array $filters): void
    {
        $this->stream = $this->rectangle(0, self::HEIGHT - 66, self::WIDTH, 66, '0.035 0.29 0.55');
        $this->stream .= $this->text('1 1 1', 35, self::HEIGHT - 38, 17, true, 'LICEU  |  RELATORIO COMERCIAL');
        $this->stream .= $this->text(
            '1 1 1',
            self::WIDTH - 210,
            self::HEIGHT - 38,
            9,
            false,
            'Gerado em '.now()->format('d/m/Y H:i'),
        );
        $this->stream .= $this->text('0.13 0.20 0.30', 35, self::HEIGHT - 88, 9, false, 'Periodo: '.$filters['inicio'].' ate '.$filters['fim']);
        $this->stream .= $this->text('0.13 0.20 0.30', 35, self::HEIGHT - 105, 9, false, 'Unidade: '.$filters['unidade']);
        $this->stream .= $this->text('0.13 0.20 0.30', 425, self::HEIGHT - 105, 9, false, 'Vendedor: '.$filters['vendedor']);
        $this->y = self::HEIGHT - 135;
    }

    private function section(string $title): void
    {
        $this->stream .= $this->rectangle(35, $this->y - 7, self::WIDTH - 70, 24, '0.92 0.95 0.98');
        $this->stream .= $this->text('0.04 0.30 0.57', 45, $this->y, 10, true, $title);
        $this->y -= 34;
    }

    private function tableHeader(): void
    {
        $this->stream .= $this->rectangle(35, $this->y - 7, self::WIDTH - 70, 21, '0.89 0.93 0.97');
        $labels = ['Unidade / Vendedor', 'Leads', 'Agend.', 'Visitas', 'Matric.', 'L/A', 'A/V', 'V/M', 'L/M'];
        $this->writeCells($labels, true);
        $this->y -= 24;
    }

    /**
     * @param  array<string, int|string>  $row
     */
    private function tableRow(array $row): void
    {
        $lead = (int) $row['leads'];
        $appointments = (int) $row['appointments'];
        $visits = (int) $row['visits'];
        $sales = (int) $row['sales'];
        $cells = [
            (string) $row['label'],
            (string) $lead,
            (string) $appointments,
            (string) $visits,
            (string) $sales,
            $this->percentage($appointments, $lead),
            $this->percentage($visits, $appointments),
            $this->percentage($sales, $visits),
            $this->percentage($sales, $lead),
        ];
        $this->stream .= $this->rectangle(35, $this->y - 7, self::WIDTH - 70, 21, '0.975 0.98 0.99');
        $this->writeCells($cells, false);
        $this->y -= 23;
    }

    /**
     * @param  list<string>  $cells
     */
    private function writeCells(array $cells, bool $bold): void
    {
        $widths = [206, 57, 65, 65, 65, 65, 65, 65, 65];
        $x = 40;
        foreach ($widths as $index => $width) {
            $this->stream .= $this->text(
                '0.13 0.20 0.30',
                $x,
                $this->y,
                $index === 0 ? 8 : 7.5,
                $bold,
                (string) ($cells[$index] ?? ''),
                $index === 0 ? 34 : 9,
            );
            $x += $width;
        }
    }

    private function percentage(int $value, int $total): string
    {
        return $total > 0
            ? number_format(($value / $total) * 100, 1, ',', '.').'%'
            : '0,0%';
    }

    private function text(
        string $color,
        float $x,
        float $y,
        float $size,
        bool $bold,
        string $value,
        int $maxLength = 100,
    ): string {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        $encoded = substr($encoded === false ? '' : $encoded, 0, $maxLength);
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);

        return sprintf(
            "%s rg BT /%s %.1f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET\n",
            $color,
            $bold ? 'F2' : 'F1',
            $size,
            $x,
            $y,
            $escaped,
        );
    }

    private function rectangle(float $x, float $y, float $width, float $height, string $fill): string
    {
        return sprintf("%s rg %.2f %.2f %.2f %.2f re f\n", $fill, $x, $y, $width, $height);
    }

    /**
     * @param  list<string>  $pages
     */
    private function encodePdf(array $pages): string
    {
        $objects = [];
        $add = static function (string $object) use (&$objects): int {
            $objects[] = $object;

            return count($objects);
        };
        $catalogId = $add('');
        $pagesId = $add('');
        $regularFontId = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $boldFontId = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $pageIds = [];

        foreach ($pages as $index => $content) {
            $content .= $this->text(
                '0.36 0.43 0.51',
                self::WIDTH - 100,
                20,
                8,
                false,
                'Pagina '.($index + 1).' / '.count($pages),
            );
            $contentId = $add('<< /Length '.strlen($content)." >>\nstream\n".$content.'endstream');
            $pageIds[] = $add(
                '<< /Type /Page /Parent '.$pagesId.' 0 R /MediaBox [0 0 '.self::WIDTH.' '.self::HEIGHT.'] '
                .'/Resources << /Font << /F1 '.$regularFontId.' 0 R /F2 '.$boldFontId.' 0 R >> >> '
                .'/Contents '.$contentId.' 0 R >>',
            );
        }

        $objects[$pagesId - 1] = '<< /Type /Pages /Kids ['.implode(' ', array_map(
            static fn (int $id): string => $id.' 0 R',
            $pageIds,
        )).'] /Count '.count($pageIds).' >>';
        $objects[$catalogId - 1] = '<< /Type /Catalog /Pages '.$pagesId.' 0 R >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root $catalogId 0 R >>\n";
        $pdf .= "startxref\n$xrefOffset\n%%EOF";

        return $pdf;
    }
}
