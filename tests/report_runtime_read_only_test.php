<?php

$glpiRoot = rtrim((string) getenv('GLPI_ROOT'), '/\\');
if ($glpiRoot === '' || !is_file($glpiRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "Defina GLPI_ROOT para executar este teste.\n");
    exit(2);
}
require_once $glpiRoot . '/vendor/autoload.php';
$kernel = new Glpi\Kernel\Kernel();
$kernel->boot();
$pluginRoot = dirname(__DIR__);
foreach ([
    'inc/projectright.class.php' => 'PluginApontamentosProjectRight',
    'inc/config.class.php' => 'PluginApontamentosConfig',
    'inc/appointmenttype.class.php' => 'PluginApontamentosAppointmentType',
    'inc/appointment.class.php' => 'PluginApontamentosAppointment',
    'inc/report.class.php' => 'PluginApontamentosReport',
    'inc/reportpdf.class.php' => 'PluginApontamentosReportPdf',
] as $file => $class) {
    if (!class_exists($class, false)) require_once $pluginRoot . '/' . $file;
}

global $DB;
$tables = [
    'glpi_plugin_apontamentos_appointments',
    'glpi_plugin_apontamentos_types',
    'glpi_plugin_apontamentos_userschedules',
];
$before = [];
foreach ($tables as $table) $before[$table] = count($DB->request(['FROM' => $table]));

$_SESSION['glpiID'] = 2;
$_SESSION['glpiactive_entity'] = 0;
$_SESSION['glpiactiveentities'] = [0];
$_SESSION['glpiactiveprofile'][PluginApontamentosAppointment::$rightname] = READ;
$_SESSION['glpiactiveprofile'][PluginApontamentosProjectRight::$rightname] = 0;

$filters = PluginApontamentosReport::filtersFromRequest([
    'view' => 'week', 'start' => '2026-08-23', 'end' => '2026-08-29',
    'entities_id' => 999999, 'users_id' => 2,
]);
$report = PluginApontamentosReport::build($filters);
$typeMinutes = array_sum(array_column($report['types'], 'minutes'));
$checks = [
    $filters['userId'] === 2,
    $filters['entityId'] === 0 && $filters['entities'] === [0],
    $filters['start']->format('Y-m-d') === '2026-08-23',
    $filters['end']->format('Y-m-d') === '2026-08-29',
    count($report['summary']) === 7,
    !array_filter($report['rows'], static fn(array $row): bool => !empty($row['is_deleted'])),
    $typeMinutes === $report['totals']['pointed'],
    $report['totals']['missing'] >= 0,
    $report['totals']['extra'] >= 0,
    str_starts_with(PluginApontamentosReportPdf::render($report), '%PDF-1.4'),
];
try {
    PluginApontamentosReport::filtersFromRequest([
        'view' => 'week', 'start' => '2026-08-23', 'end' => '2026-08-29',
        'itemtype' => 'Project',
    ]);
    $checks[] = false;
} catch (InvalidArgumentException) {
    $checks[] = true;
}
try {
    PluginApontamentosReport::filtersFromRequest([
        'view' => 'custom', 'start' => '2026-01-01', 'end' => '2026-12-31',
    ]);
    $checks[] = false;
} catch (InvalidArgumentException) {
    $checks[] = true;
}

$after = [];
foreach ($tables as $table) $after[$table] = count($DB->request(['FROM' => $table]));
$checks[] = $before === $after;
$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, 'Falharam verificações de execução gerencial: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK - ' . count($checks) . ' verificações gerenciais de execução somente leitura; registros: '
    . json_encode($before) . '/' . json_encode($after) . ".\n";
