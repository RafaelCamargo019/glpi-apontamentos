<?php

ob_start();
include '../../../inc/includes.php';

function plugin_apontamentos_itemtab_response(array $payload, int $status = 200): never
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

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    plugin_apontamentos_itemtab_response([
        'success' => false,
        'message' => __('Método não permitido.', 'apontamentos'),
    ], 405);
}

if (!Session::haveRight(PluginApontamentosAppointment::$rightname, READ)) {
    plugin_apontamentos_itemtab_response([
        'success' => false,
        'message' => __('Você não possui permissão para visualizar estes apontamentos.', 'apontamentos'),
    ], 403);
}

$context = PluginApontamentosItemTab::contextFromInput($_GET);
if ($context === null || !PluginApontamentosItemTab::supportsModalContext($context)) {
    plugin_apontamentos_itemtab_response([
        'success' => false,
        'message' => __('O registro de origem é inválido ou não está mais acessível.', 'apontamentos'),
    ], 403);
}

$itemtype = (string) $context['itemtype'];
$item = new $itemtype();
if (!$item->getFromDB((int) $context['items_id']) || !PluginApontamentosItemTab::canAccessItem($item)) {
    plugin_apontamentos_itemtab_response([
        'success' => false,
        'message' => __('O registro de origem é inválido ou não está mais acessível.', 'apontamentos'),
    ], 403);
}

ob_start();
PluginApontamentosItemTab::renderPanel($item, $context);
$html = (string) ob_get_clean();
plugin_apontamentos_itemtab_response([
    'success' => true,
    'html' => $html,
]);
