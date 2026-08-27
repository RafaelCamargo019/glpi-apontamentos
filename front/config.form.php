<?php

use Glpi\Event;

include '../../../inc/includes.php';

Session::checkRight(PluginApontamentosConfig::$rightname, UPDATE);
global $DB;

function plugin_apontamentos_config_user($value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $user = new User();
    if ($id === false || !$user->getFromDB((int) $id)
        || empty($user->fields['is_active']) || !empty($user->fields['is_deleted'])) {
        throw new RuntimeException(__('Usuário inválido ou inativo.', 'apontamentos'));
    }
    foreach (array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? [])) as $entityId) {
        if (Session::haveAccessToEntity($entityId)
            && PluginApontamentosAppointment::userCanUseEntity((int) $id, $entityId)) {
            return (int) $id;
        }
    }
    throw new RuntimeException(__('Usuário fora do escopo de entidades autorizado.', 'apontamentos'));
}

function plugin_apontamentos_config_type_id($value, bool $allowZero = false): int
{
    $minimum = $allowZero ? 0 : 1;
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum]]);
    if ($id === false) {
        throw new RuntimeException(__('Tipo de apontamento inválido.', 'apontamentos'));
    }
    if ((int) $id > 0 && PluginApontamentosAppointmentType::getRecord((int) $id) === null) {
        throw new RuntimeException(__('Tipo de apontamento inexistente.', 'apontamentos'));
    }
    return (int) $id;
}

function plugin_apontamentos_config_schedule(array $input): ?array
{
    $values = [];
    foreach (PluginApontamentosConfig::DAYS as $column) {
        $hours = filter_var($input[str_replace('_minutes', '_hours', $column)] ?? null, FILTER_VALIDATE_FLOAT);
        if ($hours === false || $hours < 0 || $hours > 24) {
            return null;
        }
        $values[$column] = (int) round($hours * 60);
    }
    return PluginApontamentosConfig::scheduleInput($values);
}

function plugin_apontamentos_config_url(int $userId, int $typeId = 0): string
{
    $url = '/plugins/apontamentos/front/config.form.php?schedule_user_id=' . $userId;
    return $typeId > 0 ? $url . '&edit_type_id=' . $typeId : $url;
}

$selectedUser = Session::getLoginUserID();
try {
    $selectedUser = plugin_apontamentos_config_user($_GET['schedule_user_id'] ?? $selectedUser);
} catch (RuntimeException) {
    $selectedUser = Session::getLoginUserID();
}

$editTypeId = 0;
try {
    $editTypeId = plugin_apontamentos_config_type_id($_GET['edit_type_id'] ?? 0, true);
} catch (RuntimeException $error) {
    Session::addMessageAfterRedirect($error->getMessage(), false, ERROR);
}

if (isset($_POST['save_type'])) {
    try {
        $typeId = plugin_apontamentos_config_type_id($_POST['types_id'] ?? 0, true);
        $name = PluginApontamentosAppointmentType::normalizeName($_POST['name'] ?? '');
        $color = PluginApontamentosConfig::validColor($_POST['color'] ?? null);
        if ($name === null) {
            throw new RuntimeException(__('Informe um nome de até 255 caracteres.', 'apontamentos'));
        }
        if ($color === null) {
            throw new RuntimeException(__('Informe uma cor hexadecimal válida no formato #RRGGBB.', 'apontamentos'));
        }
        $now = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        if ($typeId > 0) {
            $record = PluginApontamentosAppointmentType::getRecord($typeId);
            if ($record === null || !empty($record['is_deleted'])) {
                throw new RuntimeException(__('Tipo de apontamento inexistente ou excluído.', 'apontamentos'));
            }
            $saved = $DB->update(PluginApontamentosAppointmentType::getTable(), [
                'name' => $name,
                'color' => $color,
                'date_mod' => $now,
            ], ['id' => $typeId]);
            $message = __('Tipo de apontamento atualizado.', 'apontamentos');
        } else {
            $saved = $DB->insert(PluginApontamentosAppointmentType::getTable(), [
                'name' => $name,
                'color' => $color,
                'is_active' => 1,
                'is_deleted' => 0,
                'date_creation' => $now,
                'date_mod' => $now,
            ]);
            if ($saved) {
                $typeId = (int) $DB->insertId();
            }
            $message = __('Tipo de apontamento criado.', 'apontamentos');
        }
        if (!$saved) {
            throw new RuntimeException(__('Não foi possível salvar o tipo de apontamento.', 'apontamentos'));
        }
        Event::log($typeId, 'apontamentos', 4, 'plugins', 'Cadastro de tipo de apontamento');
        Session::addMessageAfterRedirect($message);
    } catch (RuntimeException $error) {
        Session::addMessageAfterRedirect($error->getMessage(), false, ERROR);
    }
    Html::redirect(plugin_apontamentos_config_url($selectedUser));
}

