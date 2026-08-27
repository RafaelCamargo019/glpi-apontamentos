<?php

class PluginApontamentosAppointment extends CommonDBTM
{
    public static $rightname = 'plugin_apontamentos_appointment';
    public $dohistory = true;
    // O formulário do plugin apresenta mensagens próprias e sem o ID técnico.
    // Desative apenas para este objeto a mensagem automática do CommonDBTM.
    public $auto_message_on_action = false;

    public const DB_STATUS_ACTIVE = 1;
    public const LEGACY_STATUS_CANCELLED = 3;
    public const MANAGE_OTHERS = 32;

    private ?string $lastValidationError = null;
    private bool $validationMessagesEnabled = true;

    public static function getTypeName($nb = 0): string
    {
        return _n('Apontamento', 'Apontamentos', $nb, 'apontamentos');
    }

    public static function getIcon(): string
    {
        return 'ti ti-clock-hour-4';
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_apontamentos_appointments';
    }

    public static function getMenuName(): string
    {
        return __('Apontamentos', 'apontamentos');
    }

    public static function getMenuContent(): array
    {
        $menu = parent::getMenuContent();
        if (!is_array($menu)) {
            return [];
        }
        $menu['page'] = '/plugins/apontamentos/front/appointment.php';
        $menu['links']['search'] = '/plugins/apontamentos/front/appointment.php';
        if (self::canCreate()) {
            $menu['links']['add'] = '/plugins/apontamentos/front/appointment.form.php';
        }
        if (Session::haveRight(PluginApontamentosConfig::$rightname, UPDATE)) {
            $menu['links']['config'] = '/plugins/apontamentos/front/config.form.php';
        }
        return $menu;
    }

    public function getRights($interface = 'central'): array
    {
        $rights = parent::getRights($interface);
        $rights[self::MANAGE_OTHERS] = __('Gerenciar apontamentos de outros usuários', 'apontamentos');
        unset($rights[PURGE]);
        return $rights;
    }

    public static function canManageOthers(): bool
    {
        return Session::haveRight(self::$rightname, self::MANAGE_OTHERS);
    }

    public static function canUseProjects(): bool
    {
        return Session::haveRight(PluginApontamentosProjectRight::$rightname, READ);
    }

    public static function canPurge(): bool
    {
        return false;
    }

    public function setValidationMessagesEnabled(bool $enabled): void
    {
        $this->validationMessagesEnabled = $enabled;
    }

    public function getLastValidationError(): ?string
    {
        return $this->lastValidationError;
    }

    public function canViewItem(): bool
    {
        return $this->isInCurrentEntities()
            && (self::canManageOthers() || (int) $this->fields['users_id'] === Session::getLoginUserID());
    }

    public function canUpdateItem(): bool
    {
        return $this->isInCurrentEntities()
            && (self::canManageOthers() || (int) $this->fields['users_id'] === Session::getLoginUserID());
    }

    public function canDeleteItem(): bool
    {
        return self::canDeleteStoredFields($this->fields);
    }

    public static function canDeleteStoredFields(array $fields): bool
    {
        if (!Session::haveRight(self::$rightname, DELETE)) {
            return false;
        }
        return self::canManageOthers()
            || (int) ($fields['users_id'] ?? 0) === Session::getLoginUserID();
    }

    public static function deletePermanently(int $id): bool
    {
        global $DB;
        if ($id <= 0) {
            return false;
        }
        $DB->delete(self::getTable(), ['id' => $id]);
        return count($DB->request([
            'SELECT' => ['id'],
            'FROM' => self::getTable(),
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
        ])) === 0;
    }

    private function isInCurrentEntities(): bool
    {
        return Session::haveAccessToEntity((int) ($this->fields['entities_id'] ?? -1));
    }

    public static function getAllowedItemtypes(): array
    {
        return [
            'Ticket'  => __('Chamado', 'apontamentos'),
            'Problem' => __('Problema', 'apontamentos'),
            'Change'  => __('Mudança', 'apontamentos'),
        ];
    }

