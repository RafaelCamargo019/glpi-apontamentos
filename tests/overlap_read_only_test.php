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
    'inc/projectright.class.php',
    'inc/config.class.php',
    'inc/appointmenttype.class.php',
    'inc/appointment.class.php',
] as $file) {
    require_once $pluginRoot . '/' . $file;
}

global $DB;
$table = PluginApontamentosAppointment::getTable();
$before = count($DB->request(['FROM' => $table]));
$existing = [
    'id' => 91,
    'users_id' => 7,
    'entities_id' => 0,
    'appointmenttypes_id' => 1,
    'begin_time' => '2026-08-25 10:00:00',
    'end_time' => '2026-08-25 11:00:00',
    'itemtype' => 'Ticket',
    'items_id' => 23,
    'projects_id' => 0,
    'projecttasks_id' => 0,
    'is_deleted' => 0,
];

$checks = [
    'intervalo igual bloqueado' => PluginApontamentosAppointment::intervalsOverlap('2026-08-25 10:00:00', '2026-08-25 11:00:00', $existing['begin_time'], $existing['end_time']),
    'intervalo interno bloqueado' => PluginApontamentosAppointment::intervalsOverlap('2026-08-25 10:15:00', '2026-08-25 10:45:00', $existing['begin_time'], $existing['end_time']),
    'intervalo envolvendo bloqueado' => PluginApontamentosAppointment::intervalsOverlap('2026-08-25 09:00:00', '2026-08-25 12:00:00', $existing['begin_time'], $existing['end_time']),
    'sobreposição no começo bloqueada' => PluginApontamentosAppointment::intervalsOverlap('2026-08-25 09:30:00', '2026-08-25 10:30:00', $existing['begin_time'], $existing['end_time']),
    'sobreposição no final bloqueada' => PluginApontamentosAppointment::intervalsOverlap('2026-08-25 10:30:00', '2026-08-25 11:30:00', $existing['begin_time'], $existing['end_time']),
    'início no fim permitido' => !PluginApontamentosAppointment::intervalsOverlap('2026-08-25 11:00:00', '2026-08-25 12:00:00', $existing['begin_time'], $existing['end_time']),
    'fim no início permitido' => !PluginApontamentosAppointment::intervalsOverlap('2026-08-25 09:00:00', '2026-08-25 10:00:00', $existing['begin_time'], $existing['end_time']),
    'mesmo usuário bloqueado' => PluginApontamentosAppointment::rowConflictsWithInterval($existing, 7, '2026-08-25 10:30:00', '2026-08-25 11:30:00'),
    'outro usuário permitido' => !PluginApontamentosAppointment::rowConflictsWithInterval($existing, 8, '2026-08-25 10:30:00', '2026-08-25 11:30:00'),
    'excluído ignorado' => !PluginApontamentosAppointment::rowConflictsWithInterval(array_replace($existing, ['is_deleted' => 1]), 7, '2026-08-25 10:30:00', '2026-08-25 11:30:00'),
    'edição ignora o próprio id' => !PluginApontamentosAppointment::rowConflictsWithInterval($existing, 7, '2026-08-25 10:00:00', '2026-08-25 11:00:00', 91),
    'edição bloqueia outro id' => PluginApontamentosAppointment::rowConflictsWithInterval($existing, 7, '2026-08-25 10:00:00', '2026-08-25 11:00:00', 92),
    'consulta sem usuário correspondente' => PluginApontamentosAppointment::findOverlap(2147483647, '2099-01-01 10:00:00', '2099-01-01 11:00:00') === null,
];

$neutralMessage = PluginApontamentosAppointment::overlapErrorMessage($existing);
$checks['mensagem informa data e horário sem vazar vínculo'] = str_contains($neutralMessage, '25/08/2026')
    && str_contains($neutralMessage, '10:00')
    && str_contains($neutralMessage, '11:00')
    && !str_contains($neutralMessage, '#23');
$checks['teste não altera tabela'] = $before === count($DB->request(['FROM' => $table]));

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, 'Falharam: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK - ' . count($checks) . " verificações de sobreposição somente leitura concluídas.\n";
