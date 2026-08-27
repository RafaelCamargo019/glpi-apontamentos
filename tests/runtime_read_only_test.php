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
$classes = [
    'inc/projectright.class.php' => 'PluginApontamentosProjectRight',
    'inc/config.class.php' => 'PluginApontamentosConfig',
    'inc/appointmenttype.class.php' => 'PluginApontamentosAppointmentType',
    'inc/appointment.class.php' => 'PluginApontamentosAppointment',
    'inc/report.class.php' => 'PluginApontamentosReport',
    'inc/itemtab.class.php' => 'PluginApontamentosItemTab',
];
foreach ($classes as $file => $class) {
    if (!class_exists($class, false)) {
        require_once $pluginRoot . '/' . $file;
    }
}

global $DB;
$trackedTables = [
    PluginApontamentosAppointment::getTable(),
    PluginApontamentosAppointmentType::getTable(),
    'glpi_plugin_apontamentos_migrations',
    'glpi_plugin_apontamentos_entitysettings',
    'glpi_plugin_apontamentos_scheduleexceptions',
    'glpi_plugin_apontamentos_userschedules',
];
$before = [];
foreach ($trackedTables as $table) {
    if ($DB->tableExists($table)) {
        $before[$table] = count($DB->request(['FROM' => $table]));
    }
}

