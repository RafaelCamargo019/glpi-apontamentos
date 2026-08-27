<?php

use Glpi\Event;

ob_start();
include '../../../inc/includes.php';

function plugin_apontamentos_create_response(array $payload, int $status = 200): never
{
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    plugin_apontamentos_create_response([
        'success' => false,
        'message' => __('Método não permitido.', 'apontamentos'),
    ], 405);
}

if (!Session::validateCSRF($_POST, true)) {
    plugin_apontamentos_create_response([
        'success' => false,
        'message' => __('A sessão de segurança expirou. Atualize a página e tente novamente.', 'apontamentos'),
    ], 403);
}

if (!PluginApontamentosAppointment::canCreate()) {
    plugin_apontamentos_create_response([
        'success' => false,
        'message' => __('Você não possui permissão para criar apontamentos.', 'apontamentos'),
    ], 403);
}

try {
    $appointment = new PluginApontamentosAppointment();
    $appointment->setValidationMessagesEnabled(false);
    $newId = $appointment->add($_POST);
    if (!$newId) {
        $message = $appointment->getLastValidationError()
            ?? __('Não foi possível salvar o apontamento.', 'apontamentos');
        plugin_apontamentos_create_response([
            'success' => false,
            'message' => $message,
            'show_details' => preg_match('/vínculo|vincule|registro|projeto|tarefa/iu', $message) === 1,
            'csrf_token' => Session::getNewCSRFToken(true),
        ], 422);
    }

    Event::log((int) $newId, 'apontamentos', 4, 'plugins', 'Criação de apontamento pelo calendário');
    plugin_apontamentos_create_response([
        'success' => true,
        'message' => __('Apontamento salvo com sucesso.', 'apontamentos'),
        'id' => (int) $newId,
        'csrf_token' => Session::getNewCSRFToken(true),
    ], 201);
} catch (Throwable $error) {
    plugin_apontamentos_create_response([
        'success' => false,
        'message' => __('Não foi possível salvar o apontamento.', 'apontamentos'),
        'csrf_token' => Session::getNewCSRFToken(true),
    ], 500);
}
