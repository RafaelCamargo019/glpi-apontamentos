<?php

$root = dirname(__DIR__);
$appointment = file_get_contents($root . '/inc/appointment.class.php');
$form = file_get_contents($root . '/front/appointment.form.php');
$page = file_get_contents($root . '/front/appointment.php');
$calendar = file_get_contents($root . '/js/calendar.js');
$delete = file_get_contents($root . '/ajax/delete.php');
$events = file_get_contents($root . '/ajax/events.php');
$export = file_get_contents($root . '/ajax/export.php');
$reportClass = file_get_contents($root . '/inc/report.class.php');
$preview = file_get_contents($root . '/tests/calendar_preview.html');

$checks = [
    'mensagem automática desativada somente no objeto' => str_contains($appointment, 'public $auto_message_on_action = false;'),
    'criação possui somente mensagem personalizada' => substr_count($form, 'Apontamento salvo com sucesso.') === 1
        && !str_contains($appointment . $form, 'Item adicionado com sucesso'),
    'edição possui somente mensagem personalizada' => substr_count($form, 'Apontamento atualizado com sucesso.') === 1,
    'formulário preserva submissão com erro' => substr_count($form, '$formOptions[\'form_input\'] = $_POST') >= 2,
    'exclusão possui mensagem única por fluxo' => substr_count($form, 'Apontamento excluído com sucesso.') === 1
        && substr_count($delete, 'Apontamento excluído com sucesso.') === 1,
    'endpoint de exclusão responde somente JSON' => str_contains($delete, 'ob_start();')
        && str_contains($delete, 'plugin_apontamentos_delete_response')
        && str_contains($delete, "Content-Type: application/json; charset=UTF-8")
        && substr_count($delete, 'echo json_encode') === 1,
    'mensagem do calendário usa alerta padrão e texto seguro' => str_contains($page, 'alert-important alert-success alert-dismissible')
        && str_contains($page, "class='ap-message-text'")
        && str_contains($calendar, 'textElement.textContent = String(message'),
    'troca comum e Select2 usam função única' => str_contains($calendar, "addEventListener('change', userSelectionChanged)")
        && str_contains($calendar, "event.target?.name === 'ap_user_filter') userSelectionChanged()"),
    'troca de usuário evita requisição duplicada' => str_contains($calendar, 'selectedUserValue')
        && str_contains($calendar, 'userReloadQueued')
        && str_contains($calendar, 'queueMicrotask'),
    'URL preserva usuário data e visualização' => str_contains($calendar, "searchParams.set('users_id'")
        && str_contains($calendar, "searchParams.set('date'")
        && str_contains($calendar, "searchParams.set('view'")
        && str_contains($page, "data-initial-view='")
        && str_contains($calendar, 'root.dataset.initialView'),
    'seletor de gestor não oferece usuário vazio' => str_contains($page, "'display_emptychoice' => false"),
    'aviso do rodapé removido' => !str_contains($page, 'Registros excluídos não são exibidos.')
        && !str_contains($preview, 'Registros excluídos não são exibidos.'),
    'excluídos continuam filtrados' => str_contains($events, "'is_deleted' => 0")
        && str_contains($reportClass, "'is_deleted' => 0"),
    'CSV atual permanece disponível' => str_contains($export, 'text/csv')
        && str_contains(file_get_contents($root . '/front/report.php'), 'CSV detalhado'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, 'Falharam: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}
echo 'OK - ' . count($checks) . " verificações de mensagens e interface somente leitura concluídas.\n";
