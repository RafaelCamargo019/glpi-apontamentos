<?php

Session::checkRight(PluginApontamentosAppointment::$rightname, READ);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function plugin_apontamentos_events_error(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function plugin_apontamentos_events_date($value): ?DateTimeImmutable
{
    if (!is_string($value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && ($errors === false || (!$errors['warning_count'] && !$errors['error_count']))
        && $date->format('Y-m-d') === $value
        ? $date
        : null;
}

$start = plugin_apontamentos_events_date($_GET['start'] ?? null);
$end = plugin_apontamentos_events_date($_GET['end'] ?? null);
if ($start === null || $end === null || $end <= $start) {
    plugin_apontamentos_events_error(__('Período inválido.', 'apontamentos'));
}
$days = (int) $start->diff($end)->format('%a');
if ($days < 1 || $days > 42) {
    plugin_apontamentos_events_error(__('O período consultado deve ter entre 1 e 42 dias.', 'apontamentos'));
}

$activeEntities = array_values(array_unique(array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []))));
$entityId = (int) ($_SESSION['glpiactive_entity'] ?? -1);
if ($entityId < 0 || !in_array($entityId, $activeEntities, true)) {
    plugin_apontamentos_events_error(__('Entidade inválida ou inacessível.', 'apontamentos'), 403);
}
$entities = [];
foreach ($activeEntities as $activeEntityId) {
    $entity = new Entity();
    if ($activeEntityId >= 0 && $entity->getFromDB($activeEntityId)) {
        $entities[] = $activeEntityId;
    }
}
$entities = array_values(array_unique($entities));
if ($entities === []) {
    plugin_apontamentos_events_error(__('Nenhuma entidade acessível.', 'apontamentos'), 403);
}

$requestedUser = filter_var($_GET['users_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($requestedUser === false) {
    plugin_apontamentos_events_error(__('Usuário inválido.', 'apontamentos'));
}
$canManageOthers = PluginApontamentosAppointment::canManageOthers();
if (!$canManageOthers) {
    $requestedUser = Session::getLoginUserID();
}

global $DB;
$where = [
    'is_deleted' => 0,
    'entities_id' => $entities,
    'begin_time' => ['<', $end->format('Y-m-d 00:00:00')],
    'end_time' => ['>', $start->format('Y-m-d 00:00:00')],
];
if ($requestedUser > 0) {
    $where['users_id'] = $requestedUser;
}

$itemtypes = PluginApontamentosAppointment::getAllowedItemtypes();
$events = [];
$dailyMinutes = [];
foreach ($DB->request([
    'FROM' => PluginApontamentosAppointment::getTable(),
    'WHERE' => $where,
    'ORDER' => ['begin_time ASC', 'id ASC'],
    'LIMIT' => 2000,
]) as $row) {
    $itemtype = (string) ($row['itemtype'] ?? '');
    $itemsId = (int) ($row['items_id'] ?? 0);
    $reference = '';
    $linkedUrl = '';
    if ($itemtype !== '' && $itemsId > 0 && isset($itemtypes[$itemtype])) {
        // O vínculo gravado continua identificável mesmo se a permissão do
        // objeto tiver mudado. A URL e o nome só são expostos com READ atual.
        $reference = $itemtypes[$itemtype] . ' #' . $itemsId;
        $linkedItem = new $itemtype();
        if (PluginApontamentosAppointment::canReadRelatedItem($linkedItem, $itemsId)) {
            $linkedUrl = $itemtype::getFormURLWithID($itemsId);
            $linkedName = trim((string) $linkedItem->getName());
            if ($linkedName !== '') {
                $reference .= ' - ' . $linkedName;
            }
        }
    }
    $projectReference = '';
    $projectTaskReference = '';
    if (PluginApontamentosAppointment::canUseProjects()) {
        $projectId = (int) ($row['projects_id'] ?? 0);
        if ($projectId > 0) {
            $projectReference = __('Projeto', 'apontamentos') . ' #' . $projectId;
            $project = new Project();
            if (PluginApontamentosAppointment::canReadRelatedItem($project, $projectId)) {
                $projectName = trim((string) $project->getName());
                if ($projectName !== '') {
                    $projectReference .= ' - ' . $projectName;
                }
            }
        }
        $projectTaskId = (int) ($row['projecttasks_id'] ?? 0);
        if ($projectTaskId > 0) {
            $projectTaskReference = __('Tarefa do projeto', 'apontamentos') . ' #' . $projectTaskId;
            $projectTask = new ProjectTask();
            if (PluginApontamentosAppointment::canReadRelatedItem($projectTask, $projectTaskId)) {
                $projectTaskName = trim((string) $projectTask->getName());
                if ($projectTaskName !== '') {
                    $projectTaskReference .= ' - ' . $projectTaskName;
                }
            }
        }
    }
    $appointment = new PluginApontamentosAppointment();
    $appointment->getFromDB((int) $row['id']);
    $editUrl = PluginApontamentosAppointment::getFormURLWithID((int) $row['id']);
    $appointmentTypeId = (int) ($row['appointmenttypes_id'] ?? 0);
    $appointmentTypeName = PluginApontamentosAppointmentType::displayName($appointmentTypeId);
    $color = PluginApontamentosAppointmentType::colorFor($appointmentTypeId);
    $entityName = trim((string) Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']));
    if ($entityName === '') {
        $entityName = (int) $row['entities_id'] === 0
            ? __('Entidade raiz', 'apontamentos')
            : sprintf(__('Entidade #%d', 'apontamentos'), (int) $row['entities_id']);
    }
    $event = [
        'id' => (int) $row['id'],
        'user_id' => (int) $row['users_id'],
        'entity_id' => (int) $row['entities_id'],
        'entity' => $entityName,
        'appointment_type_id' => $appointmentTypeId,
        'appointment_type' => $appointmentTypeName,
        'start' => (string) $row['begin_time'],
        'end' => (string) $row['end_time'],
        'content' => (string) ($row['content'] ?? ''),
        'reference' => $reference,
        'project' => $projectReference,
        'project_task' => $projectTaskReference,
        'linked_url' => $linkedUrl,
        'edit_url' => $editUrl,
        'can_update' => $appointment->can((int) $row['id'], UPDATE),
        'can_delete' => $appointment->can((int) $row['id'], DELETE),
        'color' => $color,
        'url' => $linkedUrl !== '' ? $linkedUrl : $editUrl,
    ];
    $events[] = $event;
    $key = substr((string) $row['begin_time'], 0, 10);
    $dailyMinutes[$key] = ($dailyMinutes[$key] ?? 0) + max(0, (int) round((strtotime((string) $row['end_time']) - strtotime((string) $row['begin_time'])) / 60));
}

$schedule = [];
$today = new DateTimeImmutable('today');
for ($date = $start; $date < $end; $date = $date->modify('+1 day')) {
    $key = $date->format('Y-m-d');
    $actual = (int) ($dailyMinutes[$key] ?? 0);
    $expected = $requestedUser > 0 ? PluginApontamentosConfig::scheduleFor((int) $requestedUser, $date) : null;
    $state = PluginApontamentosConfig::dailyTargetState($expected, $actual, $date, $today);
    $schedule[$key] = ['expected_minutes' => $expected, 'actual_minutes' => $actual, 'state' => $state, 'difference_minutes' => $expected === null ? null : $actual - $expected];
}

echo json_encode([
    'events' => $events,
    'schedule' => $schedule,
    'range' => ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
