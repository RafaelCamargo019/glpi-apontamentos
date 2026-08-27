<?php

use Glpi\Event;

include '../../../inc/includes.php';

$appointment = new PluginApontamentosAppointment();
$id = (int) ($_REQUEST['id'] ?? 0);
$formOptions = [];
$requestInput = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
$contextRequested = PluginApontamentosItemTab::contextRequested($requestInput);
$context = $contextRequested
    ? PluginApontamentosItemTab::contextFromInput($requestInput)
    : null;
$contextRejected = $contextRequested && $context === null;

// Para registros existentes, o contexto precisa corresponder ao vínculo que
// já está salvo. Isso impede que parâmetros adulterados escolham outro retorno.
if (!$contextRejected && $context !== null && $id > 0) {
    $storedAppointment = new PluginApontamentosAppointment();
    if (!$storedAppointment->getFromDB($id)
        || !PluginApontamentosItemTab::appointmentBelongsToContext($storedAppointment->fields, $context)) {
        $context = null;
        $contextRejected = true;
    }
}

if ($contextRejected) {
    Session::addMessageAfterRedirect(
        __('O registro de origem é inválido ou não está mais acessível.', 'apontamentos'),
        false,
        ERROR
    );
    if (isset($_POST['delete']) || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        Html::redirect(PluginApontamentosAppointment::getSearchURL());
    }
    // Não execute a gravação desta requisição. O preenchimento é mantido para
    // que o usuário possa corrigir o vínculo no formulário oficial do plugin.
    $formOptions['form_input'] = $_POST;
}

if (!$contextRejected && isset($_POST['add'])) {
    Session::checkRight(PluginApontamentosAppointment::$rightname, CREATE);
    $addInput = $context !== null && PluginApontamentosItemTab::supportsModalContext($context)
        ? PluginApontamentosItemTab::bindInputToContext($_POST, $context)
        : $_POST;
    $appointment->check(-1, CREATE, $addInput);
    $newId = $appointment->add($addInput);
    if ($newId) {
        Event::log($newId, 'apontamentos', 4, 'plugins', 'Criação de apontamento');
        $appointment->getFromDB($newId);
        $savedUser = (int) $appointment->fields['users_id'];
        $savedDate = substr((string) $appointment->fields['begin_time'], 0, 10);
        Session::addMessageAfterRedirect(__('Apontamento salvo com sucesso.', 'apontamentos'), true, INFO);
        if ($context !== null
            && PluginApontamentosItemTab::appointmentBelongsToContext($appointment->fields, $context)) {
            Html::redirect(PluginApontamentosItemTab::contextUrl($context));
        }
        Html::redirect(PluginApontamentosAppointment::getSearchURL()
            . '?users_id=' . $savedUser . '&date=' . rawurlencode($savedDate));
    }
    // A validação pode falhar depois que o usuário já preencheu vários
    // seletores. Reexiba a mesma submissão em vez de abrir um formulário vazio.
    $formOptions['form_input'] = $addInput;
} elseif (!$contextRejected && isset($_POST['update'])) {
    $appointment->check($id, UPDATE);
    if ($appointment->update($_POST)) {
        Event::log($id, 'apontamentos', 4, 'plugins', 'Atualização de apontamento');
        $appointment->getFromDB($id);
        $savedUser = (int) $appointment->fields['users_id'];
        $savedDate = substr((string) $appointment->fields['begin_time'], 0, 10);
        Session::addMessageAfterRedirect(__('Apontamento atualizado com sucesso.', 'apontamentos'), true, INFO);
        if ($context !== null
            && PluginApontamentosItemTab::appointmentBelongsToContext($appointment->fields, $context)) {
            Html::redirect(PluginApontamentosItemTab::contextUrl($context));
        }
        Html::redirect(PluginApontamentosAppointment::getSearchURL()
            . '?users_id=' . $savedUser . '&date=' . rawurlencode($savedDate));
    }
    $formOptions['form_input'] = $_POST;
} elseif (!$contextRejected && isset($_POST['delete'])) {
    if (!$appointment->getFromDB($id)
        || !PluginApontamentosAppointment::canDeleteStoredFields($appointment->fields)) {
        Session::addMessageAfterRedirect(__('Você não possui permissão para excluir este apontamento.', 'apontamentos'), true, ERROR);
        Html::redirect(PluginApontamentosAppointment::getSearchURL());
    }
    $deletedFields = $appointment->fields;
    if (!PluginApontamentosAppointment::deletePermanently($id)) {
        Session::addMessageAfterRedirect(__('Não foi possível excluir o apontamento.', 'apontamentos'), true, ERROR);
        Html::redirect(PluginApontamentosAppointment::getSearchURL());
    }
    Event::log($id, 'apontamentos', 4, 'plugins', 'Exclusão definitiva de apontamento');
    Session::addMessageAfterRedirect(__('Apontamento excluído com sucesso.', 'apontamentos'), true, INFO);
    if ($context !== null
        && PluginApontamentosItemTab::appointmentBelongsToContext($deletedFields, $context)) {
        Html::redirect(PluginApontamentosItemTab::contextUrl($context));
    }
    Html::redirect(PluginApontamentosAppointment::getSearchURL());
}

Session::checkRight(PluginApontamentosAppointment::$rightname, READ);
if ($id > 0 && $appointment->getFromDB($id)
    && !empty($appointment->fields['is_deleted'])) {
    Session::addMessageAfterRedirect(__('Este apontamento foi excluído.', 'apontamentos'), false, ERROR);
    Html::redirect(PluginApontamentosAppointment::getSearchURL());
}
if ($context !== null) {
    $formOptions['context'] = $context;
    if ($id === 0 && !isset($formOptions['form_input'])) {
        $formOptions['form_input'] = PluginApontamentosItemTab::prefillForContext($context);
    }
}
Html::header(__('Apontamento', 'apontamentos'), $_SERVER['PHP_SELF'], 'helpdesk', 'PluginApontamentosAppointment');
$appointment->showForm($id, $formOptions);
Html::footer();