if (isset($_POST['set_type_active'])) {
    try {
        $typeId = plugin_apontamentos_config_type_id($_POST['types_id'] ?? null);
        $record = PluginApontamentosAppointmentType::getRecord($typeId);
        if ($record === null || !empty($record['is_deleted'])) {
            throw new RuntimeException(__('Tipo de apontamento inexistente ou excluído.', 'apontamentos'));
        }
        $active = filter_var($_POST['is_active'] ?? null, FILTER_VALIDATE_INT);
        if ($active !== 0 && $active !== 1) {
            throw new RuntimeException(__('Estado do tipo de apontamento inválido.', 'apontamentos'));
        }
        if (!$DB->update(PluginApontamentosAppointmentType::getTable(), [
            'is_active' => $active,
            'date_mod' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
        ], ['id' => $typeId])) {
            throw new RuntimeException(__('Não foi possível alterar o tipo de apontamento.', 'apontamentos'));
        }
        Event::log($typeId, 'apontamentos', 4, 'plugins', $active ? 'Tipo ativado' : 'Tipo desativado');
        Session::addMessageAfterRedirect($active
            ? __('Tipo de apontamento ativado.', 'apontamentos')
            : __('Tipo de apontamento desativado.', 'apontamentos'));
    } catch (RuntimeException $error) {
        Session::addMessageAfterRedirect($error->getMessage(), false, ERROR);
    }
    Html::redirect(plugin_apontamentos_config_url($selectedUser));
}

if (isset($_POST['delete_type'])) {
    try {
        $typeId = plugin_apontamentos_config_type_id($_POST['types_id'] ?? null);
        $record = PluginApontamentosAppointmentType::getRecord($typeId);
        if ($record === null || !empty($record['is_deleted'])) {
            throw new RuntimeException(__('Tipo de apontamento inexistente ou já excluído.', 'apontamentos'));
        }
        if (!$DB->update(PluginApontamentosAppointmentType::getTable(), [
            'is_active' => 0,
            'is_deleted' => 1,
            'date_mod' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
        ], ['id' => $typeId])) {
            throw new RuntimeException(__('Não foi possível excluir o tipo de apontamento.', 'apontamentos'));
        }
        Event::log($typeId, 'apontamentos', 4, 'plugins', 'Exclusão lógica de tipo de apontamento');
        Session::addMessageAfterRedirect(__('Tipo de apontamento excluído.', 'apontamentos'));
    } catch (RuntimeException $error) {
        Session::addMessageAfterRedirect($error->getMessage(), false, ERROR);
    }
    Html::redirect(plugin_apontamentos_config_url($selectedUser));
}