    public static function getAccessibleLinkOptions(string $itemtype, int $entityId): array
    {
        global $DB;
        if (!isset(self::getAllowedItemtypes()[$itemtype])) {
            return [];
        }
        $item = new $itemtype();
        $options = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM' => $itemtype::getTable(),
            'WHERE' => ['is_deleted' => 0],
            'ORDER' => ['id DESC'],
            'LIMIT' => 2000,
        ]) as $row) {
            $id = (int) $row['id'];
            if (!self::canReadRelatedItem($item, $id)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $options[$id] = '#' . $id . ' - ' . ($name !== '' ? $name : self::getAllowedItemtypes()[$itemtype]);
        }
        return $options;
    }

    public function prepareInputForAdd($input)
    {
        $this->lastValidationError = null;
        $input = $this->normalizeSubmittedTimes((array) $input);
        $input = $this->normalizeSubmittedLink($input);
        if (!self::canUseProjects()) {
            if ((int) ($input['projects_id'] ?? 0) > 0 || (int) ($input['projecttasks_id'] ?? 0) > 0) {
                return $this->validationError(__('Você não possui permissão para vincular projetos.', 'apontamentos'));
            }
            $input['projects_id'] = 0;
            $input['projecttasks_id'] = 0;
        }
        // Usuários comuns apontam sempre para si. Um gestor pode usar o usuário
        // atualmente selecionado no calendário, mas a entidade e o vínculo do
        // usuário continuam sendo validados no servidor.
        $requestedUser = $this->positiveInt($input['users_id'] ?? Session::getLoginUserID());
        $input['users_id'] = self::canManageOthers() && $requestedUser !== null
            ? $requestedUser
            : Session::getLoginUserID();
        $input['entities_id'] = self::activeEntityId();
        $input['appointmenttypes_id'] = $this->positiveInt($input['appointmenttypes_id'] ?? 0);
        $input['status'] = self::DB_STATUS_ACTIVE;
        $input['is_deleted'] = 0;
        return $this->validateAndNormalize($input, 0);
    }

    public function prepareInputForUpdate($input)
    {
        $this->lastValidationError = null;
        $current = $this->fields;
        // A edição nunca transfere a propriedade, inclusive quando um gestor
        // está editando o apontamento de outra pessoa. A entidade gravada
        // também não pode ser alterada por um POST forjado.
        $input = $this->normalizeSubmittedTimes((array) $input);
        $input['users_id'] = (int) $current['users_id'];
        $input['entities_id'] = (int) $current['entities_id'];
        $input = $this->normalizeSubmittedLink($input);
        if (!self::canUseProjects()) {
            foreach (['projects_id', 'projecttasks_id'] as $field) {
                if (array_key_exists($field, $input) && (int) $input[$field] !== (int) $current[$field]) {
                    return $this->validationError(__('Você não possui permissão para alterar vínculos de projeto.', 'apontamentos'));
                }
                $input[$field] = (int) $current[$field];
            }
        }
        $input['status'] = self::DB_STATUS_ACTIVE;
        $merged = array_merge($current, $input);
        $normalized = $this->validateAndNormalize($merged, (int) $current['id']);
        if ($normalized === false) {
            return false;
        }
        return array_intersect_key($normalized, array_flip([
            'id', 'users_id', 'entities_id', 'appointmenttypes_id', 'begin_time', 'end_time', 'content',
            'status', 'itemtype', 'items_id', 'projects_id', 'projecttasks_id', 'is_deleted',
        ]));
    }

    private function normalizeSubmittedLink(array $input): array
    {
        if (!array_key_exists('link_type', $input)) {
            return $input;
        }
        $type = (string) $input['link_type'];
        if ($type === '') {
            $input['itemtype'] = null;
            $input['items_id'] = 0;
            return $input;
        }
        if ($type === 'Project') {
            $input['itemtype'] = null;
            $input['items_id'] = 0;
            return $input;
        }
        if (!isset(self::getAllowedItemtypes()[$type])) {
            $input['itemtype'] = $type;
            $input['items_id'] = 0;
            return $input;
        }
        $input['itemtype'] = $type;
        $input['items_id'] = $input['linked_' . $type] ?? 0;
        $input['projects_id'] = 0;
        $input['projecttasks_id'] = 0;
        return $input;
    }

    /**
     * Datas e horários completos nunca são aceitos do cliente. Início e fim
     * são reconstruídos exclusivamente com a data e as duas horas visuais.
     */
    private function normalizeSubmittedTimes(array $input): array
    {
        unset($input['begin_time'], $input['end_time']);
        $date = (string) ($input['appointment_date'] ?? '');
        $input['begin_time'] = self::combineDateAndTime(
            $date,
            (string) ($input['begin_time_hour'] ?? '')
        );
        $input['end_time'] = self::combineDateAndTime(
            $date,
            (string) ($input['end_time_hour'] ?? '')
        );
        unset($input['appointment_date'], $input['begin_time_hour'], $input['end_time_hour']);
        return $input;
    }

    private function validateAndNormalize(array $input, int $ignoreId)
    {
        $input['users_id'] = $this->positiveInt($input['users_id'] ?? 0);
        $input['entities_id'] = $this->nonNegativeInt($input['entities_id'] ?? -1);
        if ($input['entities_id'] === null) {
            return $this->validationError(__('Não foi possível determinar a entidade ativa do GLPI.', 'apontamentos'));
        }
        if ($input['users_id'] === null || !Session::haveAccessToEntity($input['entities_id'])) {
            return $this->validationError(__('Usuário ou entidade inválidos.', 'apontamentos'));
        }
        $entity = new Entity();
        if (!$entity->getFromDB($input['entities_id'])) {
            return $this->validationError(__('A entidade selecionada não existe mais.', 'apontamentos'));
        }
        if (!self::canManageOthers() && $input['users_id'] !== Session::getLoginUserID()) {
            return $this->validationError(__('Você não pode apontar para outro usuário.', 'apontamentos'));
        }
        if (!self::userCanUseEntity($input['users_id'], $input['entities_id'])) {
            return $this->validationError(__('O usuário não possui perfil nesta entidade.', 'apontamentos'));
        }

        $input['appointmenttypes_id'] = $this->positiveInt($input['appointmenttypes_id'] ?? 0);
        $currentTypeId = $ignoreId > 0 ? (int) ($this->fields['appointmenttypes_id'] ?? 0) : 0;
        $appointmentType = $input['appointmenttypes_id'] !== null
            ? PluginApontamentosAppointmentType::getRecord($input['appointmenttypes_id'])
            : null;
        if ($input['appointmenttypes_id'] === null || $appointmentType === null) {
            return $this->validationError(__('Selecione um tipo de apontamento válido.', 'apontamentos'));
        }
        $keepsHistoricalType = $ignoreId > 0 && $input['appointmenttypes_id'] === $currentTypeId;
        if ((!empty($appointmentType['is_deleted']) || empty($appointmentType['is_active']))
            && !$keepsHistoricalType) {
            return $this->validationError(__('O tipo de apontamento selecionado está inativo ou excluído.', 'apontamentos'));
        }

        $input['begin_time'] = $this->normalizeDateTime($input['begin_time'] ?? '');
        $input['end_time'] = $this->normalizeDateTime($input['end_time'] ?? '');
        if ($input['begin_time'] === null || $input['end_time'] === null
            || !self::isValidSameDayInterval($input['begin_time'], $input['end_time'])) {
            return $this->validationError(
                __('O horário final deve ser posterior ao horário inicial e permanecer no mesmo dia.', 'apontamentos')
            );
        }
        $input['content'] = trim((string) ($input['content'] ?? ''));

        $input['status'] = self::DB_STATUS_ACTIVE;

        $input['itemtype'] = trim((string) ($input['itemtype'] ?? ''));
        $input['items_id'] = $this->nonNegativeInt($input['items_id'] ?? 0);
        if ($input['items_id'] === null || ($input['itemtype'] === '') !== ($input['items_id'] === 0)) {
            return $this->validationError(__('Selecione o registro relacionado.', 'apontamentos'));
        }
        if ($input['itemtype'] !== '') {
            if (!isset(self::getAllowedItemtypes()[$input['itemtype']])) {
                return $this->validationError(__('Tipo de vínculo inválido.', 'apontamentos'));
            }
            $itemtype = $input['itemtype'];
            $item = new $itemtype();
            if (!self::canReadRelatedItem($item, $input['items_id'])) {
                return $this->validationError(__('Registro relacionado inexistente ou inacessível.', 'apontamentos'));
            }
        } else {
            $input['itemtype'] = null;
            $input['items_id'] = 0;
        }

        $input['projects_id'] = $this->nonNegativeInt($input['projects_id'] ?? 0);
        $input['projecttasks_id'] = $this->nonNegativeInt($input['projecttasks_id'] ?? 0);
        if ($input['projects_id'] === null || $input['projecttasks_id'] === null) {
            return $this->validationError(__('Projeto ou tarefa inválidos.', 'apontamentos'));
        }
        if ($input['projects_id'] > 0) {
            $project = new Project();
            if (!self::canReadRelatedItem($project, $input['projects_id'])) {
                return $this->validationError(__('Projeto inexistente ou inacessível.', 'apontamentos'));
            }
        }
        if ($input['projecttasks_id'] > 0) {
            $task = new ProjectTask();
            if ($input['projects_id'] === 0 || !$task->getFromDB($input['projecttasks_id'])
                || !$task->can($input['projecttasks_id'], READ)
                || (int) $task->fields['projects_id'] !== $input['projects_id']) {
                return $this->validationError(__('A tarefa não pertence ao projeto selecionado ou está inacessível.', 'apontamentos'));
            }
        }
        if ($input['itemtype'] === null && $input['projects_id'] === 0) {
            return $this->validationError(__('Vincule um chamado, problema, mudança ou projeto.', 'apontamentos'));
        }

        $conflict = self::findOverlap(
            $input['users_id'],
            $input['begin_time'],
            $input['end_time'],
            $ignoreId
        );
        if ($conflict !== null) {
            return $this->validationError(self::overlapErrorMessage($conflict));
        }
        return $input;
    }

    /**
     * Retorna o primeiro apontamento ativo que cruza o intervalo informado.
     * Os limites são abertos: terminar exatamente quando outro começa (ou
     * começar quando outro termina) é permitido.
     */
    public static function findOverlap(int $userId, string $begin, string $end, int $ignoreId = 0): ?array
    {
        global $DB;

        if ($userId <= 0 || $begin === '' || $end === '' || $end <= $begin) {
            return null;
        }

        $where = [
            'users_id' => $userId,
            'is_deleted' => 0,
            ['begin_time' => ['<', $end]],
            ['end_time' => ['>', $begin]],
        ];
        if ($ignoreId > 0) {
            $where[] = ['NOT' => ['id' => $ignoreId]];
        }

        foreach ($DB->request([
            'SELECT' => [
                'id', 'users_id', 'entities_id', 'appointmenttypes_id',
                'begin_time', 'end_time', 'itemtype', 'items_id',
                'projects_id', 'projecttasks_id', 'is_deleted',
            ],
            'FROM' => self::getTable(),
            'WHERE' => $where,
            'ORDER' => ['begin_time ASC', 'id ASC'],
            'LIMIT' => 1,
        ]) as $row) {
            $row = (array) $row;
            if (self::rowConflictsWithInterval($row, $userId, $begin, $end, $ignoreId)) {
                return $row;
            }
        }
        return null;
    }

    public static function rowConflictsWithInterval(
        array $row,
        int $userId,
        string $begin,
        string $end,
        int $ignoreId = 0
    ): bool {
        return (int) ($row['users_id'] ?? 0) === $userId
            && empty($row['is_deleted'])
            && ($ignoreId <= 0 || (int) ($row['id'] ?? 0) !== $ignoreId)
            && self::intervalsOverlap(
                $begin,
                $end,
                (string) ($row['begin_time'] ?? ''),
                (string) ($row['end_time'] ?? '')
            );
    }

    public static function intervalsOverlap(
        string $begin,
        string $end,
        string $existingBegin,
        string $existingEnd
    ): bool {
        return $begin !== '' && $end !== '' && $existingBegin !== '' && $existingEnd !== ''
            && $begin < $existingEnd
            && $end > $existingBegin;
    }

    public static function overlapErrorMessage(array $conflict): string
    {
        $begin = self::messageDateTime((string) ($conflict['begin_time'] ?? ''));
        $end = self::messageDateTime((string) ($conflict['end_time'] ?? ''));
        $date = $begin?->format('d/m/Y') ?? __('data desconhecida', 'apontamentos');
        $beginTime = $begin?->format('H:i') ?? '--:--';
        $endTime = $end?->format('H:i') ?? '--:--';

        if (!self::canExposeConflictDetails($conflict)) {
            return sprintf(
                __('Não foi possível salvar. Já existe outro apontamento deste usuário em %1$s, das %2$s às %3$s.', 'apontamentos'),
                $date,
                $beginTime,
                $endTime
            );
        }

        $references = self::conflictReferences($conflict);
        if ($references === null) {
            return sprintf(
                __('Não foi possível salvar. Já existe outro apontamento deste usuário em %1$s, das %2$s às %3$s.', 'apontamentos'),
                $date,
                $beginTime,
                $endTime
            );
        }

        $typeName = self::safeMessageText(PluginApontamentosAppointmentType::displayName(
            (int) ($conflict['appointmenttypes_id'] ?? 0)
        ));
        $suffix = $references === [] ? '' : ': ' . implode('; ', $references);
        return sprintf(
            __('Não foi possível salvar. Já existe o apontamento #%1$d deste usuário em %2$s, das %3$s às %4$s%5$s. Tipo de apontamento: %6$s.', 'apontamentos'),
            (int) ($conflict['id'] ?? 0),
            $date,
            $beginTime,
            $endTime,
            $suffix,
            $typeName
        );
    }

    private static function canExposeConflictDetails(array $conflict): bool
    {
        return Session::haveAccessToEntity((int) ($conflict['entities_id'] ?? -1))
            && (self::canManageOthers()
                || (int) ($conflict['users_id'] ?? 0) === Session::getLoginUserID());
    }

    /**
     * @return null|array<int, string> Null indica vínculo não acessível.
     */
    private static function conflictReferences(array $conflict): ?array
    {
        $references = [];
        $itemtype = (string) ($conflict['itemtype'] ?? '');
        $itemId = (int) ($conflict['items_id'] ?? 0);
        if ($itemtype !== '' && $itemId > 0) {
            if (!isset(self::getAllowedItemtypes()[$itemtype])) {
                return null;
            }
            $item = new $itemtype();
            if (!self::canReadRelatedItem($item, $itemId)) {
                return null;
            }
            $references[] = sprintf(
                '%1$s #%2$d — %3$s',
                self::safeMessageText(self::getAllowedItemtypes()[$itemtype]),
                $itemId,
                self::safeMessageText((string) ($item->fields['name'] ?? self::getAllowedItemtypes()[$itemtype]))
            );
        }

        $taskId = (int) ($conflict['projecttasks_id'] ?? 0);
        $projectId = (int) ($conflict['projects_id'] ?? 0);
        if ($taskId > 0) {
            $task = new ProjectTask();
            if (!self::canUseProjects() || !self::canReadRelatedItem($task, $taskId)) {
                return null;
            }
            $references[] = sprintf(
                '%1$s #%2$d — %3$s',
                self::safeMessageText(__('Tarefa', 'apontamentos')),
                $taskId,
                self::safeMessageText((string) ($task->fields['name'] ?? __('Tarefa', 'apontamentos')))
            );
        } elseif ($projectId > 0) {
            $project = new Project();
            if (!self::canUseProjects() || !self::canReadRelatedItem($project, $projectId)) {
                return null;
            }
            $references[] = sprintf(
                '%1$s #%2$d — %3$s',
                self::safeMessageText(__('Projeto', 'apontamentos')),
                $projectId,
                self::safeMessageText((string) ($project->fields['name'] ?? __('Projeto', 'apontamentos')))
            );
        }
        return $references;
    }

    private static function messageDateTime(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        return $date !== false ? $date : null;
    }

    private static function safeMessageText(string $value): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        return htmlescape($value);
    }

    private function validationError(string $message)
    {
        $this->lastValidationError = $message;
        if ($this->validationMessagesEnabled) {
            Session::addMessageAfterRedirect($message, false, ERROR);
        }
        return false;
    }

    private function positiveInt($value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? null : $value;
    }

    private function nonNegativeInt($value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $value === false ? null : $value;
    }

    private function normalizeDateTime(string $value): ?string
    {
        $value = trim(str_replace('T', ' ', $value));
        if (strlen($value) === 16) {
            $value .= ':00';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            return null;
        }
        return $date->format('Y-m-d H:i:s') === $value ? $value : null;
    }

    public static function combineEndTime(string $beginValue, string $endHour): ?string
    {
        $beginValue = trim(str_replace('T', ' ', $beginValue));
        if (strlen($beginValue) === 16) {
            $beginValue .= ':00';
        }
        $begin = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $beginValue);
        $beginErrors = DateTimeImmutable::getLastErrors();
        if ($begin === false
            || ($beginErrors !== false && ($beginErrors['warning_count'] || $beginErrors['error_count']))
            || $begin->format('Y-m-d H:i:s') !== $beginValue) {
            return null;
        }

        $endHour = trim($endHour);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $endHour)) {
            return null;
        }
        return $begin->format('Y-m-d') . ' ' . $endHour . ':00';
    }

    public static function combineDateAndTime(string $dateValue, string $timeValue): ?string
    {
        $dateValue = trim($dateValue);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))
            || $date->format('Y-m-d') !== $dateValue) {
            return null;
        }

        $timeValue = trim($timeValue);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $timeValue)) {
            return null;
        }
        return $dateValue . ' ' . $timeValue . ':00';
    }

    public static function isSameCalendarDay(string $begin, string $end): bool
    {
        return substr($begin, 0, 10) === substr($end, 0, 10);
    }

    public static function isValidSameDayInterval(string $begin, string $end): bool
    {
        return $begin !== '' && $end !== ''
            && $end > $begin
            && self::isSameCalendarDay($begin, $end);
    }

    public static function relatedItemMatchesEntity(CommonDBTM $item, int $entityId): bool
    {
        if (!array_key_exists('entities_id', $item->fields)) {
            return true;
        }
        if ((int) $item->fields['entities_id'] === $entityId) {
            return true;
        }
        return !empty($item->fields['is_recursive'])
            && in_array((int) $item->fields['entities_id'], array_map('intval', getAncestorsOf('glpi_entities', $entityId)), true);
    }

    public static function canReadRelatedItem(CommonDBTM $item, int $id): bool
    {
        if ($id <= 0 || !$item->getFromDB($id)) {
            return false;
        }
        if ($item->can($id, READ)) {
            return true;
        }

        // Alguns objetos ITIL retornam false em can() quando chamados a partir
        // da rota do plug-in, apesar de o perfil possuir READ e a entidade estar
        // acessível. Este fallback aplica as mesmas duas barreiras explicitamente.
        $rightname = $item::$rightname ?? '';
        if ($rightname === '' || !Session::haveRight($rightname, READ)) {
            return false;
        }
        return !array_key_exists('entities_id', $item->fields)
            || Session::haveAccessToEntity((int) $item->fields['entities_id']);
    }

    public static function userCanUseEntity(int $userId, int $entityId): bool
    {
        global $DB;
        $user = new User();
        if (!$user->getFromDB($userId) || empty($user->fields['is_active']) || !empty($user->fields['is_deleted'])) {
            return false;
        }
        foreach ($DB->request(['FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $userId]]) as $profile) {
            $profileEntity = (int) $profile['entities_id'];
            if ($profileEntity === $entityId
                || (!empty($profile['is_recursive']) && in_array($profileEntity, array_map('intval', getAncestorsOf('glpi_entities', $entityId)), true))) {
                return true;
            }
        }
        return false;
    }

    public function showForm($id, array $options = []): bool
    {
        $formInput = is_array($options['form_input'] ?? null) ? $options['form_input'] : [];
        $context = is_array($options['context'] ?? null) ? $options['context'] : null;
        unset($options['form_input'], $options['context']);

        if ($id > 0) {
            $this->check($id, READ);
        } else {
            $this->check(-1, CREATE);
            $prefillBegin = self::validPrefillDateTime($_GET['begin_time'] ?? null);
            $prefillEnd = self::validPrefillDateTime($_GET['end_time'] ?? null);
            if ($prefillBegin === null || $prefillEnd === null || $prefillEnd <= $prefillBegin) {
                $prefillBegin = date('Y-m-d H:00:00');
                $prefillEnd = date('Y-m-d H:00:00', time() + 3600);
            }
            $this->fields = [
                'id' => 0, 'users_id' => Session::getLoginUserID(),
                'entities_id' => self::activeEntityId(), 'appointmenttypes_id' => 0,
                'begin_time' => $prefillBegin, 'end_time' => $prefillEnd,
                'content' => '', 'status' => self::DB_STATUS_ACTIVE, 'itemtype' => '', 'items_id' => 0,
                'projects_id' => 0, 'projecttasks_id' => 0,
            ];
        }

        if ($formInput !== []) {
            $this->applyFormInput($formInput);
        }

        $this->showFormHeader($options);
        $disabled = '';
        $appointmentDate = substr((string) $this->fields['begin_time'], 0, 10);
        $beginHour = substr((string) $this->fields['begin_time'], 11, 5);
        $endHour = substr((string) $this->fields['end_time'], 11, 5);
        $canUseProjects = self::canUseProjects();
        $selectedLinkType = $canUseProjects && (string) ($formInput['link_type'] ?? '') === 'Project'
            ? 'Project'
            : ((int) ($this->fields['projects_id'] ?? 0) > 0
                ? 'Project'
                : (string) ($this->fields['itemtype'] ?? ''));
        $linkTypeOptions = self::getAllowedItemtypes();
        if ($canUseProjects) {
            $linkTypeOptions['Project'] = __('Projeto', 'apontamentos');
        }

        echo "<tr class='tab_bg_1'><td colspan='4'><div class='plugin-apontamentos-form'>";
        if ($context !== null) {
            $origin = (string) $context['label'];
            if ((string) ($context['name'] ?? '') !== '') {
                $origin .= ' — ' . (string) $context['name'];
            }
            echo "<div class='ap-layout-row ap-origin-row'><div class='ap-field-group'><span class='ap-field-label'>" . __('Origem', 'apontamentos') . '</span>';
            echo "<a href='" . htmlescape((string) $context['url']) . "'><i class='ti ti-link'></i> "
                . htmlescape($origin) . '</a>';
            echo Html::hidden('context_itemtype', ['value' => (string) $context['itemtype']]);
            echo Html::hidden('context_items_id', ['value' => (int) $context['items_id']]);
            echo '</div></div>';
        }

        echo "<div class='ap-layout-row ap-main-row'><div class='ap-field-group'><label class='ap-field-label'>" . __('Tipo de apontamento', 'apontamentos') . "</label><div class='ap-field-control ap-type-control'>";
        Dropdown::showFromArray(
            'appointmenttypes_id',
            [0 => Dropdown::EMPTY_VALUE]
                + PluginApontamentosAppointmentType::activeOptions((int) $this->fields['appointmenttypes_id']),
            [
                'value' => (int) $this->fields['appointmenttypes_id'],
                'display_emptychoice' => false,
                'required' => true,
                'width' => '100%',
            ]
        );
        echo '</div></div>';
        echo "<div class='ap-field-group'><label class='ap-field-label' for='ap-appointment-date'>" . __('Data', 'apontamentos') . "</label><div class='ap-field-control ap-date-control'><input id='ap-appointment-date' class='form-control' type='date' name='appointment_date' value='" . htmlescape($appointmentDate) . "' required$disabled></div></div>";
        echo "<div class='ap-field-group'><label class='ap-field-label' for='ap-begin-time-hour'>" . __('Início', 'apontamentos') . "</label><div class='ap-field-control ap-time-control'><span class='ap-compact-time-control'><input id='ap-begin-time-hour' class='form-control ap-compact-time' type='time' name='begin_time_hour' value='" . htmlescape($beginHour) . "' step='60' required$disabled><i class='ti ti-clock ap-compact-clock' aria-hidden='true'></i></span></div></div>";
        echo "<div class='ap-field-group'><label class='ap-field-label' for='ap-end-time-hour'>" . __('Fim', 'apontamentos') . "</label><div class='ap-field-control ap-time-control'><span class='ap-compact-time-control'><input id='ap-end-time-hour' class='form-control ap-compact-time' type='time' name='end_time_hour' value='" . htmlescape($endHour) . "' step='60' required$disabled><i class='ti ti-clock ap-compact-clock' aria-hidden='true'></i></span></div></div>";
        echo '</div>';

        echo "<div class='ap-layout-row ap-link-row'>";
        echo "<div class='ap-field-group'><span class='ap-field-label'>" . __('Vincular a', 'apontamentos') . "</span><div class='ap-field-control ap-link-type-control'>";
        Dropdown::showFromArray('link_type', ['' => Dropdown::EMPTY_VALUE] + $linkTypeOptions, ['value' => $selectedLinkType, 'disabled' => $disabled !== '', 'width' => '100%']);
        echo "</div></div><div class='ap-field-group ap-itil-link-fields'><span class='ap-field-label'>" . __('Registro vinculado', 'apontamentos') . "</span><div class='ap-field-control ap-linked-control'>";
        foreach (array_keys(self::getAllowedItemtypes()) as $type) {
            $value = $this->fields['itemtype'] === $type ? (int) $this->fields['items_id'] : 0;
            $linkOptions = self::getAccessibleLinkOptions($type, (int) $this->fields['entities_id']);
            if ($value > 0 && !isset($linkOptions[$value])) {
                $linkOptions[$value] = sprintf(__('#%d - seleção preservada', 'apontamentos'), $value);
            }
            echo "<span class='ap-linked-picker' data-link-type='" . htmlescape($type) . "' style='display:none'>";
            Dropdown::showFromArray(
                'linked_' . $type,
                [0 => Dropdown::EMPTY_VALUE] + $linkOptions,
                ['value' => $value, 'disabled' => $disabled !== '', 'width' => '100%']
            );
            echo '</span>';
        }
        echo "<span class='ap-no-link'>" . __('Selecione primeiro o tipo de vínculo.', 'apontamentos') . '</span></div></div>';

        if ($canUseProjects) {
            echo "<div class='ap-field-group ap-project-fields'><span class='ap-field-label'>" . __('Projeto', 'apontamentos') . "</span><div class='ap-field-control ap-project-control'>";
            Project::dropdown(['name' => 'projects_id', 'value' => $this->fields['projects_id'], 'disabled' => $disabled !== '']);
            echo "</div></div><div class='ap-field-group ap-project-fields'><span class='ap-field-label'>" . __('Tarefa do projeto', 'apontamentos') . "</span><div class='ap-field-control ap-task-control'>";
            ProjectTask::dropdown(['name' => 'projecttasks_id', 'value' => $this->fields['projecttasks_id'], 'condition' => ['projects_id' => (int) $this->fields['projects_id']], 'disabled' => $disabled !== '']);
            echo '</div></div>';
        }
        echo '</div>';

        echo "<div class='ap-layout-row ap-content-row'><div class='ap-field-group ap-content-group'><label class='ap-field-label' for='ap-content'>" . __('Conteúdo', 'apontamentos') . "</label><textarea id='ap-content' name='content' rows='7' class='form-control'$disabled>" . htmlescape((string) $this->fields['content']) . '</textarea></div></div>';
        echo '</div></td></tr>';
        $options['canedit'] = true;
        $options['candel'] = $id > 0;
        $this->showFormButtons($options);
        echo <<<'HTML'
<script>
(() => {
  const addButton = document.querySelector('.asset button[name="add"]');
  if (addButton) {
    addButton.setAttribute('aria-label', 'Salvar');
    const icon = addButton.querySelector('i');
    if (icon) icon.className = 'ti ti-device-floppy';
    const label = addButton.querySelector('span');
    if (label) label.textContent = 'Salvar';
  }
  const form = document.querySelector('.plugin-apontamentos-form');
  if (!form) return;
  const type = form.querySelector('[name="link_type"]');
  if (!type) return;
  const refresh = () => {
    const projectSelected = type.value === 'Project';
    form.querySelectorAll('.ap-linked-picker').forEach(el => { el.style.display = !projectSelected && el.dataset.linkType === type.value ? 'block' : 'none'; });
    form.querySelectorAll('.ap-itil-link-fields').forEach(el => { el.style.display = projectSelected ? 'none' : 'inline-flex'; });
    form.querySelectorAll('.ap-project-fields').forEach(el => { el.style.display = projectSelected ? 'inline-flex' : 'none'; });
    const empty = form.querySelector('.ap-no-link');
    if (empty) empty.style.display = !projectSelected && type.value === '' ? 'inline' : 'none';
  };
  type.addEventListener('change', refresh);
  if (window.jQuery) window.jQuery(type).on('change select2:select', refresh);
  refresh();
})();
</script>
<style>
.plugin-apontamentos-form{display:flex;flex-direction:column;align-items:flex-start;gap:10px;width:100%;padding:4px 8px;box-sizing:border-box}.plugin-apontamentos-form .ap-layout-row{display:flex;align-items:center;justify-content:flex-start;flex-wrap:wrap;gap:8px 24px;width:100%;min-height:40px}.plugin-apontamentos-form .ap-field-group{display:inline-flex;align-items:center;justify-content:flex-start;gap:8px;min-width:0}.plugin-apontamentos-form .ap-field-label{flex:0 0 auto;margin:0;white-space:nowrap}.plugin-apontamentos-form .ap-field-control{display:flex;align-items:center;min-width:0;min-height:40px}.plugin-apontamentos-form .ap-type-control{width:270px}.plugin-apontamentos-form .ap-date-control{width:170px}.plugin-apontamentos-form .ap-time-control{width:90px}.plugin-apontamentos-form .ap-link-type-control{width:180px}.plugin-apontamentos-form .ap-linked-control{width:320px}.plugin-apontamentos-form .ap-project-control{width:270px}.plugin-apontamentos-form .ap-task-control{width:320px}.plugin-apontamentos-form .ap-field-control>.select2-container,.plugin-apontamentos-form .ap-field-control .select2-container{width:100%!important;max-width:100%}.plugin-apontamentos-form .select2-selection--single{display:flex;align-items:center;min-height:40px;height:40px}.plugin-apontamentos-form .select2-selection--single .select2-selection__rendered{line-height:38px}.plugin-apontamentos-form .select2-selection--single .select2-selection__arrow{height:38px}.plugin-apontamentos-form .form-control:not(textarea){height:40px;min-height:40px}.plugin-apontamentos-form .ap-linked-picker{display:none;width:100%}.plugin-apontamentos-form .ap-no-link{display:inline-flex;align-items:center;min-height:40px;white-space:nowrap}.plugin-apontamentos-form .ap-content-row{align-items:flex-start}.plugin-apontamentos-form .ap-content-group{display:flex;width:100%;align-items:flex-start}.plugin-apontamentos-form .ap-content-group .ap-field-label{margin-top:10px}.plugin-apontamentos-form .ap-content-group textarea{flex:1 1 auto;width:auto;min-width:0}.plugin-apontamentos-form .ap-compact-time-control{position:relative;display:inline-flex;width:90px;height:40px;overflow:visible}.plugin-apontamentos-form .ap-compact-time{box-sizing:border-box;width:90px;max-width:90px;min-width:90px;padding-left:8px;padding-right:24px}.plugin-apontamentos-form .ap-compact-time::-webkit-calendar-picker-indicator{position:absolute;right:3px;width:20px;height:100%;margin:0;padding:0;opacity:0;cursor:pointer}.plugin-apontamentos-form .ap-compact-clock{position:absolute;right:8px;top:50%;transform:translateY(-50%);font-size:.9rem;line-height:1;pointer-events:none;color:var(--tblr-body-color)}@media(max-width:768px){.plugin-apontamentos-form{padding:4px}.plugin-apontamentos-form .ap-layout-row{align-items:stretch;flex-direction:column;gap:8px}.plugin-apontamentos-form .ap-field-group{display:flex;align-items:flex-start;flex-direction:column;gap:4px;width:100%}.plugin-apontamentos-form .ap-field-control,.plugin-apontamentos-form .ap-type-control,.plugin-apontamentos-form .ap-date-control,.plugin-apontamentos-form .ap-time-control,.plugin-apontamentos-form .ap-link-type-control,.plugin-apontamentos-form .ap-linked-control,.plugin-apontamentos-form .ap-project-control,.plugin-apontamentos-form .ap-task-control{width:min(100%,320px)}.plugin-apontamentos-form .ap-time-control,.plugin-apontamentos-form .ap-compact-time-control,.plugin-apontamentos-form .ap-compact-time{width:90px;min-width:90px;max-width:90px}.plugin-apontamentos-form .ap-content-group textarea{width:100%}.plugin-apontamentos-form .ap-content-group .ap-field-label{margin-top:0}}
</style>
HTML;
        return true;
    }

    private function applyFormInput(array $input): void
    {
        $dateValue = (string) ($input['appointment_date'] ?? '');
        $beginValue = self::combineDateAndTime(
            $dateValue,
            (string) ($input['begin_time_hour'] ?? '')
        );
        if ($beginValue !== null) {
            $this->fields['begin_time'] = $beginValue;
        }
        $endValue = self::combineDateAndTime(
            $dateValue,
            (string) ($input['end_time_hour'] ?? '')
        );
        if ($endValue !== null) {
            $this->fields['end_time'] = $endValue;
        }
        $this->fields['content'] = (string) ($input['content'] ?? $this->fields['content']);
        $this->fields['appointmenttypes_id'] = $this->nonNegativeInt(
            $input['appointmenttypes_id'] ?? $this->fields['appointmenttypes_id'] ?? 0
        ) ?? 0;

        $type = (string) ($input['link_type'] ?? $input['itemtype'] ?? '');
        if (isset(self::getAllowedItemtypes()[$type])) {
            $this->fields['itemtype'] = $type;
            $this->fields['items_id'] = $this->nonNegativeInt($input['linked_' . $type] ?? $input['items_id'] ?? 0) ?? 0;
        } else {
            $this->fields['itemtype'] = '';
            $this->fields['items_id'] = 0;
        }

        $this->fields['projects_id'] = $this->nonNegativeInt($input['projects_id'] ?? 0) ?? 0;
        $this->fields['projecttasks_id'] = $this->nonNegativeInt($input['projecttasks_id'] ?? 0) ?? 0;
    }

    private static function activeEntityId(): int
    {
        $entityId = (int) ($_SESSION['glpiactive_entity'] ?? -1);
        if ($entityId < 0 || !Session::haveAccessToEntity($entityId)) {
            return -1;
        }
        $entity = new Entity();
        return $entity->getFromDB($entityId) ? $entityId : -1;
    }

    public static function getAccessibleEntityOptions(): array
    {
        $options = [];
        $activeEntities = array_values(array_unique(array_map(
            'intval',
            (array) ($_SESSION['glpiactiveentities'] ?? [])
        )));
        foreach ($activeEntities as $entityId) {
            if ($entityId < 0 || !Session::haveAccessToEntity($entityId)) {
                continue;
            }
            $entity = new Entity();
            if (!$entity->getFromDB($entityId)) {
                continue;
            }
            $name = trim((string) Dropdown::getDropdownName('glpi_entities', $entityId));
            $options[$entityId] = $name !== ''
                ? $name
                : ($entityId === 0 ? __('Entidade raiz', 'apontamentos') : sprintf(__('Entidade #%d', 'apontamentos'), $entityId));
        }
        return $options;
    }

    private static function validPrefillDateTime($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim(str_replace('T', ' ', $value));
        if (strlen($value) === 16) {
            $value .= ':00';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            return null;
        }
        return $date->format('Y-m-d H:i:s') === $value ? $value : null;
    }
}
