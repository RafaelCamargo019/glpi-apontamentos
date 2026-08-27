<?php

final class PluginApontamentosAppointmentModal
{
    public static function render(int $calendarUser, string $csrfToken, ?array $fixedContext = null): void
    {
        $typeOptions = PluginApontamentosAppointmentType::activeOptions();
        $allowedLinks = PluginApontamentosAppointment::getAllowedItemtypes();
        $entityId = (int) ($_SESSION['glpiactive_entity'] ?? -1);
        $fixedItemtype = trim((string) ($fixedContext['itemtype'] ?? ''));
        $fixedItemsId = (int) ($fixedContext['items_id'] ?? 0);
        $fixedIsProject = $fixedItemtype === 'Project';
        $fixedTypeAllowed = isset($allowedLinks[$fixedItemtype])
            || ($fixedIsProject && PluginApontamentosAppointment::canUseProjects());
        if ($fixedItemsId <= 0 || !$fixedTypeAllowed) {
            $fixedContext = null;
            $fixedItemtype = '';
            $fixedItemsId = 0;
            $fixedIsProject = false;
        }
        $canUseProjects = $fixedContext === null && PluginApontamentosAppointment::canUseProjects();
        $linkOptions = $allowedLinks;
        if ($canUseProjects) {
            $linkOptions['Project'] = __('Projeto', 'apontamentos');
        }
        $projectOptions = $canUseProjects ? self::projectOptions() : [];
        $taskProjectOptions = $fixedIsProject
            ? [$fixedItemsId => (string) ($fixedContext['name'] ?? '')]
            : $projectOptions;
        $taskOptions = ($canUseProjects || $fixedIsProject)
            ? self::projectTaskOptions($taskProjectOptions)
            : [];

        echo "<div class='ap-create-modal' id='ap-create-modal' hidden>";
        echo "<div class='ap-create-backdrop' data-modal-action='cancel' aria-hidden='true'></div>";
        echo "<section class='ap-create-dialog' role='dialog' aria-modal='true' aria-labelledby='ap-create-title' tabindex='-1'>";
        echo "<header class='ap-create-header'><h2 id='ap-create-title'>" . __('Adicionar apontamento', 'apontamentos') . "</h2>";
        echo "<button type='button' class='btn-close' data-modal-action='cancel' aria-label='" . htmlescape(__('Fechar', 'apontamentos')) . "'></button></header>";

        echo "<form id='ap-create-form' method='post' action='" . htmlescape(PluginApontamentosAppointment::getFormURL()) . "' novalidate>";
        echo "<input type='hidden' name='_glpi_csrf_token' value='" . htmlescape($csrfToken) . "'>";
        echo "<input type='hidden' name='users_id' value='" . $calendarUser . "'>";
        echo "<input type='hidden' name='add' value='1'>";
        if ($fixedContext !== null) {
            echo "<input type='hidden' name='context_itemtype' value='" . htmlescape($fixedItemtype) . "'>";
            echo "<input type='hidden' name='context_items_id' value='" . $fixedItemsId . "'>";
        }
        echo "<div class='ap-create-body'>";
        echo "<div class='ap-modal-error alert alert-important alert-danger' role='alert' aria-live='assertive' tabindex='-1' hidden><i class='ti ti-alert-circle' aria-hidden='true'></i><span></span></div>";

        echo "<div class='ap-modal-field'><label for='ap-modal-content'>" . __('O que eu fiz?', 'apontamentos') . "</label>";
        echo "<input class='form-control' id='ap-modal-content' type='text' name='content' maxlength='65535' placeholder='" . htmlescape(__('Descreva brevemente a atividade realizada', 'apontamentos')) . "'></div>";

        echo "<div class='ap-modal-field'><label for='ap-modal-type'>" . __('Tipo de apontamento', 'apontamentos') . " <span aria-hidden='true'>*</span></label>";
        echo "<select class='form-select' id='ap-modal-type' name='appointmenttypes_id' required>";
        self::renderOptions($typeOptions, __('Selecione', 'apontamentos'));
        echo '</select></div>';

        echo "<fieldset class='ap-interval'><legend>" . __('Intervalo de tempo', 'apontamentos') . "</legend><div class='ap-interval-fields'>";
        echo "<div class='ap-modal-field'><label for='ap-modal-date'>" . __('Data', 'apontamentos') . " <span aria-hidden='true'>*</span></label><input class='form-control' id='ap-modal-date' type='date' name='appointment_date' required></div>";
        echo "<div class='ap-modal-field'><label for='ap-modal-begin'>" . __('Início', 'apontamentos') . " <span aria-hidden='true'>*</span></label><input class='form-control' id='ap-modal-begin' type='time' name='begin_time_hour' step='60' required></div>";
        echo "<div class='ap-modal-field'><label for='ap-modal-end'>" . __('Término', 'apontamentos') . " <span aria-hidden='true'>*</span></label><input class='form-control' id='ap-modal-end' type='time' name='end_time_hour' step='60' required></div>";
        echo '</div></fieldset>';

        echo "<button type='button' class='ap-details-toggle btn btn-link' aria-expanded='false' aria-controls='ap-create-details'><i class='ti ti-chevron-down' aria-hidden='true'></i><span>" . __('Exibir detalhes', 'apontamentos') . "</span></button>";
        echo "<div class='ap-create-details' id='ap-create-details' hidden>";
        if ($fixedContext !== null) {
            $fixedName = trim((string) ($fixedContext['name'] ?? ''));
            $fixedTypeLabel = $fixedIsProject
                ? __('Projeto', 'apontamentos')
                : $allowedLinks[$fixedItemtype];
            $fixedLabel = $fixedTypeLabel . ' #' . $fixedItemsId;
            if ($fixedName !== '') {
                $fixedLabel .= ' - ' . $fixedName;
            }
            echo "<div class='ap-modal-field'><span class='ap-fixed-link-label'>" . __('Vinculado a', 'apontamentos') . "</span><div class='ap-fixed-link' role='status'><i class='ti ti-lock' aria-hidden='true'></i><span>" . htmlescape($fixedLabel) . '</span></div></div>';
            echo "<input type='hidden' name='link_type' value='" . htmlescape($fixedItemtype) . "'>";
            if ($fixedIsProject) {
                echo "<input type='hidden' name='projects_id' value='" . $fixedItemsId . "'>";
                echo "<div class='ap-modal-field'><label for='ap-modal-task'>" . __('Tarefa do projeto', 'apontamentos') . " <span class='text-muted'>(" . __('opcional', 'apontamentos') . ")</span></label><select class='form-select' id='ap-modal-task' name='projecttasks_id'><option value='0'>" . htmlescape(__('Nenhuma', 'apontamentos')) . '</option>';
                foreach ($taskOptions as $task) {
                    echo "<option value='" . (int) $task['id'] . "'>" . htmlescape((string) $task['name']) . '</option>';
                }
                echo '</select></div>';
            } else {
                echo "<input type='hidden' name='linked_" . htmlescape($fixedItemtype) . "' value='" . $fixedItemsId . "'>";
                echo "<input type='hidden' name='projects_id' value='0'><input type='hidden' name='projecttasks_id' value='0'>";
            }
        } else {
            echo "<div class='ap-modal-field'><label for='ap-modal-link-type'>" . __('Vincular a', 'apontamentos') . "</label><select class='form-select' id='ap-modal-link-type' name='link_type'>";
            self::renderOptions($linkOptions, __('Nenhum', 'apontamentos'));
            echo '</select></div>';
            echo "<div class='ap-modal-field ap-modal-linked-field'><label>" . __('Registro vinculado', 'apontamentos') . '</label>';
            foreach ($allowedLinks as $itemtype => $label) {
                echo "<select class='form-select ap-modal-linked-picker' name='linked_" . htmlescape($itemtype) . "' data-link-type='" . htmlescape($itemtype) . "' aria-label='" . htmlescape(sprintf(__('Registro vinculado: %s', 'apontamentos'), $label)) . "' hidden disabled>";
                self::renderOptions(
                    PluginApontamentosAppointment::getAccessibleLinkOptions($itemtype, $entityId),
                    __('Selecione', 'apontamentos')
                );
                echo '</select>';
            }
            echo "<span class='ap-modal-no-link'>" . __('Selecione primeiro o tipo de vínculo.', 'apontamentos') . '</span></div>';
            if ($canUseProjects) {
                echo "<div class='ap-details-grid ap-modal-project-fields' hidden>";
                echo "<div class='ap-modal-field'><label for='ap-modal-project'>" . __('Projeto', 'apontamentos') . "</label><select class='form-select' id='ap-modal-project' name='projects_id' disabled>";
                self::renderOptions($projectOptions, __('Nenhum', 'apontamentos'), '0');
                echo '</select></div>';
                echo "<div class='ap-modal-field'><label for='ap-modal-task'>" . __('Tarefa do projeto', 'apontamentos') . "</label><select class='form-select' id='ap-modal-task' name='projecttasks_id' disabled><option value='0'>" . htmlescape(__('Nenhuma', 'apontamentos')) . '</option>';
                foreach ($taskOptions as $task) {
                    echo "<option value='" . (int) $task['id'] . "' data-project-id='" . (int) $task['projects_id'] . "'>" . htmlescape((string) $task['name']) . '</option>';
                }
                echo '</select></div></div>';
            } else {
                echo "<input type='hidden' name='projects_id' value='0'><input type='hidden' name='projecttasks_id' value='0'>";
            }
        }
        echo '</div></div>';

        echo "<footer class='ap-create-footer'><button type='button' class='btn btn-link' data-modal-action='cancel'>" . __('Cancelar', 'apontamentos') . "</button><button type='submit' class='btn btn-primary ap-modal-save'><span class='ap-save-spinner spinner-border spinner-border-sm' aria-hidden='true' hidden></span><i class='ti ti-device-floppy ap-save-icon' aria-hidden='true'></i><span>" . __('Salvar', 'apontamentos') . '</span></button></footer>';
        echo '</form></section>';

        echo "<div class='ap-discard-confirm' hidden>";
        echo "<div class='ap-discard-backdrop' data-modal-action='continue-editing' aria-hidden='true'></div>";
        echo "<section class='ap-discard-dialog' role='alertdialog' aria-modal='true' aria-labelledby='ap-discard-title' aria-describedby='ap-discard-description' tabindex='-1'>";
        echo "<div class='ap-discard-icon' aria-hidden='true'><i class='ti ti-alert-triangle'></i></div>";
        echo "<div class='ap-discard-content'><h3 id='ap-discard-title'>" . __('Descartar alterações?', 'apontamentos') . "</h3>";
        echo "<p id='ap-discard-description'>" . __('As informações preenchidas neste apontamento serão perdidas.', 'apontamentos') . '</p></div>';
        echo "<footer class='ap-discard-actions'><button type='button' class='btn btn-link' data-modal-action='continue-editing'>" . __('Continuar editando', 'apontamentos') . "</button><button type='button' class='btn btn-danger' data-modal-action='discard'>" . __('Descartar alterações', 'apontamentos') . '</button></footer>';
        echo '</section></div></div>';
    }

