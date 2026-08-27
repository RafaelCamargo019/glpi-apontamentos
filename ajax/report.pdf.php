<?php

Session::checkRight(PluginApontamentosReport::$rightname, READ);
require_once dirname(__DIR__) . '/inc/reportpdf.class.php';

try {
    $filters = PluginApontamentosReport::filtersFromRequest($_GET);
    $report = PluginApontamentosReport::build($filters);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $exception->getMessage();
    exit;
}

$pdf = PluginApontamentosReportPdf::render($report);
$filename = 'relatorio-apontamentos-'
    . $filters['start']->format('Ymd') . '-'
    . $filters['end']->format('Ymd') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $pdf;
