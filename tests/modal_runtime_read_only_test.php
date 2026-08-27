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
    'inc/appointmentmodal.class.php' => 'PluginApontamentosAppointmentModal',
] as $file => $class) {
    if (!class_exists($class, false)) require_once $pluginRoot . '/' . $file;
}

global $DB;
$tables = [
    PluginApontamentosAppointment::getTable(),
    PluginApontamentosAppointmentType::getTable(),
    Project::getTable(),
    ProjectTask::getTable(),
];
$before = [];
foreach ($tables as $table) $before[$table] = count($DB->request(['FROM' => $table]));

$_SESSION['glpiID'] = 2;
$_SESSION['glpiactive_entity'] = 0;
$_SESSION['glpiactiveentities'] = [0];
$_SESSION['glpiactiveprofile'][PluginApontamentosAppointment::$rightname] = ALLSTANDARDRIGHT | PluginApontamentosAppointment::MANAGE_OTHERS;
$_SESSION['glpiactiveprofile'][PluginApontamentosProjectRight::$rightname] = READ;

ob_start();
PluginApontamentosAppointmentModal::render(2, 'token-somente-leitura');
$html = (string) ob_get_clean();
ob_start();
PluginApontamentosAppointmentModal::render(2, 'token-contexto', [
    'itemtype' => 'Problem',
    'items_id' => 7,
    'name' => 'Problema demonstrativo',
]);
$contextHtml = (string) ob_get_clean();
ob_start();
PluginApontamentosAppointmentModal::render(2, 'token-projeto', [
    'itemtype' => 'Project',
    'items_id' => 23,
    'name' => 'Projeto demonstrativo',
]);
$projectContextHtml = (string) ob_get_clean();
$checks = [
    str_contains($html, "id='ap-create-modal'"),
    str_contains($html, "name='appointmenttypes_id'"),
    str_contains($html, "name='linked_Ticket'"),
    str_contains($html, "name='projects_id'"),
    str_contains($html, "name='projecttasks_id'"),
    str_contains($html, "<option value='Project'>Projeto</option>"),
    str_contains($html, "class='ap-details-grid ap-modal-project-fields' hidden"),
    str_contains($html, "id='ap-modal-project' name='projects_id' disabled"),
    str_contains($html, "value='token-somente-leitura'"),
    !str_contains($html, "name='entities_id'"),
    str_contains($contextHtml, "name='context_itemtype' value='Problem'"),
    str_contains($contextHtml, "name='context_items_id' value='7'"),
    str_contains($contextHtml, "name='linked_Problem' value='7'"),
    str_contains($contextHtml, 'Problema #7 - Problema demonstrativo'),
    str_contains($contextHtml, 'ap-fixed-link') && str_contains($contextHtml, 'ti ti-lock'),
    !str_contains($contextHtml, "id='ap-modal-link-type'") && !str_contains($contextHtml, "id='ap-modal-project'"),
    str_contains($projectContextHtml, "name='context_itemtype' value='Project'"),
    str_contains($projectContextHtml, "name='context_items_id' value='23'"),
    str_contains($projectContextHtml, "name='projects_id' value='23'"),
    str_contains($projectContextHtml, "name='projecttasks_id'")
        && !str_contains($projectContextHtml, "name='projecttasks_id' disabled"),
    str_contains($projectContextHtml, 'Tarefa do projeto') && str_contains($projectContextHtml, 'opcional'),
    str_contains($projectContextHtml, 'Projeto #23 - Projeto demonstrativo'),
    !str_contains($projectContextHtml, "name='linked_Project'")
        && !str_contains($projectContextHtml, "id='ap-modal-project'"),
];

$after = [];
foreach ($tables as $table) $after[$table] = count($DB->request(['FROM' => $table]));
$checks[] = $before === $after;
if (in_array(false, $checks, true)) {
    fwrite(STDERR, "Falha no teste de execução do modal.\n");
    exit(1);
}
echo 'OK - ' . count($checks) . ' verificações de execução do modal; contagens antes/depois: '
    . json_encode($before) . '/' . json_encode($after) . ".\n";