    private static function renderOptions(array $options, string $emptyLabel, string $emptyValue = ''): void
    {
        echo "<option value='" . htmlescape($emptyValue) . "'>" . htmlescape($emptyLabel) . '</option>';
        foreach ($options as $value => $label) {
            echo "<option value='" . htmlescape((string) $value) . "'>" . htmlescape((string) $label) . '</option>';
        }
    }

    public static function projectOptions(): array
    {
        global $DB;
        $options = [];
        $project = new Project();
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM' => Project::getTable(),
            'WHERE' => ['is_deleted' => 0],
            'ORDER' => ['name ASC', 'id ASC'],
            'LIMIT' => 2000,
        ]) as $row) {
            $id = (int) $row['id'];
            if (!PluginApontamentosAppointment::canReadRelatedItem($project, $id)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $options[$id] = '#' . $id . ' - ' . ($name !== '' ? $name : __('Projeto', 'apontamentos'));
        }
        return $options;
    }

    public static function projectTaskOptions(array $projectOptions): array
    {
        global $DB;
        $options = [];
        $task = new ProjectTask();
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'projects_id'],
            'FROM' => ProjectTask::getTable(),
            'ORDER' => ['name ASC', 'id ASC'],
            'LIMIT' => 4000,
        ]) as $row) {
            $id = (int) $row['id'];
            $projectId = (int) $row['projects_id'];
            if (!isset($projectOptions[$projectId])
                || !PluginApontamentosAppointment::canReadRelatedItem($task, $id)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $options[] = [
                'id' => $id,
                'projects_id' => $projectId,
                'name' => '#' . $id . ' - ' . ($name !== '' ? $name : __('Tarefa', 'apontamentos')),
            ];
        }
        return $options;
    }
}
