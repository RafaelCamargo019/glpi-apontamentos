<?php

if (!class_exists('CommonGLPI')) {
    class CommonGLPI {}
}
if (!function_exists('__')) {
    function __($message, $domain = null) { return $message; }
}
if (!class_exists('PluginApontamentosConfig')) {
    class PluginApontamentosConfig
    {
        public const DEFAULT_COLOR = '#206BC4';
        public static function validColor($value): ?string
        {
            $value = strtoupper((string) $value);
            return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : null;
        }
    }
}

require_once dirname(__DIR__) . '/inc/report.class.php';
require_once dirname(__DIR__) . '/inc/reportpdf.class.php';

$summary = [];
for ($day = 1; $day <= 25; $day++) {
    $summary[] = [
        'dateKey' => '2026-08-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT),
        'userId' => 2,
        'name' => 'Operador de demonstração',
        'expectedMinutes' => 480,
        'rawMinutes' => $day % 3 === 0 ? 600 : 360,
        'pointedMinutes' => $day % 3 === 0 ? 600 : 360,
        'missingMinutes' => $day % 3 === 0 ? 0 : 120,
        'extraMinutes' => $day % 3 === 0 ? 120 : 0,
        'occupation' => $day % 3 === 0 ? 125.0 : 75.0,
        'state' => $day % 3 === 0 ? 'exceeded' : 'short',
        'count' => 4,
    ];
}
$report = [
    'filters' => [
        'start' => new DateTimeImmutable('2026-08-01'),
        'end' => new DateTimeImmutable('2026-08-25'),
    ],
    'totals' => [
        'expected' => 12000, 'pointed' => 9960, 'missing' => 2880,
        'extra' => 840, 'occupation' => 83.0, 'count' => 100,
        'operators' => 1,
    ],
    'summary' => $summary,
    'types' => [
        ['name' => 'Suporte', 'color' => '#206BC4', 'minutes' => 6000, 'percent' => 60.2],
        ['name' => 'Implantação', 'color' => '#2FB344', 'minutes' => 3960, 'percent' => 39.8],
    ],
    'overlapIds' => [3 => true, 4 => true],
];
$pdf = PluginApontamentosReportPdf::render($report);
if (!str_starts_with($pdf, '%PDF-1.4') || !str_ends_with($pdf, "%%EOF\n") || substr_count($pdf, '/Type /Page ') < 3) {
    fwrite(STDERR, "PDF inválido ou sem paginação esperada.\n");
    exit(1);
}
$target = $argv[1] ?? '';
if ($target !== '') {
    if (file_put_contents($target, $pdf) !== strlen($pdf)) {
        fwrite(STDERR, "Não foi possível escrever o PDF de validação.\n");
        exit(1);
    }
}
echo "OK - PDF local gerado com " . strlen($pdf) . " bytes e paginação válida.\n";
