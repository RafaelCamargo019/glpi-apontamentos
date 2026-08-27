<?php

class PluginApontamentosItemTab extends CommonGLPI
{
    public static $rightname = 'plugin_apontamentos_appointment';

    public const SUPPORTED_ITEMTYPES = [
        'Ticket',
        'Problem',
        'Change',
        'Project',
    ];

    public const MODAL_ITEMTYPES = [
        'Ticket',
        'Problem',
        'Change',
        'Project',
    ];

    public static function getTypeName($nb = 0): string
    {
        return _n('Apontamento', 'Apontamentos', $nb, 'apontamentos');
    }

    public static function getIcon(): string
    {
        return 'ti ti-clock-hour-4';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!self::canAccessItem($item) || !Session::haveRight(self::$rightname, READ)) {
            return '';
        }
        $count = 0;
        if (!empty($_SESSION['glpishow_count_on_tabs'])) {
            $count = count(self::getVisibleRows($item));
        }
        return self::createTabEntry(
            self::getTypeName(Session::getPluralNumber()),
            $count,
            $item::getType(),
            self::getIcon()
        );
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!Session::haveRight(self::$rightname, READ) || !self::canAccessItem($item)) {
            echo "<div class='alert alert-danger'>" . htmlescape(__('Você não possui permissão para visualizar estes apontamentos.', 'apontamentos')) . '</div>';
            return false;
        }

        $context = self::contextFromItem($item);
        if ($context === null) {
            return false;
        }
        $canCreate = PluginApontamentosAppointment::canCreate();
        $usesModal = $canCreate && self::supportsModalContext($context);
        $csrfToken = $usesModal ? Session::getNewCSRFToken(true) : '';

        echo "<div class='ap-itemtab container-fluid px-2 py-3'"
            . " data-create-endpoint='/plugins/apontamentos/ajax/create_context.php'"
            . " data-refresh-endpoint='/plugins/apontamentos/ajax/itemtab.php'"
            . " data-create-csrf-token='" . htmlescape($csrfToken) . "'"
            . " data-context-itemtype='" . htmlescape((string) $context['itemtype']) . "'"
            . " data-context-items-id='" . (int) $context['items_id'] . "'"
            . " data-can-create='" . ($usesModal ? '1' : '0') . "'>";
        echo "<div class='ap-itemtab-success alert alert-important alert-success mb-3' role='status' aria-live='polite' hidden><i class='ti ti-circle-check' aria-hidden='true'></i> <span></span></div>";
        echo "<div class='ap-itemtab-error alert alert-important alert-danger mb-3' role='alert' aria-live='assertive' hidden><i class='ti ti-alert-circle' aria-hidden='true'></i> <span></span></div>";
        echo "<div class='ap-itemtab-panel'>";
        self::renderPanel($item, $context);
        echo '</div>';
        if ($usesModal) {
            PluginApontamentosAppointmentModal::render(
                Session::getLoginUserID(),
                $csrfToken,
                $context
            );
        }
        echo '</div>';
        return true;
    }

    public static function renderPanel(CommonGLPI $item, ?array $context = null): void
    {
        $context ??= self::contextFromItem($item);
        if ($context === null || !self::canAccessItem($item)) {
            echo "<div class='alert alert-danger mb-0'>" . htmlescape(__('O registro de origem é inválido ou não está mais acessível.', 'apontamentos')) . '</div>';
            return;
        }
        $rows = self::getVisibleRows($item);

        echo "<div class='d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3'>";
        echo '<h3 class="m-0"><i class="ti ti-clock-hour-4"></i> '
            . htmlescape(__('Apontamentos relacionados', 'apontamentos')) . '</h3>';
        if (PluginApontamentosAppointment::canCreate()) {
            $createUrl = self::appendContext(
                PluginApontamentosAppointment::getFormURL(),
                $context,
                self::prefillForContext($context)
            );
            $modalAction = self::supportsModalContext($context) ? " data-ap-action='open-create'" : '';
            echo "<a class='btn btn-primary'" . $modalAction . " href='" . htmlescape($createUrl) . "'><i class='ti ti-plus'></i> "
                . htmlescape(__('Novo apontamento', 'apontamentos')) . '</a>';
        }
        echo '</div>';

        if ($rows === []) {
            echo "<div class='alert alert-info mb-0'><i class='ti ti-info-circle'></i> "
                . htmlescape(__('Nenhum apontamento relacionado a este registro.', 'apontamentos')) . '</div>';
            return;
        }

        echo "<div class='table-responsive'><table class='table table-hover table-striped align-middle'>";
        echo '<thead><tr><th>' . htmlescape(__('Início', 'apontamentos')) . '</th><th>'
            . htmlescape(__('Fim', 'apontamentos')) . '</th><th>'
            . htmlescape(__('Duração', 'apontamentos')) . '</th><th>'
            . htmlescape(__('Tipo de apontamento', 'apontamentos')) . '</th><th>'
            . htmlescape(__('Tipo de vínculo', 'apontamentos')) . "</th><th class='text-end'>"
            . htmlescape(__('Ações', 'apontamentos')) . '</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $appointment = new PluginApontamentosAppointment();
            if (!$appointment->getFromDB((int) $row['id']) || !$appointment->canViewItem()) {
                continue;
            }
            $begin = self::formatDateTime((string) $row['begin_time']);
            $end = self::formatDateTime((string) $row['end_time']);
            $minutes = max(0, (int) round((strtotime((string) $row['end_time']) - strtotime((string) $row['begin_time'])) / 60));
            $appointmentType = PluginApontamentosAppointmentType::displayName(
                (int) ($row['appointmenttypes_id'] ?? 0)
            );
            $linkedType = (int) ($row['projects_id'] ?? 0) > 0
                ? 'Project'
                : (string) ($row['itemtype'] ?? '');
            $linkedTypeLabels = PluginApontamentosAppointment::getAllowedItemtypes() + [
                'Project' => __('Projeto', 'apontamentos'),
            ];
            $linkedTypeLabel = $linkedTypeLabels[$linkedType] ?? __('Não informado', 'apontamentos');

            echo '<tr><td>' . htmlescape($begin) . '</td><td>' . htmlescape($end) . '</td><td>'
                . htmlescape(self::formatDuration($minutes)) . '</td><td>' . htmlescape($appointmentType)
                . '</td><td>' . htmlescape($linkedTypeLabel) . '</td>';
            echo "<td class='text-end text-nowrap'>";
            if ($appointment->can((int) $row['id'], UPDATE)) {
                $editUrl = self::appendContext(
                    PluginApontamentosAppointment::getFormURLWithID((int) $row['id']),
                    $context
                );
                echo "<a class='btn btn-sm btn-outline-primary me-1' href='" . htmlescape($editUrl)
                    . "' title='" . htmlescape(__('Editar apontamento', 'apontamentos')) . "' aria-label='"
                    . htmlescape(sprintf(__('Editar apontamento #%d', 'apontamentos'), (int) $row['id']))
                    . "'><i class='ti ti-pencil'></i></a>";
            }
            if ($appointment->can((int) $row['id'], DELETE)) {
                echo "<form method='post' action='" . htmlescape(PluginApontamentosAppointment::getFormURL())
                    . "' class='d-inline'>";
                echo Html::hidden('id', ['value' => (int) $row['id']]);
                echo Html::hidden('context_itemtype', ['value' => $context['itemtype']]);
                echo Html::hidden('context_items_id', ['value' => $context['items_id']]);
                echo "<button class='btn btn-sm btn-outline-danger' type='submit' name='delete' title='"
                    . htmlescape(__('Excluir apontamento', 'apontamentos')) . "' aria-label='"
                    . htmlescape(sprintf(__('Excluir apontamento #%d', 'apontamentos'), (int) $row['id']))
                    . "'><i class='ti ti-trash'></i></button>";
                Html::closeForm();
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function contextRequested(array $input): bool
    {
        return array_key_exists('context_itemtype', $input)
            || array_key_exists('context_items_id', $input);
    }

    public static function contextFromInput(array $input): ?array
    {
        $itemtype = trim((string) ($input['context_itemtype'] ?? ''));
        $itemsId = filter_var(
            $input['context_items_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($itemsId === false || !in_array($itemtype, self::SUPPORTED_ITEMTYPES, true)) {
            return null;
        }
        $item = new $itemtype();
        if (!$item->getFromDB((int) $itemsId) || !self::canAccessItem($item)) {
            return null;
        }
        return self::contextFromItem($item);
    }

    public static function contextFromItem(CommonGLPI $item): ?array
    {
        if (!self::canAccessItem($item)) {
            return null;
        }
        $itemtype = $item::getType();
        $itemsId = (int) $item->getID();
        $name = trim((string) $item->getName());
        $labels = PluginApontamentosAppointment::getAllowedItemtypes() + [
            'Project' => __('Projeto', 'apontamentos'),
        ];
        return [
            'itemtype' => $itemtype,
            'items_id' => $itemsId,
            'label' => ($labels[$itemtype] ?? $itemtype) . ' #' . $itemsId,
            'name' => $name,
            'url' => $itemtype::getFormURLWithID($itemsId),
        ];
    }

    public static function canAccessItem(CommonGLPI $item): bool
    {
        $itemtype = $item::getType();
        $itemsId = (int) $item->getID();
        if ($itemsId <= 0 || !in_array($itemtype, self::SUPPORTED_ITEMTYPES, true)) {
            return false;
        }
        if ($itemtype === 'Project' && !PluginApontamentosAppointment::canUseProjects()) {
            return false;
        }
        return $item->can($itemsId, READ);
    }

    public static function prefillForContext(array $context): array
    {
        if (($context['itemtype'] ?? '') === 'Project') {
            return ['projects_id' => (int) $context['items_id']];
        }
        $itemtype = (string) $context['itemtype'];
        return [
            'link_type' => $itemtype,
            'linked_' . $itemtype => (int) $context['items_id'],
        ];
    }

    public static function supportsModalContext(array $context): bool
    {
        return in_array((string) ($context['itemtype'] ?? ''), self::MODAL_ITEMTYPES, true)
            && (int) ($context['items_id'] ?? 0) > 0;
    }

    /**
     * Impõe no servidor o vínculo da aba de origem. Campos enviados pelo
     * navegador nunca podem trocar um Chamado por Problema/Mudança ou apontar
     * para outro registro enquanto o usuário permanece nessa aba. No contexto
     * de Projeto, somente a tarefa opcional permanece selecionável.
     */
    public static function bindInputToContext(array $input, array $context): array
    {
        if (!self::supportsModalContext($context)) {
            return $input;
        }
        foreach (array_keys(PluginApontamentosAppointment::getAllowedItemtypes()) as $itemtype) {
            unset($input['linked_' . $itemtype]);
        }
        unset($input['itemtype'], $input['items_id']);
        $itemtype = (string) $context['itemtype'];
        $input['users_id'] = Session::getLoginUserID();
        if ($itemtype === 'Project') {
            $input['link_type'] = 'Project';
            $input['projects_id'] = (int) $context['items_id'];
            $taskId = filter_var(
                $input['projecttasks_id'] ?? 0,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]]
            );
            $input['projecttasks_id'] = $taskId === false ? 0 : (int) $taskId;
        } else {
            $input['link_type'] = $itemtype;
            $input['linked_' . $itemtype] = (int) $context['items_id'];
            $input['projects_id'] = 0;
            $input['projecttasks_id'] = 0;
        }
        return $input;
    }

    public static function appointmentMatchesContext(array $fields, array $context): bool
    {
        if (($context['itemtype'] ?? '') === 'Project') {
            return (int) ($fields['projects_id'] ?? 0) === (int) $context['items_id'];
        }
        return (string) ($fields['itemtype'] ?? '') === (string) $context['itemtype']
            && (int) ($fields['items_id'] ?? 0) === (int) $context['items_id'];
    }

    public static function appointmentBelongsToContext(array $fields, array $context): bool
    {
        if (self::appointmentMatchesContext($fields, $context)) {
            return true;
        }
        $itemtype = (string) ($context['itemtype'] ?? '');
        $itemsId = filter_var(
            $context['items_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($itemsId === false || !in_array($itemtype, self::SUPPORTED_ITEMTYPES, true)) {
            return false;
        }
        $item = new $itemtype();
        if (!$item->getFromDB((int) $itemsId) || !self::canAccessItem($item)) {
            return false;
        }
        $targets = self::getRelatedTargets($item);
        $linkedType = trim((string) ($fields['itemtype'] ?? ''));
        $linkedId = (int) ($fields['items_id'] ?? 0);
        return $linkedId > 0 && isset($targets[$linkedType][$linkedId]);
    }

    public static function appendContext(string $url, array $context, array $extra = []): string
    {
        $parameters = [
            'context_itemtype' => (string) $context['itemtype'],
            'context_items_id' => (int) $context['items_id'],
        ] + $extra;
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    public static function contextUrl(array $context): string
    {
        $itemtype = (string) ($context['itemtype'] ?? '');
        $itemsId = filter_var(
            $context['items_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($itemsId === false || !in_array($itemtype, self::SUPPORTED_ITEMTYPES, true)) {
            return PluginApontamentosAppointment::getSearchURL();
        }
        // A URL de retorno nunca é lida do GET/POST nem do próprio array de
        // contexto: ela é reconstruída pela classe oficial permitida.
        $base = $itemtype::getFormURLWithID((int) $itemsId);
        return $base . (str_contains($base, '?') ? '&' : '?')
            . 'forcetab=' . rawurlencode(self::class . '$1');
    }

    private static function getVisibleRows(CommonGLPI $item): array
    {
        global $DB;
        if (!self::canAccessItem($item)) {
            return [];
        }
        $entities = self::activeEntityIds();
        if ($entities === []) {
            return [];
        }
        $baseWhere = [
            'is_deleted' => 0,
            'entities_id' => $entities,
        ];
        if (!PluginApontamentosAppointment::canManageOthers()) {
            $baseWhere['users_id'] = Session::getLoginUserID();
        }
        $rows = [];
        if ($item::getType() === 'Project') {
            foreach ($DB->request([
                'FROM' => PluginApontamentosAppointment::getTable(),
                'WHERE' => $baseWhere + ['projects_id' => (int) $item->getID()],
                'ORDER' => ['begin_time DESC', 'id DESC'],
                'LIMIT' => 1000,
            ]) as $row) {
                $rows[] = (array) $row;
            }
        } else {
            foreach (self::getRelatedTargets($item) as $relatedType => $relatedIds) {
                if ($relatedIds === []) {
                    continue;
                }
                foreach ($DB->request([
                    'FROM' => PluginApontamentosAppointment::getTable(),
                    'WHERE' => $baseWhere + [
                        'itemtype' => $relatedType,
                        'items_id' => array_values($relatedIds),
                    ],
                    'ORDER' => ['begin_time DESC', 'id DESC'],
                    'LIMIT' => 1000,
                ]) as $row) {
                    $rows[] = (array) $row;
                }
            }
        }
        usort($rows, static function (array $left, array $right): int {
            $byBegin = strcmp((string) $right['begin_time'], (string) $left['begin_time']);
            return $byBegin !== 0 ? $byBegin : (int) $right['id'] <=> (int) $left['id'];
        });
        return array_slice($rows, 0, 1000);
    }

    private static function getRelatedTargets(CommonGLPI $item): array
    {
        $itemtype = $item::getType();
        $itemsId = (int) $item->getID();
        $targets = [
            'Ticket' => [],
            'Problem' => [],
            'Change' => [],
        ];
        if (!isset($targets[$itemtype])) {
            return $targets;
        }
        $targets[$itemtype][$itemsId] = $itemsId;

        if ($itemtype === 'Ticket') {
            self::addSymmetricRelations($targets, 'glpi_tickets_tickets', 'tickets_id_1', 'tickets_id_2', $itemsId, 'Ticket');
            self::addRelations($targets, 'glpi_problems_tickets', 'tickets_id', $itemsId, 'Problem', 'problems_id');
            self::addRelations($targets, 'glpi_changes_tickets', 'tickets_id', $itemsId, 'Change', 'changes_id');
        } elseif ($itemtype === 'Problem') {
            self::addSymmetricRelations($targets, 'glpi_problems_problems', 'problems_id_1', 'problems_id_2', $itemsId, 'Problem');
            self::addRelations($targets, 'glpi_problems_tickets', 'problems_id', $itemsId, 'Ticket', 'tickets_id');
            self::addRelations($targets, 'glpi_changes_problems', 'problems_id', $itemsId, 'Change', 'changes_id');
        } elseif ($itemtype === 'Change') {
            self::addSymmetricRelations($targets, 'glpi_changes_changes', 'changes_id_1', 'changes_id_2', $itemsId, 'Change');
            self::addRelations($targets, 'glpi_changes_tickets', 'changes_id', $itemsId, 'Ticket', 'tickets_id');
            self::addRelations($targets, 'glpi_changes_problems', 'changes_id', $itemsId, 'Problem', 'problems_id');
        }
        return $targets;
    }

    private static function addSymmetricRelations(
        array &$targets,
        string $table,
        string $leftColumn,
        string $rightColumn,
        int $itemsId,
        string $targetType
    ): void {
        self::addRelations($targets, $table, $leftColumn, $itemsId, $targetType, $rightColumn);
        self::addRelations($targets, $table, $rightColumn, $itemsId, $targetType, $leftColumn);
    }

    private static function addRelations(
        array &$targets,
        string $table,
        string $matchColumn,
        int $itemsId,
        string $targetType,
        string $targetColumn
    ): void {
        global $DB;
        if (!$DB->tableExists($table)) {
            return;
        }
        foreach ($DB->request([
            'SELECT' => [$targetColumn],
            'FROM' => $table,
            'WHERE' => [$matchColumn => $itemsId],
        ]) as $relation) {
            $targetId = (int) $relation[$targetColumn];
            if ($targetId <= 0 || isset($targets[$targetType][$targetId])) {
                continue;
            }
            $target = new $targetType();
            if ($target->getFromDB($targetId) && self::canAccessItem($target)) {
                $targets[$targetType][$targetId] = $targetId;
            }
        }
    }

    private static function activeEntityIds(): array
    {
        $ids = [];
        foreach (array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? [])) as $entityId) {
            $entity = new Entity();
            if ($entityId >= 0 && Session::haveAccessToEntity($entityId) && $entity->getFromDB($entityId)) {
                $ids[] = $entityId;
            }
        }
        return array_values(array_unique($ids));
    }

    private static function formatDateTime(string $value): string
    {
        $timestamp = strtotime($value);
        return $timestamp === false ? $value : date('d/m/Y H:i', $timestamp);
    }

    private static function formatDuration(int $minutes): string
    {
        return sprintf('%dh %02dm', intdiv(max(0, $minutes), 60), max(0, $minutes) % 60);
    }
}