if (isset($_POST['save_user'])) {
    try {
        $userId = plugin_apontamentos_config_user($_POST['users_id'] ?? null);
        $selectedUser = $userId;
        $schedule = plugin_apontamentos_config_schedule($_POST);
        if ($schedule === null) {
            throw new RuntimeException(__('Informe uma jornada válida entre 0 e 24 horas para cada dia.', 'apontamentos'));
        }
        $table = 'glpi_plugin_apontamentos_userschedules';
        if (!$DB->tableExists($table)) {
            throw new RuntimeException(__('A atualização da estrutura do plugin ainda não foi aplicada.', 'apontamentos'));
        }
        $now = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM' => $table,
            'WHERE' => ['users_id' => $userId],
            'LIMIT' => 1,
        ])->current();
        $values = $schedule + ['date_mod' => $now];
        if ($existing) {
            $saved = $DB->update($table, $values, ['id' => (int) $existing['id']]);
        } else {
            $saved = $DB->insert($table, ['users_id' => $userId] + $values + ['date_creation' => $now]);
        }
        if (!$saved) {
            throw new RuntimeException(__('Não foi possível salvar a jornada do usuário.', 'apontamentos'));
        }
        Event::log($userId, 'apontamentos', 4, 'plugins', 'Configuração atual de jornada global do usuário');
        Session::addMessageAfterRedirect(__('Jornada do usuário salva.', 'apontamentos'));
    } catch (RuntimeException $error) {
        Session::addMessageAfterRedirect($error->getMessage(), false, ERROR);
    }
    Html::redirect(plugin_apontamentos_config_url($selectedUser));
}

function plugin_apontamentos_schedule_fields(array $schedule): void
{
    $labels = [
        'monday' => 'Segunda',
        'tuesday' => 'Terça',
        'wednesday' => 'Quarta',
        'thursday' => 'Quinta',
        'friday' => 'Sexta',
        'saturday' => 'Sábado',
        'sunday' => 'Domingo',
    ];
    echo "<div class='row g-2 mt-2'>";
    foreach ($labels as $day => $label) {
        $column = $day . '_minutes';
        $hours = number_format(((int) ($schedule[$column] ?? 0)) / 60, 2, '.', '');
        echo "<div class='col'><label class='form-label'>" . htmlescape(__($label, 'apontamentos'))
            . "</label><input class='form-control' type='number' name='{$day}_hours' min='0' max='24' step='.25' value='"
            . htmlescape($hours) . "' required></div>";
    }
    echo '</div>';
}

$editType = $editTypeId > 0 ? PluginApontamentosAppointmentType::getRecord($editTypeId) : null;
$userSchedule = PluginApontamentosConfig::scheduleForUser($selectedUser)
    ?? PluginApontamentosConfig::defaultSchedule();

Html::header(__('Configuração de apontamentos', 'apontamentos'), $_SERVER['PHP_SELF'], 'helpdesk', 'PluginApontamentosAppointment');
echo "<div class='container-xl'>";

echo "<div class='card mb-3'><div class='card-header'><h3 class='card-title'>"
    . __('Tipos de apontamento', 'apontamentos') . "</h3></div><div class='card-body'>";
echo "<form method='post' class='row g-3 align-items-end mb-4'>";
echo Html::hidden('types_id', ['value' => $editTypeId]);
echo "<div class='col-md-6'><label class='form-label'>" . __('Nome', 'apontamentos')
    . "</label><input class='form-control' type='text' name='name' maxlength='255' value='"
    . htmlescape((string) ($editType['name'] ?? '')) . "' required></div>";
echo "<div class='col-md-2'><label class='form-label'>" . __('Cor', 'apontamentos')
    . "</label><input class='form-control form-control-color' type='color' name='color' value='"
    . htmlescape(PluginApontamentosConfig::validColor($editType['color'] ?? '')
        ?? PluginApontamentosConfig::DEFAULT_COLOR)
    . "' pattern='#[0-9A-Fa-f]{6}' required></div>";
echo "<div class='col-md-4'><button class='btn btn-primary' name='save_type'><i class='ti ti-device-floppy'></i> "
    . ($editType ? __('Salvar alterações', 'apontamentos') : __('Adicionar tipo', 'apontamentos')) . '</button>';
if ($editType) {
    echo " <a class='btn btn-outline-secondary' href='" . htmlescape(plugin_apontamentos_config_url($selectedUser)) . "'>"
        . __('Cancelar edição', 'apontamentos') . '</a>';
}
echo '</div>';
Html::closeForm();

