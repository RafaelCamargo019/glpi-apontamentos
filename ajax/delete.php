<?php

use Glpi\Event;

ob_start();
include '../../../inc/includes.php';

function plugin_apontamentos_delete_response(array $payload, int $status = 200): never
{
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    plugin_apontamentos_delete_response([
        'success' => false,
        'error' => __('Método não permitido.', 'apontamentos'),
    ], 405);
}

// O calendário pode excluir mais de um cartão sem recarregar a página. O token
// é exclusivo deste componente e permanece válido durante essa tela.
if (!Session::validateCSRF($_POST, true)) {
    plugin_apontamentos_delete_response([
        'success' => false,
        'error' => __('A sessão de segurança expirou. Atualize a página e tente novamente.', 'apontamentos'),
    ], 403);
}

$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) {
    plugin_apontamentos_delete_response([
        'success' => false,
        'error' => __('Apontamento inválido.', 'apontamentos'),
    ], 400);
}

global $DB;
$row = $DB->request([
    'FROM' => PluginApontamentosAppointment::getTable(),
    'WHERE' => ['id' => (int) $id],
    'LIMIT' => 1,
])->current();
if (!$row) {
    plugin_apontamentos_delete_response([
        'success' => false,
        'error' => __('Apontamento não encontrado.', 'apontamentos'),
    ], 404);
}

if (!PluginApontamentosAppointment::canDeleteStoredFields($row)) {
    plugin_apontamentos_delete_response([
        'success' => false,
        'error' => __('Você não possui permissão para excluir este apontamento.', 'apontamentos'),
    ], 403);
}

if (!PluginApontamentosAppointment::deletePermanently((int) $id)) {
    plugin_apontamentos_delete_response([
        'success' => false,
        'error' => __('Não foi possível excluir o apontamento.', 'apontamentos'),
    ], 422);
}

Event::log((int) $id, 'apontamentos', 4, 'plugins', 'Exclusão lógica de apontamento pelo calendário');
plugin_apontamentos_delete_response([
    'success' => true,
    'message' => __('Apontamento excluído com sucesso.', 'apontamentos'),
]);