$defaultSchedule = PluginApontamentosConfig::defaultSchedule();
Plugin::registerClass('PluginApontamentosItemTab', [
    'addtabon' => ['Ticket', 'Problem', 'Change', 'Project'],
]);
$currentSessionUser = (int) Session::getLoginUserID();
$fixedContextInput = PluginApontamentosItemTab::bindInputToContext([
    'content' => 'somente leitura',
    'link_type' => 'Change',
    'linked_Change' => 99,
    'itemtype' => 'Change',
    'items_id' => 99,
    'projects_id' => 8,
    'projecttasks_id' => 9,
], ['itemtype' => 'Ticket', 'items_id' => 17]);
$fixedProjectContextInput = PluginApontamentosItemTab::bindInputToContext([
    'content' => 'somente leitura',
    'link_type' => 'Ticket',
    'linked_Ticket' => 99,
    'projects_id' => 8,
    'projecttasks_id' => 9,
], ['itemtype' => 'Project', 'items_id' => 23]);
$linkNormalizer = new ReflectionMethod(PluginApontamentosAppointment::class, 'normalizeSubmittedLink');
$linkNormalizer->setAccessible(true);
$projectLinkInput = $linkNormalizer->invoke(new PluginApontamentosAppointment(), [
    'link_type' => 'Project',
    'projects_id' => 5,
    'projecttasks_id' => 6,
    'itemtype' => 'Ticket',
    'items_id' => 8,
]);
$ticketLinkInput = $linkNormalizer->invoke(new PluginApontamentosAppointment(), [
    'link_type' => 'Ticket',
    'linked_Ticket' => 8,
    'projects_id' => 5,
    'projecttasks_id' => 6,
]);
$checks = [
    PluginApontamentosConfig::validColor('#2563eb') === '#2563EB',
    PluginApontamentosConfig::validColor('#ABCDEF') === '#ABCDEF',
    PluginApontamentosConfig::validColor('red') === null,
    PluginApontamentosConfig::validColor('#12345G') === null,
    PluginApontamentosAppointmentType::normalizeName('  Suporte  ') === 'Suporte',
    PluginApontamentosAppointmentType::normalizeName('') === null,
    PluginApontamentosAppointmentType::normalizeName(str_repeat('a', 256)) === null,
    PluginApontamentosAppointmentType::colorFor(0) === PluginApontamentosConfig::DEFAULT_COLOR,
    PluginApontamentosConfig::normalizeMinutes(480) === 480,
    PluginApontamentosConfig::normalizeMinutes(1441) === null,
    PluginApontamentosConfig::scheduleInput($defaultSchedule) === $defaultSchedule,
    !array_key_exists('effective_date', PluginApontamentosConfig::scheduleInput($defaultSchedule)),
    PluginApontamentosAppointment::MANAGE_OTHERS === 32,
    PluginApontamentosConfig::$rightname === 'plugin_apontamentos_config',
    PluginApontamentosAppointmentType::$rightname === PluginApontamentosConfig::$rightname,
    PluginApontamentosReport::$rightname === 'plugin_apontamentos_export',
    PluginApontamentosProjectRight::$rightname === 'plugin_apontamentos_project',
    PluginApontamentosItemTab::$rightname === PluginApontamentosAppointment::$rightname,
    PluginApontamentosItemTab::SUPPORTED_ITEMTYPES === ['Ticket', 'Problem', 'Change', 'Project'],
    PluginApontamentosItemTab::MODAL_ITEMTYPES === ['Ticket', 'Problem', 'Change', 'Project'],
    PluginApontamentosItemTab::supportsModalContext(['itemtype' => 'Problem', 'items_id' => 4]),
    PluginApontamentosItemTab::supportsModalContext(['itemtype' => 'Project', 'items_id' => 4]),
    ($fixedContextInput['link_type'] ?? '') === 'Ticket'
        && (int) ($fixedContextInput['linked_Ticket'] ?? 0) === 17
        && !isset($fixedContextInput['linked_Change'])
        && !isset($fixedContextInput['itemtype'])
        && !isset($fixedContextInput['items_id'])
        && (int) ($fixedContextInput['users_id'] ?? 0) === $currentSessionUser
        && (int) ($fixedContextInput['projects_id'] ?? -1) === 0
        && (int) ($fixedContextInput['projecttasks_id'] ?? -1) === 0,
    ($fixedProjectContextInput['link_type'] ?? '') === 'Project'
        && !isset($fixedProjectContextInput['linked_Ticket'])
        && !isset($fixedProjectContextInput['itemtype'])
        && !isset($fixedProjectContextInput['items_id'])
        && (int) ($fixedProjectContextInput['users_id'] ?? 0) === $currentSessionUser
        && (int) ($fixedProjectContextInput['projects_id'] ?? 0) === 23
        && (int) ($fixedProjectContextInput['projecttasks_id'] ?? 0) === 9,
    ($projectLinkInput['itemtype'] ?? null) === null
        && (int) ($projectLinkInput['items_id'] ?? -1) === 0
        && (int) ($projectLinkInput['projects_id'] ?? 0) === 5
        && (int) ($projectLinkInput['projecttasks_id'] ?? 0) === 6,
    ($ticketLinkInput['itemtype'] ?? '') === 'Ticket'
        && (int) ($ticketLinkInput['items_id'] ?? 0) === 8
        && (int) ($ticketLinkInput['projects_id'] ?? -1) === 0
        && (int) ($ticketLinkInput['projecttasks_id'] ?? -1) === 0,
    PluginApontamentosItemTab::prefillForContext(['itemtype' => 'Ticket', 'items_id' => 17]) === ['link_type' => 'Ticket', 'linked_Ticket' => 17],
    PluginApontamentosItemTab::prefillForContext(['itemtype' => 'Project', 'items_id' => 23]) === ['projects_id' => 23],
    PluginApontamentosItemTab::appointmentMatchesContext(['itemtype' => 'Change', 'items_id' => 9], ['itemtype' => 'Change', 'items_id' => 9]),
    !PluginApontamentosItemTab::appointmentMatchesContext(['itemtype' => 'Problem', 'items_id' => 9], ['itemtype' => 'Change', 'items_id' => 9]),
    PluginApontamentosItemTab::appointmentMatchesContext(['projects_id' => 4], ['itemtype' => 'Project', 'items_id' => 4]),
    str_contains(PluginApontamentosItemTab::appendContext('/front/form.php?id=2', ['itemtype' => 'Ticket', 'items_id' => 7]), 'context_itemtype=Ticket'),
    PluginApontamentosItemTab::contextRequested(['context_items_id' => 7]),
    !PluginApontamentosItemTab::contextRequested(['id' => 7]),
    PluginApontamentosItemTab::contextFromInput(['context_itemtype' => 'User', 'context_items_id' => 2]) === null,
    !str_contains(PluginApontamentosItemTab::contextUrl(['itemtype' => 'Ticket', 'items_id' => 7, 'url' => 'https://example.invalid/']), 'example.invalid'),
    PluginApontamentosItemTab::contextUrl(['itemtype' => 'User', 'items_id' => 2, 'url' => 'https://example.invalid/']) === PluginApontamentosAppointment::getSearchURL(),
    in_array('PluginApontamentosItemTab', CommonGLPI::getOtherTabs('Ticket'), true),
    in_array('PluginApontamentosItemTab', CommonGLPI::getOtherTabs('Problem'), true),
    in_array('PluginApontamentosItemTab', CommonGLPI::getOtherTabs('Change'), true),
    in_array('PluginApontamentosItemTab', CommonGLPI::getOtherTabs('Project'), true),
    isset(PluginApontamentosAppointment::getAllowedItemtypes()['Ticket']),
    PluginApontamentosAppointment::isSameCalendarDay('2026-08-24 00:00:00', '2026-08-24 23:59:00'),
    !PluginApontamentosAppointment::isSameCalendarDay('2026-08-24 23:00:00', '2026-08-25 01:00:00'),
    PluginApontamentosConfig::dailyTargetState(480, 480, new DateTimeImmutable('today')) === 'met',
    PluginApontamentosConfig::dailyTargetState(480, 600, new DateTimeImmutable('tomorrow')) === 'exceeded',
    PluginApontamentosConfig::dailyTargetState(480, 479, new DateTimeImmutable('today')) === 'short',
    PluginApontamentosConfig::dailyTargetState(480, 390, new DateTimeImmutable('yesterday')) === 'short',
    PluginApontamentosConfig::dailyTargetState(480, 0, new DateTimeImmutable('yesterday')) === 'empty',
    PluginApontamentosConfig::dailyTargetState(480, 0, new DateTimeImmutable('tomorrow')) === 'empty',
    PluginApontamentosConfig::dailyTargetState(0, 60, new DateTimeImmutable('today')) === 'off',
    PluginApontamentosConfig::dailyTargetState(null, 60, new DateTimeImmutable('today')) === 'unconfigured',
];

