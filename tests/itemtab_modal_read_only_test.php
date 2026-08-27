<?php

$root = dirname(__DIR__);
$setup = (string) file_get_contents($root . '/setup.php');
$itemTab = (string) file_get_contents($root . '/inc/itemtab.class.php');
$modal = (string) file_get_contents($root . '/inc/appointmentmodal.class.php');
$fallback = (string) file_get_contents($root . '/front/appointment.form.php');
$create = (string) file_get_contents($root . '/ajax/create_context.php');
$refresh = (string) file_get_contents($root . '/ajax/itemtab.php');
$javascript = (string) file_get_contents($root . '/js/itemtab.js');
$css = (string) file_get_contents($root . '/css/calendar.css');
$errors = [];
$checks = [];
$check = static function (string $label, bool $condition) use (&$errors, &$checks): void {
    $checks[] = $label;
    if (!$condition) $errors[] = 'Falhou: ' . $label;
};

$check('modal disponível em Chamado Problema Mudança e Projeto', str_contains($itemTab, 'public const MODAL_ITEMTYPES')
    && str_contains($itemTab, "'Ticket',\n        'Problem',\n        'Change',\n        'Project',"));
$check('recursos carregados nas quatro páginas GLPI', str_contains($setup, "(ticket|problem|change|project)\\.form\\.php")
    && str_contains($setup, "front/itemtab.js.php"));
$check('botão mantém fallback e recebe ação de modal', str_contains($itemTab, "data-ap-action='open-create'")
    && str_contains($itemTab, 'PluginApontamentosAppointment::getFormURL()'));
$check('aba fornece endpoints e contexto ao JavaScript', str_contains($itemTab, "data-create-endpoint='/plugins/apontamentos/ajax/create_context.php'")
    && str_contains($itemTab, "data-refresh-endpoint='/plugins/apontamentos/ajax/itemtab.php'")
    && str_contains($itemTab, 'data-context-itemtype'));
$check('mesmo modal é renderizado sobre a aba', str_contains($itemTab, 'PluginApontamentosAppointmentModal::render(')
    && str_contains($itemTab, '$csrfToken,') && str_contains($itemTab, '$context'));
$check('modal recebe contexto fixo opcional', str_contains($modal, '?array $fixedContext = null')
    && str_contains($modal, "name='context_itemtype'") && str_contains($modal, "name='context_items_id'"));
$check('vínculo fixo é exibido e bloqueado', str_contains($modal, 'ap-fixed-link')
    && str_contains($modal, "ti ti-lock") && str_contains($modal, "name='linked_"));
$check('modal contextual não oferece projeto alternativo', str_contains($modal, '$canUseProjects = $fixedContext === null')
    && str_contains($modal, '$fixedIsProject') && str_contains($modal, 'ap-fixed-link'));
$check('servidor centraliza a imposição do vínculo', str_contains($itemTab, 'function bindInputToContext')
    && str_contains($itemTab, "unset(\$input['itemtype'], \$input['items_id'])")
    && str_contains($itemTab, "\$input['link_type'] = \$itemtype"));
$check('campos forjados de outros vínculos são descartados', str_contains($itemTab, "unset(\$input['linked_' . \$itemtype])")
    && str_contains($itemTab, "\$input['users_id'] = Session::getLoginUserID()")
    && str_contains($itemTab, "\$input['projects_id'] = 0")
    && str_contains($itemTab, "\$input['projecttasks_id'] = 0"));
$check('contexto de projeto fixa projeto e preserva tarefa opcional', str_contains($itemTab, "if (\$itemtype === 'Project')")
    && str_contains($itemTab, "\$input['projects_id'] = (int) \$context['items_id']")
    && str_contains($itemTab, "\$input['projecttasks_id'] = \$taskId === false ? 0 : (int) \$taskId"));
$check('modal de projeto oferece somente tarefa opcional', str_contains($modal, "__('opcional', 'apontamentos')")
    && str_contains($modal, "name='projecttasks_id'") && str_contains($modal, "name='projects_id' value='"));
$check('endpoint contextual aceita somente POST', str_contains($create, "REQUEST_METHOD")
    && str_contains($create, "!== 'POST'") && str_contains($create, '405'));
$check('endpoint contextual valida CSRF e criação', str_contains($create, 'Session::validateCSRF($_POST, true)')
    && str_contains($create, 'PluginApontamentosAppointment::canCreate()'));
$check('endpoint contextual exige origem válida', str_contains($create, 'PluginApontamentosItemTab::contextFromInput($_POST)')
    && str_contains($create, 'PluginApontamentosItemTab::supportsModalContext($context)'));
$bindPosition = strpos($create, 'bindInputToContext($_POST, $context)');
$addPosition = strpos($create, '$appointment->add($input)');
$check('endpoint contextual força vínculo antes de gravar', $bindPosition !== false
    && $addPosition !== false && $bindPosition < $addPosition);
$check('fallback também força o vínculo da origem', str_contains($fallback, 'PluginApontamentosItemTab::bindInputToContext($_POST, $context)')
    && str_contains($fallback, '$appointment->add($addInput)'));
$check('atualização da lista é somente GET', str_contains($refresh, "!== 'GET'")
    && str_contains($refresh, 'Session::haveRight') && str_contains($refresh, 'READ'));
$check('atualização revalida acesso ao registro', str_contains($refresh, 'contextFromInput($_GET)')
    && str_contains($refresh, 'canAccessItem($item)'));
$check('painel é renderizado novamente pelo servidor', str_contains($refresh, 'PluginApontamentosItemTab::renderPanel($item, $context)')
    && str_contains($itemTab, 'public static function renderPanel'));
$check('clique abre modal sem navegar', str_contains($javascript, 'event.preventDefault()')
    && str_contains($javascript, 'instanceFor(root)?.open(trigger)')
    && !preg_match('/window\.location\.(?:href\s*=|reload\s*\()/', $javascript));
$check('salvamento usa AJAX e FormData', str_contains($javascript, "method: 'POST'")
    && str_contains($javascript, 'body: new FormData(createForm)'));
$check('lista é atualizada sem recarregar a página', str_contains($javascript, 'await refreshPanel()')
    && str_contains($javascript, 'panel.innerHTML = result.html'));
$check('erro mantém modal e valores', str_contains($javascript, 'showModalError(')
    && !preg_match('/showModalError\([^;]+close\(true\)/s', $javascript));
$check('descarte usa confirmação tratada', str_contains($javascript, 'openDiscardConfirmation()')
    && !str_contains($javascript, 'window.confirm('));
$check('teclado e foco permanecem tratados', str_contains($javascript, "event.key === 'Escape'")
    && str_contains($javascript, 'focusableElements()'));
$check('vínculo fixo possui apresentação visual', str_contains($css, '.ap-fixed-link-label')
    && str_contains($css, '.ap-fixed-link'));
$check('teste não grava dados', !preg_match('/^\s*\$[A-Za-z_][A-Za-z0-9_]*->add\(/m', (string) file_get_contents(__FILE__))
    && !preg_match('/^\s*\$DB->(?:insert|update|delete)\(/m', (string) file_get_contents(__FILE__)));
$check('implementação não altera esquema', !preg_match('/ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE|ADD\s+COLUMN/i', $itemTab . $modal . $create . $refresh . $javascript));

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
echo 'OK - ' . count($checks) . " verificações do modal contextual concluídas.\n";
