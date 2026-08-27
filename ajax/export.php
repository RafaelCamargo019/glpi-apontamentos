<?php

use Glpi\Event;

Session::checkRight(PluginApontamentosReport::$rightname, READ);

function plugin_apontamentos_csv_error(string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo $message;
    exit;
}

function plugin_apontamentos_csv_value($value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
    return preg_match('/^[=+\-@]/u', ltrim($value)) ? "'" . $value : $value;
}

try {
    $filters = PluginApontamentosReport::filtersFromRequest($_GET);
    $report = PluginApontamentosReport::build($filters);
} catch (InvalidArgumentException $exception) {
    plugin_apontamentos_csv_error($exception->getMessage());
}

$kind = (string) ($_GET['kind'] ?? 'detailed');
if (!in_array($kind, ['detailed', 'summary'], true)) {
    plugin_apontamentos_csv_error(__('Tipo de exportação inválido.', 'apontamentos'));
}
$startName = $filters['start']->format('Ymd');
$endName = $filters['end']->format('Ymd');
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="apontamentos-' . $kind . '-' . $startName . '-' . $endName . '.csv"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF");

if ($kind === 'summary') {
    fputcsv($out, [
        'Usuário', 'Data', 'Jornada esperada (min)', 'Horas apontadas (min)',
        'Horas não apontadas (min)', 'Horas excedentes (min)',
        'Ocupação (%)', 'Quantidade', 'Situação',
    ], ';', '"', '\\');
    foreach ($report['summary'] as $line) {
        fputcsv($out, array_map('plugin_apontamentos_csv_value', [
            $line['name'],
            (new DateTimeImmutable($line['dateKey']))->format('d/m/Y'),
            $line['expectedMinutes'] ?? 'Jornada não configurada',
            $line['pointedMinutes'],
            $line['missingMinutes'] ?? 'Jornada não configurada',
            $line['extraMinutes'],
            $line['occupation'] ?? 'Jornada não configurada',
            $line['count'],
            PluginApontamentosReport::statusLabel($line['state']),
        ]), ';', '"', '\\');
    }
} else {
    fputcsv($out, [
        'ID', 'Tipo de apontamento', 'Entidade', 'Usuário', 'Início', 'Fim',
        'Duração (min)', 'Tipo do vínculo', 'ID vinculado', 'Projeto',
        'Tarefa', 'Conteúdo', 'Criação', 'Modificação',
    ], ';', '"', '\\');
    foreach ($report['rows'] as $row) {
        $minutes = max(0, (int) round(
            ($row['_end']->getTimestamp() - $row['_begin']->getTimestamp()) / 60
        ));
        fputcsv($out, array_map('plugin_apontamentos_csv_value', [
            (int) $row['id'],
            PluginApontamentosAppointmentType::displayName((int) $row['appointmenttypes_id']),
            Dropdown::getDropdownName('glpi_entities', (int) $row['entities_id']),
            getUserName((int) $row['users_id']),
            $row['begin_time'],
            $row['end_time'],
            $minutes,
            $row['_link_label'],
            (int) $row['items_id'] ?: '',
            (int) $row['projects_id'] ?: '',
            $row['_project_label'],
            $row['content'],
            $row['date_creation'],
            $row['date_mod'],
        ]), ';', '"', '\\');
    }
}

Event::log(
    Session::getLoginUserID(),
    'apontamentos',
    4,
    'plugins',
    'Exportação ' . $kind . ': ' . $filters['start']->format('Y-m-d')
        . ' a ' . $filters['end']->format('Y-m-d')
);
fclose($out);