if ($DB->tableExists(PluginApontamentosAppointmentType::getTable())) {
    $allOptions = PluginApontamentosAppointmentType::reportOptions();
    foreach ($DB->request(['FROM' => PluginApontamentosAppointmentType::getTable()]) as $row) {
        $id = (int) $row['id'];
        $record = PluginApontamentosAppointmentType::getRecord($id);
        $checks[] = is_array($record) && (int) $record['id'] === $id;
        $checks[] = PluginApontamentosAppointmentType::displayName($id) === (string) $row['name'];
        $checks[] = PluginApontamentosAppointmentType::colorFor($id)
            === (PluginApontamentosConfig::validColor($row['color'] ?? '') ?? PluginApontamentosConfig::DEFAULT_COLOR);
        $checks[] = !empty($row['is_deleted']) || isset($allOptions[$id]);
    }
}
if ($DB->tableExists('glpi_plugin_apontamentos_userschedules')) {
    foreach ($DB->request(['FROM' => 'glpi_plugin_apontamentos_userschedules']) as $row) {
        $schedule = PluginApontamentosConfig::scheduleForUser((int) $row['users_id']);
        $checks[] = is_array($schedule) && (int) $schedule['users_id'] === (int) $row['users_id'];
    }
}

$originalCsrfTokens = $_SESSION['glpicsrftokens'] ?? null;
$standaloneToken = Session::getNewCSRFToken(true);
$checks[] = Session::validateCSRF(['_glpi_csrf_token' => $standaloneToken], true);
$checks[] = Session::validateCSRF(['_glpi_csrf_token' => $standaloneToken], true);
if ($originalCsrfTokens === null) {
    unset($_SESSION['glpicsrftokens']);
} else {
    $_SESSION['glpicsrftokens'] = $originalCsrfTokens;
}

$after = [];
foreach (array_keys($before) as $table) {
    $after[$table] = count($DB->request(['FROM' => $table]));
}
$checks[] = $before === $after;
if (in_array(false, $checks, true)) {
    $failedChecks = array_keys(array_filter($checks, static fn($value): bool => $value === false));
    fwrite(STDERR, 'Falha nas verificações de execução somente leitura: '
        . implode(', ', array_map(static fn(int $index): string => (string) ($index + 1), $failedChecks)) . ".\n");
    exit(1);
}
echo 'OK - ' . count($checks) . ' verificações de execução somente leitura; contagens antes/depois: '
    . json_encode($before, JSON_UNESCAPED_SLASHES) . '/'
    . json_encode($after, JSON_UNESCAPED_SLASHES) . ".\n";
