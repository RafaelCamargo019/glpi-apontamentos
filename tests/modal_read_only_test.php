<?php

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/front/appointment.php');
$modal = (string) file_get_contents($root . '/inc/appointmentmodal.class.php');
$appointment = (string) file_get_contents($root . '/inc/appointment.class.php');
$endpoint = (string) file_get_contents($root . '/ajax/create.php');
$calendar = (string) file_get_contents($root . '/js/calendar.js');
$css = (string) file_get_contents($root . '/css/calendar.css');
$errors = [];
$checks = [];
$check = static function (string $label, bool $condition) use (&$errors, &$checks): void {
    $checks[] = $label;
    if (!$condition) $errors[] = 'Falhou: ' . $label;
};

$check('Novo apontamento possui fallback e ação de modal', str_contains($page, "data-action='open-create'") && str_contains($page, 'getFormURL()'));
$check('clique principal impede navegação', str_contains($calendar, "action === 'open-create'") && str_contains($calendar, 'event.preventDefault()'));
$check('modal é centralizado', str_contains($css, '.ap-create-modal { position: fixed; inset: 0;') && str_contains($css, 'align-items: center; justify-content: center'));
$check('calendário permanece no fundo', str_contains($modal, "class='ap-create-backdrop'") && !preg_match('/window\.location\.href\s*=/', $calendar));
$check('fundo recebe camada escura', str_contains($css, 'background: rgba(15, 23, 42, .58)'));
$check('conteúdo compacto usa coluna existente', str_contains($modal, "type='text' name='content'") && str_contains($modal, 'O que eu fiz?'));
$check('conteúdo continua opcional', !preg_match("/name='content'[^>]*required/", $modal) && str_contains($appointment, "\$input['content'] = trim"));
$check('tipo é obrigatório', str_contains($modal, "name='appointmenttypes_id' required") && str_contains($appointment, 'Selecione um tipo de apontamento válido.'));
$check('data início e término são obrigatórios', str_contains($modal, "name='appointment_date' required") && str_contains($modal, "name='begin_time_hour' step='60' required") && str_contains($modal, "name='end_time_hour' step='60' required"));
$check('servidor reconstrói horários', substr_count($appointment, 'self::combineDateAndTime(') >= 2);
$check('horários completos forjados são ignorados', str_contains($appointment, "unset(\$input['begin_time'], \$input['end_time'])"));
$check('intervalo inválido é rejeitado', str_contains($appointment, 'isValidSameDayInterval') && str_contains($appointment, 'posterior ao horário inicial'));
$check('sobreposição permanece bloqueada', str_contains($appointment, 'findOverlap(') && str_contains($appointment, 'overlapErrorMessage'));
$check('horários consecutivos permanecem permitidos', str_contains($appointment, "['begin_time' => ['<', \$end]]") && str_contains($appointment, "['end_time' => ['>', \$begin]]"));
$check('detalhes contêm vínculos e projetos', str_contains($modal, 'ap-details-toggle') && str_contains($modal, 'ap-create-details') && str_contains($modal, 'Registro vinculado') && str_contains($modal, 'Tarefa do projeto'));
$check('campos ocultos não reservam espaço', str_contains($css, '.ap-create-details[hidden] { display: none !important; }') && str_contains($css, '.ap-modal-linked-picker[hidden]'));
$check('erro mantém dados e modal aberto', str_contains($calendar, 'showModalError(') && !preg_match('/showModalError\([^;]+closeCreateModal/s', $calendar));
$check('erro de vínculo abre detalhes', str_contains($endpoint, "'show_details'") && str_contains($calendar, 'if (showDetails) setDetailsExpanded(true)'));
$check('sucesso fecha o modal', str_contains($calendar, 'closeCreateModal(true)'));
$check('sucesso atualiza eventos totais e cores', str_contains($calendar, 'await loadEvents();') && str_contains($calendar, 'applyScheduleColor'));
$check('usuário período e visualização são preservados', str_contains($calendar, 'currentModalUser()') && !str_contains($calendar, 'location.reload(') && str_contains($calendar, 'let view ='));
$check('envio duplicado é impedido', str_contains($calendar, 'if (!createForm || modalSubmitting) return') && str_contains($calendar, 'modalSave.disabled = submitting'));
$check('CSRF inválido é rejeitado', str_contains($endpoint, 'Session::validateCSRF($_POST, true)') && str_contains($page, 'data-create-csrf-token'));
$check('permissões são validadas no servidor', str_contains($endpoint, 'PluginApontamentosAppointment::canCreate()') && str_contains($appointment, 'self::canManageOthers()'));
$check('endpoint responde somente JSON', str_contains($endpoint, 'ob_start();') && str_contains($endpoint, 'plugin_apontamentos_create_response') && substr_count($endpoint, 'echo json_encode') === 1);
$check('mensagem de sucesso não contém ID', str_contains($endpoint, "'id' => (int) \$newId") && !str_contains($endpoint, 'Apontamento - ID'));
$check('modal é acessível e responsivo', str_contains($modal, "role='dialog' aria-modal='true' aria-labelledby='ap-create-title'") && str_contains($css, '@media (max-width: 620px)') && str_contains($calendar, "event.key === 'Escape'"));
$check('descarte não usa confirmação nativa do navegador', !str_contains($calendar, "window.confirm('Descartar as alterações deste apontamento?')"));
$check('confirmação de descarte é tratada no plugin', str_contains($modal, "role='alertdialog'") && str_contains($modal, 'Descartar alterações?') && str_contains($modal, 'As informações preenchidas neste apontamento serão perdidas.'));
$check('confirmação permite continuar ou descartar', str_contains($modal, "data-modal-action='continue-editing'") && str_contains($modal, "data-modal-action='discard'") && str_contains($calendar, "action === 'discard'"));
$check('confirmação possui foco e Escape tratados', str_contains($calendar, 'openDiscardConfirmation()') && str_contains($calendar, 'closeDiscardConfirmation(true)') && str_contains($calendar, 'discardDialog : createDialog'));
$check('confirmação de descarte é responsiva', str_contains($css, '.ap-discard-confirm[hidden]') && str_contains($css, '.ap-discard-dialog') && str_contains($css, '.ap-discard-actions'));
$check('testes não criam apontamentos', !preg_match('/->add\(|\$DB->(?:insert|update|delete)/', (string) file_get_contents(__FILE__)));
$check('nenhuma tabela ou coluna é alterada', !preg_match('/ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE|ADD\s+COLUMN/i', $modal . $endpoint . $calendar . $css));

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
echo 'OK - ' . count($checks) . " verificações do modal de criação concluídas.\n";