echo "<div class='table-responsive'><table class='table table-hover align-middle'><thead><tr><th>"
    . __('Nome', 'apontamentos') . '</th><th>' . __('Cor', 'apontamentos') . '</th><th>'
    . __('Estado', 'apontamentos') . '</th><th class="text-end">' . __('Ações', 'apontamentos')
    . '</th></tr></thead><tbody>';
$listedTypes = $DB->request([
    'FROM' => PluginApontamentosAppointmentType::getTable(),
    'WHERE' => ['is_deleted' => 0],
    'ORDER' => ['name ASC', 'id ASC'],
]);
foreach ($listedTypes as $type) {
    $typeId = (int) $type['id'];
    $active = !empty($type['is_active']);
    $color = PluginApontamentosConfig::validColor($type['color'] ?? '') ?? PluginApontamentosConfig::DEFAULT_COLOR;
    echo '<tr><td>' . htmlescape((string) $type['name']) . "</td><td><span class='badge' style='background:"
        . htmlescape($color) . "'>&nbsp;&nbsp;&nbsp;</span> " . htmlescape($color) . '</td><td>'
        . ($active ? __('Ativo', 'apontamentos') : __('Inativo', 'apontamentos'))
        . "</td><td class='text-end'>";
    echo "<a class='btn btn-sm btn-outline-primary' href='"
        . htmlescape(plugin_apontamentos_config_url($selectedUser, $typeId))
        . "'><i class='ti ti-pencil'></i> " . __('Editar', 'apontamentos') . '</a> ';
    echo "<form method='post' class='d-inline'>";
    echo Html::hidden('types_id', ['value' => $typeId]);
    echo Html::hidden('is_active', ['value' => $active ? 0 : 1]);
    echo "<button class='btn btn-sm btn-outline-secondary' name='set_type_active'><i class='ti ti-power'></i> "
        . ($active ? __('Desativar', 'apontamentos') : __('Ativar', 'apontamentos')) . '</button>';
    Html::closeForm();
    echo " <form method='post' class='d-inline' onsubmit=\"return confirm('"
        . htmlescape(__('Excluir este tipo de apontamento?', 'apontamentos')) . "');\">";
    echo Html::hidden('types_id', ['value' => $typeId]);
    echo "<button class='btn btn-sm btn-outline-danger' name='delete_type'><i class='ti ti-trash'></i> "
        . __('Excluir', 'apontamentos') . '</button>';
    Html::closeForm();
    echo '</td></tr>';
}
if (count($listedTypes) === 0) {
    echo "<tr><td colspan='4' class='text-muted'>" . __('Nenhum tipo cadastrado.', 'apontamentos') . '</td></tr>';
}
echo '</tbody></table></div></div></div>';

echo "<div class='card'><div class='card-header'><h3 class='card-title'>"
    . __('Jornada atual por usuário', 'apontamentos') . "</h3></div><div class='card-body'>";
echo "<form method='get' class='ap-config-selector mb-3'>";
echo "<label class='form-label'>" . __('Usuário', 'apontamentos') . '</label>';
User::dropdown([
    'name' => 'schedule_user_id',
    'value' => $selectedUser,
    'entity' => $_SESSION['glpiactiveentities'],
    'right' => 'all',
]);
echo '</form>';
echo "<form method='post'>";
echo Html::hidden('users_id', ['value' => $selectedUser]);
plugin_apontamentos_schedule_fields($userSchedule);
echo "<button class='btn btn-primary mt-3' name='save_user'><i class='ti ti-device-floppy'></i> "
    . __('Salvar jornada do usuário', 'apontamentos') . '</button>';
Html::closeForm();
echo '</div></div></div>';

echo <<<'HTML'
<script>
(() => {
  document.querySelectorAll('.ap-config-selector select').forEach(select => {
    let submitted = false;
    const reloadSelection = () => {
      if (submitted) return;
      submitted = true;
      select.form?.requestSubmit();
    };
    select.addEventListener('change', reloadSelection);
    if (window.jQuery) window.jQuery(select).on('select2:select', reloadSelection);
  });
})();
</script>
HTML;

Html::footer();
