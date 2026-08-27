<?php

include '../../../inc/includes.php';

Session::checkRight(PluginApontamentosAppointment::$rightname, READ);
$deleteCsrfToken = Session::getNewCSRFToken(true);
$createCsrfToken = Session::getNewCSRFToken(true);
Html::header(__('Apontamentos', 'apontamentos'), $_SERVER['PHP_SELF'], 'helpdesk', 'PluginApontamentosAppointment');

$canManageOthers = PluginApontamentosAppointment::canManageOthers();
$canCreate = PluginApontamentosAppointment::canCreate();
$canExport = Session::haveRight(PluginApontamentosReport::$rightname, READ);
$canConfigure = Session::haveRight(PluginApontamentosConfig::$rightname, UPDATE);
$activeEntity = (int) ($_SESSION['glpiactive_entity'] ?? 0);
$calendarUser = Session::getLoginUserID();
if ($canManageOthers) {
    $requestedUser = filter_var($_GET['users_id'] ?? $calendarUser, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($requestedUser !== false && PluginApontamentosAppointment::userCanUseEntity((int) $requestedUser, $activeEntity)) {
        $calendarUser = (int) $requestedUser;
    }
}
$focusDate = (string) ($_GET['date'] ?? date('Y-m-d'));
$focusDateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $focusDate);
$focusErrors = DateTimeImmutable::getLastErrors();
if (!$focusDateObject || ($focusErrors !== false && ($focusErrors['warning_count'] || $focusErrors['error_count']))
    || $focusDateObject->format('Y-m-d') !== $focusDate) {
    $focusDate = date('Y-m-d');
}
$initialView = (string) ($_GET['view'] ?? 'week');
if (!in_array($initialView, ['month', 'week', 'day'], true)) {
    $initialView = 'week';
}

echo "<div id='apontamentos-calendar' class='ap-calendar card'"
    . " data-endpoint='/plugins/apontamentos/ajax/events.php'"
    . " data-delete-endpoint='/plugins/apontamentos/ajax/delete.php'"
    . " data-create-endpoint='/plugins/apontamentos/ajax/create.php'"
    . " data-form-url='" . htmlescape(PluginApontamentosAppointment::getFormURL()) . "'"
    // Token independente: o token padrão do request também é usado por
    // componentes do GLPI e pode ser consumido antes do clique na lixeira.
    . " data-csrf-token='" . htmlescape($deleteCsrfToken) . "'"
    . " data-create-csrf-token='" . htmlescape($createCsrfToken) . "'"
    . " data-can-create='" . ($canCreate ? '1' : '0') . "'"
    . " data-can-manage-others='" . ($canManageOthers ? '1' : '0') . "'"
    . " data-current-user='" . Session::getLoginUserID() . "'"
    . " data-focus-date='" . htmlescape($focusDate) . "'"
    . " data-initial-view='" . htmlescape($initialView) . "'"
    . " data-active-entity='" . $activeEntity . "'>";

echo "<div class='ap-toolbar'>";
echo "<div class='ap-nav btn-group' role='group' aria-label='" . htmlescape(__('Navegação do calendário', 'apontamentos')) . "'>";
echo "<button type='button' class='btn btn-outline-secondary' data-action='prev' aria-label='" . htmlescape(__('Período anterior', 'apontamentos')) . "'><i class='ti ti-chevron-left'></i></button>";
echo "<button type='button' class='btn btn-outline-secondary' data-action='next' aria-label='" . htmlescape(__('Próximo período', 'apontamentos')) . "'><i class='ti ti-chevron-right'></i></button>";
echo "<button type='button' class='btn btn-outline-secondary ms-2' data-action='today'>" . __('Hoje', 'apontamentos') . '</button></div>';
echo "<h2 class='ap-title' aria-live='polite'></h2>";
echo "<div class='ap-views btn-group' role='group' aria-label='" . htmlescape(__('Visualização', 'apontamentos')) . "'>";
foreach (['month' => __('Mês', 'apontamentos'), 'week' => __('Semana', 'apontamentos'), 'day' => __('Dia', 'apontamentos')] as $view => $label) {
    echo "<button type='button' class='btn btn-outline-secondary' data-view='" . $view . "'>" . $label . '</button>';
}
echo "<button type='button' class='btn btn-outline-secondary ms-2' data-action='refresh' aria-label='" . htmlescape(__('Atualizar', 'apontamentos')) . "'><i class='ti ti-refresh'></i></button></div>";
echo '</div>';

echo "<div class='ap-filters'>";
if ($canManageOthers) {
    echo "<div><label for='ap-user-filter'>" . __('Usuário', 'apontamentos') . "</label>";
    User::dropdown([
        'name' => 'ap_user_filter',
        'value' => $calendarUser,
        'entity' => $activeEntity,
        'right' => 'all',
        'display_emptychoice' => false,
    ]);
    echo '</div>';
}
if ($canCreate) {
    echo "<a class='btn btn-primary ap-new' data-action='open-create' href='" . htmlescape(PluginApontamentosAppointment::getFormURL()) . "'><i class='ti ti-plus'></i> " . __('Novo apontamento', 'apontamentos') . '</a>';
}
if ($canExport) {
    echo "<a class='btn btn-outline-primary' href='/plugins/apontamentos/front/report.php'><i class='ti ti-file-spreadsheet'></i> " . __('Relatório / Exportar', 'apontamentos') . '</a>';
}
if ($canConfigure) {
    echo "<a class='btn btn-outline-secondary' href='/plugins/apontamentos/front/config.form.php'><i class='ti ti-settings'></i> " . __('Configurar', 'apontamentos') . '</a>';
}
echo '</div>';

echo "<div class='ap-loading' role='status' aria-live='polite'><span class='spinner-border spinner-border-sm'></span> " . __('Carregando apontamentos…', 'apontamentos') . '</div>';
echo "<div class='ap-error alert alert-important alert-danger alert-dismissible' role='alert' hidden><i class='ti ti-alert-circle me-2' aria-hidden='true'></i><span class='ap-message-text'></span><button type='button' class='btn-close' aria-label='" . htmlescape(__('Fechar', 'apontamentos')) . "'></button></div>";
echo "<div class='ap-success alert alert-important alert-success alert-dismissible' role='status' aria-live='polite' hidden><i class='ti ti-circle-check me-2' aria-hidden='true'></i><span class='ap-message-text'></span><button type='button' class='btn-close' aria-label='" . htmlescape(__('Fechar', 'apontamentos')) . "'></button></div>";
echo "<div class='ap-calendar-body' aria-label='" . htmlescape(__('Calendário de apontamentos', 'apontamentos')) . "'></div>";
echo "<div class='ap-period-total'><span>" . __('Total do período', 'apontamentos') . "</span><strong>0h 00m</strong></div>";
if ($canCreate) {
    PluginApontamentosAppointmentModal::render($calendarUser, $createCsrfToken);
}
echo '</div>';
Html::footer();
