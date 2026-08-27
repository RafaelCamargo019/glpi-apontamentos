<?php

/**
 * Gerador PDF pequeno e autocontido. Usa somente recursos básicos do formato
 * PDF e fontes internas, evitando dependências externas no servidor GLPI.
 */
class PluginApontamentosReportPdf
{
    private const WIDTH = 842;
    private const HEIGHT = 595;
    private array $pages = [];
    private string $content = '';

    public static function render(array $report): string
    {
        $pdf = new self();
        $pdf->drawOverview($report);
        $pdf->drawConsolidated($report);
        return $pdf->buildDocument();
    }

    private function drawOverview(array $report): void
    {
        $this->newPage();
        $filters = $report['filters'];
        $this->text(32, 35, 'Relatório gerencial de produtividade', 18, true, [0.08, 0.16, 0.29]);
        $period = 'Período: ' . $filters['start']->format('d/m/Y') . ' a ' . $filters['end']->format('d/m/Y');
        $this->text(32, 56, $period, 10, false, [0.34, 0.39, 0.47]);
        $this->text(610, 56, 'Gerado em: ' . date('d/m/Y H:i'), 9, false, [0.34, 0.39, 0.47]);
        $this->text(32, 71, self::truncate((string) ($report['filterText'] ?? ''), 135), 8, false, [0.34, 0.39, 0.47]);

        $cards = [
            ['Jornada esperada', PluginApontamentosReport::formatMinutes($report['totals']['expected']), [0.42, 0.45, 0.49]],
            ['Horas apontadas', PluginApontamentosReport::formatMinutes($report['totals']['pointed']), [0.13, 0.42, 0.77]],
            ['Horas não apontadas', PluginApontamentosReport::formatMinutes($report['totals']['missing']), [0.96, 0.53, 0.10]],
            ['Horas excedentes', PluginApontamentosReport::formatMinutes($report['totals']['extra']), [0.45, 0.25, 0.72]],
            ['Ocupação', $report['totals']['occupation'] === null ? 'Não configurada' : $report['totals']['occupation'] . '%', [0.18, 0.70, 0.27]],
            ['Apontamentos', (string) $report['totals']['count'], [0.42, 0.45, 0.49]],
            ['Usuários', (string) $report['totals']['operators'], [0.42, 0.45, 0.49]],
        ];
        foreach ($cards as $index => [$label, $value, $color]) {
            $column = $index % 4;
            $row = intdiv($index, 4);
            $x = 32 + ($column * 195);
            $y = 88 + ($row * 62);
            $this->rect($x, $y, 180, 50, [0.97, 0.98, 0.99]);
            $this->rect($x, $y, 4, 50, $color);
            $this->text($x + 12, $y + 17, $label, 8, false, [0.34, 0.39, 0.47]);
            $this->text($x + 12, $y + 37, $value, 13, true, $color);
        }

        $this->text(32, 225, 'Jornada versus horas apontadas', 12, true, [0.08, 0.16, 0.29]);
        $daily = [];
        foreach ($report['summary'] as $line) {
            $date = $line['dateKey'];
            $daily[$date] ??= ['expected' => 0, 'pointed' => 0];
            $daily[$date]['expected'] += (int) ($line['expectedMinutes'] ?? 0);
            $daily[$date]['pointed'] += $line['pointedMinutes'];
        }
        $visibleDaily = array_slice($daily, 0, 31, true);
        $max = 1;
        foreach ($visibleDaily as $values) $max = max($max, $values['expected'], $values['pointed']);
        $chartX = 40; $chartY = 245; $chartWidth = 470; $chartHeight = 135;
        $this->line($chartX, $chartY + $chartHeight, $chartX + $chartWidth, $chartY + $chartHeight, [0.78, 0.80, 0.83]);
        $count = max(1, count($visibleDaily));
        $slot = $chartWidth / $count;
        foreach (array_values($visibleDaily) as $index => $values) {
            $expectedHeight = ($values['expected'] / $max) * ($chartHeight - 18);
            $pointedHeight = ($values['pointed'] / $max) * ($chartHeight - 18);
            $x = $chartX + ($slot * $index) + 2;
            $barWidth = max(2, min(8, ($slot - 4) / 2));
            $this->rect($x, $chartY + $chartHeight - $expectedHeight, $barWidth, $expectedHeight, [0.62, 0.64, 0.68]);
            $this->rect($x + $barWidth + 1, $chartY + $chartHeight - $pointedHeight, $barWidth, $pointedHeight, [0.13, 0.42, 0.77]);
        }
        $this->text(40, 395, 'Cinza: jornada esperada    Azul: horas apontadas', 8, false, [0.34, 0.39, 0.47]);

        $this->text(545, 225, 'Distribuição por tipo', 12, true, [0.08, 0.16, 0.29]);
        $y = 248;
        foreach (array_slice(array_values($report['types']), 0, 8) as $type) {
            $color = self::hexColor((string) $type['color']);
            $this->rect(548, $y - 9, 9, 9, $color);
            $this->text(564, $y, self::truncate((string) $type['name'], 25), 9, false, [0.08, 0.16, 0.29]);
            $this->text(730, $y, PluginApontamentosReport::formatMinutes((int) $type['minutes']) . ' (' . $type['percent'] . '%)', 8, true, $color);
            $this->rect(564, $y + 7, 220, 5, [0.92, 0.93, 0.94]);
            $this->rect(564, $y + 7, 2.2 * min(100, (float) $type['percent']), 5, $color);
            $y += 30;
        }

        if ($report['overlapIds'] !== []) {
            $this->rect(32, 520, 778, 26, [1.00, 0.95, 0.82]);
            $this->text(42, 537, 'Aviso: existem registros históricos sobrepostos; minutos duplicados foram desconsiderados.', 9, true, [0.55, 0.35, 0.02]);
        }
    }

    private function drawConsolidated(array $report): void
    {
        $rowsPerPage = 18;
        $chunks = array_chunk($report['summary'], $rowsPerPage);
        if ($chunks === []) $chunks = [[]];
        foreach ($chunks as $pageIndex => $rows) {
            $this->newPage();
            $this->text(32, 35, 'Consolidado por usuário e data', 16, true, [0.08, 0.16, 0.29]);
            $this->text(32, 53, 'Horas não apontadas representam a diferença calculada para a jornada diária.', 8, false, [0.34, 0.39, 0.47]);
            $headers = ['Usuário', 'Data', 'Jornada', 'Apontadas', 'Não apont.', 'Exced.', 'Ocup.', 'Qtd.', 'Situação'];
            $widths = [150, 68, 82, 82, 82, 70, 58, 40, 110];
            $x = 32; $y = 78;
            foreach ($headers as $index => $header) {
                $this->rect($x, $y, $widths[$index], 22, [0.13, 0.42, 0.77]);
                $this->text($x + 4, $y + 15, $header, 8, true, [1, 1, 1]);
                $x += $widths[$index];
            }
            $y += 22;
            foreach ($rows as $rowIndex => $row) {
                $x = 32;
                $background = $rowIndex % 2 === 0 ? [0.98, 0.98, 0.99] : [0.94, 0.95, 0.96];
                $values = [
                    self::truncate((string) $row['name'], 25),
                    (new DateTimeImmutable($row['dateKey']))->format('d/m/Y'),
                    PluginApontamentosReport::formatMinutes($row['expectedMinutes']),
                    PluginApontamentosReport::formatMinutes($row['pointedMinutes']),
                    PluginApontamentosReport::formatMinutes($row['missingMinutes']),
                    PluginApontamentosReport::formatMinutes($row['extraMinutes']),
                    $row['occupation'] === null ? '-' : $row['occupation'] . '%',
                    (string) $row['count'],
                    PluginApontamentosReport::statusLabel($row['state']),
                ];
                foreach ($values as $index => $value) {
                    $this->rect($x, $y, $widths[$index], 23, $background);
                    $this->text($x + 4, $y + 15, self::truncate($value, max(5, (int) ($widths[$index] / 5.5))), 7.5, false, [0.08, 0.16, 0.29]);
                    $x += $widths[$index];
                }
                $y += 23;
            }
            if ($rows === []) {
                $this->text(36, 120, 'Nenhum dado encontrado para os filtros selecionados.', 10, false, [0.34, 0.39, 0.47]);
            }
        }
    }

    private function newPage(): void
    {
        if ($this->content !== '') $this->pages[] = $this->content;
        $this->content = "1 1 1 rg 0 0 " . self::WIDTH . ' ' . self::HEIGHT . " re f\n";
    }

    private function text(float $x, float $top, string $text, float $size = 10, bool $bold = false, array $color = [0, 0, 0]): void
    {
        $font = $bold ? '/F2' : '/F1';
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        $encoded = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], (string) $encoded);
        $this->content .= sprintf(
            "%.3F %.3F %.3F rg BT %s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
            $color[0], $color[1], $color[2], $font, $size, $x, self::HEIGHT - $top, $encoded
        );
    }

    private function rect(float $x, float $top, float $width, float $height, array $color): void
    {
        $this->content .= sprintf(
            "%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n",
            $color[0], $color[1], $color[2], $x, self::HEIGHT - $top - $height, $width, $height
        );
    }

    private function line(float $x1, float $top1, float $x2, float $top2, array $color): void
    {
        $this->content .= sprintf(
            "%.3F %.3F %.3F RG %.2F %.2F m %.2F %.2F l S\n",
            $color[0], $color[1], $color[2], $x1, self::HEIGHT - $top1, $x2, self::HEIGHT - $top2
        );
    }

    private function buildDocument(): string
    {
        if ($this->content !== '') $this->pages[] = $this->content;
        $totalPages = count($this->pages);
        foreach ($this->pages as $index => &$page) {
            $footer = 'Apontamentos - página ' . ($index + 1) . ' de ' . $totalPages;
            $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $footer);
            $page .= "0.34 0.39 0.47 rg BT /F1 8 Tf 690 18 Td (" . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) $encoded) . ") Tj ET\n";
        }
        unset($page);

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $pageIds = [];
        foreach ($this->pages as $index => $_content) $pageIds[] = 5 + ($index * 2);
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageIds)) . '] /Count ' . $totalPages . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($this->pages as $index => $content) {
            $pageId = 5 + ($index * 2); $contentId = $pageId + 1;
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::WIDTH . ' ' . self::HEIGHT . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
            $objects[$contentId] = '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . "endstream";
        }
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf); $size = max(array_keys($objects)) + 1;
        $pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
        for ($id = 1; $id < $size; $id++) $pdf .= sprintf('%010d 00000 n ', $offsets[$id]) . "\n";
        $pdf .= "trailer\n<< /Size " . $size . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }

    private static function truncate(string $value, int $length): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, max(1, $length - 3)) . '...';
    }

    private static function hexColor(string $hex): array
    {
        $hex = PluginApontamentosConfig::validColor($hex) ?? PluginApontamentosConfig::DEFAULT_COLOR;
        return [hexdec(substr($hex, 1, 2)) / 255, hexdec(substr($hex, 3, 2)) / 255, hexdec(substr($hex, 5, 2)) / 255];
    }
}
