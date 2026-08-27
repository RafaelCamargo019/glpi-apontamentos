<?php

/**
 * Serviço somente leitura usado pela tela e pelas exportações gerenciais.
 * "Ociosidade" é a diferença entre jornada configurada e minutos apontados;
 * o plugin não tenta inferir intervalos reais de inatividade.
 */
class PluginApontamentosReport extends CommonGLPI
{
    public static $rightname = 'plugin_apontamentos_export';
    public const MAX_PERIOD_DAYS = 93;
    public const MAX_DETAIL_ROWS = 1000;

    public function getRights($interface = 'central'): array
    {
        return [READ => __('Exportar', 'apontamentos')];
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Exportar relatórios de apontamentos', 'apontamentos');
    }

    /** @throws InvalidArgumentException */
    public static function filtersFromRequest(array $request): array
    {
        $today = new DateTimeImmutable('today');
        $view = (string) ($request['view'] ?? 'week');
        if (!in_array($view, ['day', 'week', 'month', 'custom'], true)) {
            throw new InvalidArgumentException(__('Visualização inválida.', 'apontamentos'));
        }
        $start = self::dateValue($request['start'] ?? null);
        $end = self::dateValue($request['end'] ?? null);
        if (!$start || !$end) {
            if ($view === 'day') {
                $start = $end = $today;
            } elseif ($view === 'month') {
                $start = $today->modify('first day of this month');
                $end = $today->modify('last day of this month');
            } else {
                $start = $today->modify('sunday this week');
                $end = $start->modify('+6 days');
            }
        }
        $days = (int) $start->diff($end)->format('%r%a') + 1;
        if ($days <= 0 || $days > self::MAX_PERIOD_DAYS) {
            throw new InvalidArgumentException(sprintf(
                __('O período deve ser válido e ter no máximo %d dias.', 'apontamentos'),
                self::MAX_PERIOD_DAYS
            ));
        }

        $activeEntities = array_values(array_unique(array_map(
            'intval',
            (array) ($_SESSION['glpiactiveentities'] ?? [])
        )));
        // O relatório não oferece filtro de entidade. O escopo é sempre a
        // coleção de entidades autorizadas na sessão ativa do GLPI.
        $entityId = 0;
        $entities = $activeEntities;
        if ($entities === []) {
            throw new InvalidArgumentException(__('Nenhuma entidade acessível.', 'apontamentos'));
        }

        $requestedUser = self::nonNegativeInt($request['users_id'] ?? 0);
        if ($requestedUser === null) {
            throw new InvalidArgumentException(__('Usuário inválido.', 'apontamentos'));
        }
        $userId = PluginApontamentosAppointment::canManageOthers()
            ? $requestedUser
            : Session::getLoginUserID();
        if ($userId > 0 && !self::userAllowedInEntities($userId, $entities)) {
            throw new InvalidArgumentException(__('Usuário inacessível para as entidades autorizadas.', 'apontamentos'));
        }

        $appointmentTypeId = self::nonNegativeInt($request['appointmenttypes_id'] ?? 0);
        if ($appointmentTypeId === null) {
            throw new InvalidArgumentException(__('Um filtro numérico é inválido.', 'apontamentos'));
        }
        if ($appointmentTypeId > 0) {
            $type = PluginApontamentosAppointmentType::getRecord($appointmentTypeId);
            if ($type === null || !empty($type['is_deleted'])) {
                throw new InvalidArgumentException(__('Tipo de apontamento inexistente ou excluído.', 'apontamentos'));
            }
        }
        $itemtype = (string) ($request['itemtype'] ?? '');
        $allowedItemtypes = PluginApontamentosAppointment::getAllowedItemtypes();
        $projectSelected = $itemtype === 'Project';
        if ($projectSelected && !PluginApontamentosAppointment::canUseProjects()) {
            throw new InvalidArgumentException(__('Você não possui permissão para filtrar projetos.', 'apontamentos'));
        }
        if ($itemtype !== '' && !$projectSelected && !isset($allowedItemtypes[$itemtype])) {
            throw new InvalidArgumentException(__('Tipo de vínculo inválido.', 'apontamentos'));
        }

        $projectId = $projectSelected
            ? self::nonNegativeInt($request['projects_id'] ?? 0)
            : 0;
        $taskId = $projectSelected
            ? self::nonNegativeInt($request['projecttasks_id'] ?? 0)
            : 0;
        if ($projectId === null || $taskId === null) {
            throw new InvalidArgumentException(__('Um filtro numérico é inválido.', 'apontamentos'));
        }
        if ($projectId > 0) {
            $project = new Project();
            if (!PluginApontamentosAppointment::canReadRelatedItem($project, $projectId)) {
                throw new InvalidArgumentException(__('Projeto inacessível.', 'apontamentos'));
            }
        }
        if ($taskId > 0) {
            if ($projectId <= 0) {
                throw new InvalidArgumentException(__('Selecione o projeto da tarefa.', 'apontamentos'));
            }
            $task = new ProjectTask();
            if (!PluginApontamentosAppointment::canReadRelatedItem($task, $taskId)
                || (int) $task->fields['projects_id'] !== $projectId) {
                throw new InvalidArgumentException(__('Tarefa inacessível ou incompatível com o projeto.', 'apontamentos'));
            }
        }

        $linkedId = $itemtype === '' || $projectSelected
            ? 0
            : self::nonNegativeInt($request['linked_' . $itemtype] ?? 0);
        if ($linkedId === null) {
            throw new InvalidArgumentException(__('Registro vinculado inválido.', 'apontamentos'));
        }
        if ($linkedId > 0) {
            $linked = new $itemtype();
            if (!PluginApontamentosAppointment::canReadRelatedItem($linked, $linkedId)) {
                throw new InvalidArgumentException(__('Registro vinculado inacessível.', 'apontamentos'));
            }
        }

        return compact(
            'view', 'start', 'end', 'entityId', 'entities', 'userId',
            'appointmentTypeId', 'projectId', 'taskId', 'itemtype', 'linkedId'
        );
    }

    public static function build(array $filters): array
    {
        global $DB;
        $where = [
            'is_deleted' => 0,
            'entities_id' => $filters['entities'],
            'AND' => [
                ['begin_time' => ['>=', $filters['start']->format('Y-m-d 00:00:00')]],
                ['begin_time' => ['<', $filters['end']->modify('+1 day')->format('Y-m-d 00:00:00')]],
            ],
        ];
        foreach ([
            'userId' => 'users_id',
            'appointmentTypeId' => 'appointmenttypes_id',
            'projectId' => 'projects_id',
            'taskId' => 'projecttasks_id',
        ] as $filter => $column) {
            if ((int) $filters[$filter] > 0) {
                $where[$column] = (int) $filters[$filter];
            }
        }
        if ($filters['itemtype'] === 'Project') {
            if ($filters['projectId'] <= 0) {
                $where['projects_id'] = ['>', 0];
            }
        } elseif ($filters['itemtype'] !== '') {
            $where['itemtype'] = $filters['itemtype'];
        }
        if ($filters['linkedId'] > 0) {
            $where['items_id'] = $filters['linkedId'];
        }

        $rows = [];
        foreach ($DB->request([
            'FROM' => PluginApontamentosAppointment::getTable(),
            'WHERE' => $where,
            'ORDER' => ['begin_time ASC', 'id ASC'],
        ]) as $row) {
            $row = (array) $row;
            if (!self::userAllowedInEntities((int) $row['users_id'], [(int) $row['entities_id']])) {
                continue;
            }
            $begin = self::dateTime((string) $row['begin_time']);
            $end = self::dateTime((string) $row['end_time']);
            if (!$begin || !$end || $end <= $begin) {
                continue;
            }
            $row['_begin'] = $begin;
            $row['_end'] = $end;
            [$row['_link_label'], $row['_project_label']] = self::referenceLabels($row);
            $rows[] = $row;
        }
        $report = self::aggregate($filters, $rows);
        $report['filterText'] = self::filterText($filters);
        return $report;
    }

    private static function aggregate(array $filters, array $rows): array
    {
        $operators = [];
        $recordsByDay = [];
        $rangesByDay = [];
        foreach ($rows as $row) {
            $date = $row['_begin']->format('Y-m-d');
            $userId = (int) $row['users_id'];
            $operators[$userId] = getUserName($userId);
            $recordsByDay[$date][$userId][] = $row;
            $rangesByDay[$date][$userId][] = [
                $row['_begin']->getTimestamp(),
                $row['_end']->getTimestamp(),
                (int) $row['id'],
            ];
        }
        if ($filters['userId'] > 0) {
            $operators = [$filters['userId'] => getUserName($filters['userId'])];
        } elseif (PluginApontamentosAppointment::canManageOthers()) {
            global $DB;
            foreach ($DB->request([
                'SELECT' => ['id'],
                'FROM' => 'glpi_users',
                'WHERE' => ['is_active' => 1, 'is_deleted' => 0],
                'ORDER' => ['name ASC'],
                'LIMIT' => 500,
            ]) as $user) {
                $candidateId = (int) $user['id'];
                if (self::userAllowedInEntities($candidateId, $filters['entities'])) {
                    $operators[$candidateId] = getUserName($candidateId);
                }
            }
        }
        asort($operators, SORT_NATURAL | SORT_FLAG_CASE);

        $summary = [];
        $types = [];
        $overlapIds = [];
        for ($date = $filters['start']; $date <= $filters['end']; $date = $date->modify('+1 day')) {
            $dateKey = $date->format('Y-m-d');
            foreach ($operators as $userId => $name) {
                $records = $recordsByDay[$dateKey][$userId] ?? [];
                $creditedMinutes = self::creditedMinutes($records);
                $rawMinutes = 0;
                foreach ($records as $record) {
                    $minutes = max(0, (int) round(
                        ($record['_end']->getTimestamp() - $record['_begin']->getTimestamp()) / 60
                    ));
                    $rawMinutes += $minutes;
                    $typeId = (int) ($record['appointmenttypes_id'] ?? 0);
                    if (!isset($types[$typeId])) {
                        $types[$typeId] = [
                            'id' => $typeId,
                            'name' => PluginApontamentosAppointmentType::displayName($typeId),
                            'color' => PluginApontamentosAppointmentType::colorFor($typeId),
                            'minutes' => 0,
                            'count' => 0,
                        ];
                    }
                    $types[$typeId]['minutes'] += $creditedMinutes[(int) $record['id']] ?? 0;
                    $types[$typeId]['count']++;
                }
                $pointedMinutes = self::unionMinutes(
                    $rangesByDay[$dateKey][$userId] ?? [],
                    $overlapIds
                );
                $expectedMinutes = PluginApontamentosConfig::scheduleFor((int) $userId, $date);
                $metrics = self::calculateDay($expectedMinutes, $pointedMinutes);
                $missingMinutes = $metrics['missing'];
                $extraMinutes = $metrics['extra'];
                $occupation = $metrics['occupation'];
                $state = $metrics['state'];
                $summary[] = compact(
                    'dateKey', 'userId', 'name', 'expectedMinutes', 'rawMinutes',
                    'pointedMinutes', 'missingMinutes', 'extraMinutes',
                    'occupation', 'state'
                ) + ['count' => count($records)];
            }
        }

        $totals = [
            'expected' => 0, 'pointed' => 0, 'missing' => 0, 'extra' => 0,
            'count' => count($rows), 'operators' => count($operators),
            'unconfigured_days' => 0, 'occupation_pointed' => 0,
        ];
        foreach ($summary as $line) {
            $totals['pointed'] += $line['pointedMinutes'];
            if ($line['expectedMinutes'] === null) {
                $totals['unconfigured_days']++;
                continue;
            }
            $totals['expected'] += $line['expectedMinutes'];
            $totals['occupation_pointed'] += $line['pointedMinutes'];
            $totals['missing'] += $line['missingMinutes'];
            $totals['extra'] += $line['extraMinutes'];
        }
        $totals['occupation'] = $totals['expected'] > 0
            ? round(($totals['occupation_pointed'] / $totals['expected']) * 100, 1)
            : null;
        foreach ($types as &$type) {
            $type['percent'] = $totals['pointed'] > 0
                ? round(($type['minutes'] / $totals['pointed']) * 100, 1)
                : 0;
        }
        unset($type);
        uasort($types, static fn(array $a, array $b): int => $b['minutes'] <=> $a['minutes']);

        return compact('filters', 'rows', 'summary', 'types', 'totals', 'operators', 'overlapIds');
    }

    /**
     * Soma a união dos intervalos e marca os IDs históricos sobrepostos.
     * Intervalos consecutivos continuam independentes e são somados.
     */
    public static function unionMinutes(array $ranges, array &$overlapIds = []): int
    {
        if ($ranges === []) {
            return 0;
        }
        usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        [$start, $end, $lastId] = array_shift($ranges);
        $seconds = 0;
        foreach ($ranges as [$nextStart, $nextEnd, $nextId]) {
            if ($nextStart < $end) {
                $overlapIds[$lastId] = true;
                $overlapIds[$nextId] = true;
            }
            if ($nextStart > $end) {
                $seconds += $end - $start;
                $start = $nextStart;
                $end = $nextEnd;
                $lastId = $nextId;
                continue;
            }
            if ($nextEnd > $end) {
                $end = $nextEnd;
                $lastId = $nextId;
            }
        }
        return (int) round(($seconds + $end - $start) / 60);
    }

    /**
     * Credita a cada registro apenas os minutos que ele acrescenta à união.
     * Assim a distribuição por tipo fecha com o total mesmo diante de legado
     * sobreposto. O primeiro registro cronológico recebe os minutos comuns.
     */
    public static function creditedMinutes(array $records): array
    {
        usort($records, static fn(array $a, array $b): int => [
            $a['_begin']->getTimestamp(), (int) $a['id'],
        ] <=> [
            $b['_begin']->getTimestamp(), (int) $b['id'],
        ]);
        $covered = [];
        $credits = [];
        foreach ($records as $record) {
            $start = $record['_begin']->getTimestamp();
            $end = $record['_end']->getTimestamp();
            $before = self::coveredSeconds($covered);
            $covered[] = [$start, $end, (int) $record['id']];
            $dummy = [];
            $after = self::unionMinutes($covered, $dummy) * 60;
            $credits[(int) $record['id']] = max(0, (int) round(($after - $before) / 60));
        }
        return $credits;
    }

    public static function calculateDay(?int $expectedMinutes, int $pointedMinutes): array
    {
        $pointedMinutes = max(0, $pointedMinutes);
        if ($expectedMinutes === null) {
            return ['missing' => null, 'extra' => 0, 'occupation' => null, 'state' => 'unconfigured'];
        }
        $expectedMinutes = max(0, $expectedMinutes);
        if ($expectedMinutes === 0) {
            return ['missing' => 0, 'extra' => $pointedMinutes, 'occupation' => null, 'state' => 'off'];
        }
        $missing = max($expectedMinutes - $pointedMinutes, 0);
        $extra = max($pointedMinutes - $expectedMinutes, 0);
        return [
            'missing' => $missing,
            'extra' => $extra,
            'occupation' => round(($pointedMinutes / $expectedMinutes) * 100, 1),
            'state' => $extra > 0 ? 'exceeded' : ($missing === 0 ? 'met' : 'short'),
        ];
    }

    private static function coveredSeconds(array $ranges): int
    {
        $dummy = [];
        return self::unionMinutes($ranges, $dummy) * 60;
    }

    public static function formatMinutes(?int $minutes): string
    {
        if ($minutes === null) {
            return __('Jornada não configurada', 'apontamentos');
        }
        $minutes = max(0, $minutes);
        return intdiv($minutes, 60) . 'h '
            . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) . 'm';
    }

    public static function statusLabel(string $state): string
    {
        return [
            'met' => __('Meta atingida', 'apontamentos'),
            'short' => __('Abaixo da meta', 'apontamentos'),
            'exceeded' => __('Excedente', 'apontamentos'),
            'off' => __('Dia não trabalhado', 'apontamentos'),
            'unconfigured' => __('Sem jornada', 'apontamentos'),
        ][$state] ?? __('Sem jornada', 'apontamentos');
    }

    private static function dateValue($value): ?DateTimeImmutable
    {
        if (!is_string($value)) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        return $date
            && ($errors === false || (!$errors['warning_count'] && !$errors['error_count']))
            && $date->format('Y-m-d') === $value ? $date : null;
    }

    private static function dateTime(string $value): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function nonNegativeInt($value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $value === false ? null : (int) $value;
    }

    private static function userAllowedInEntities(int $userId, array $entities): bool
    {
        if ($userId <= 0) return false;
        foreach ($entities as $entityId) {
            if (PluginApontamentosAppointment::userCanUseEntity($userId, (int) $entityId)) {
                return true;
            }
        }
        return false;
    }

    private static function referenceLabels(array $row): array
    {
        static $cache = [];
        $linkLabel = '';
        $itemtype = (string) ($row['itemtype'] ?? '');
        $itemId = (int) ($row['items_id'] ?? 0);
        if ($itemId > 0 && isset(PluginApontamentosAppointment::getAllowedItemtypes()[$itemtype])) {
            $key = $itemtype . ':' . $itemId;
            if (!array_key_exists($key, $cache)) {
                $item = new $itemtype();
                $cache[$key] = PluginApontamentosAppointment::canReadRelatedItem($item, $itemId)
                    ? trim((string) ($item->fields['name'] ?? ''))
                    : null;
            }
            if ($cache[$key] !== null) {
                $linkLabel = PluginApontamentosAppointment::getAllowedItemtypes()[$itemtype]
                    . ' #' . $itemId
                    . ($cache[$key] !== '' ? ' - ' . $cache[$key] : '');
            }
        }

        $projectLabel = '';
        if (PluginApontamentosAppointment::canUseProjects()) {
            $taskId = (int) ($row['projecttasks_id'] ?? 0);
            $projectId = (int) ($row['projects_id'] ?? 0);
            $referenceType = $taskId > 0 ? 'ProjectTask' : ($projectId > 0 ? 'Project' : '');
            $referenceId = $taskId > 0 ? $taskId : $projectId;
            if ($referenceType !== '') {
                $key = $referenceType . ':' . $referenceId;
                if (!array_key_exists($key, $cache)) {
                    $item = new $referenceType();
                    $cache[$key] = PluginApontamentosAppointment::canReadRelatedItem($item, $referenceId)
                        ? trim((string) ($item->fields['name'] ?? ''))
                        : null;
                }
                if ($cache[$key] !== null) {
                    $projectLabel = ($referenceType === 'ProjectTask' ? __('Tarefa', 'apontamentos') : __('Projeto', 'apontamentos'))
                        . ' #' . $referenceId
                        . ($cache[$key] !== '' ? ' - ' . $cache[$key] : '');
                }
            }
        }
        return [$linkLabel, $projectLabel];
    }

    private static function filterText(array $filters): string
    {
        $parts = [];
        $parts[] = $filters['userId'] > 0
            ? __('Usuário', 'apontamentos') . ': ' . getUserName($filters['userId'])
            : __('Usuários', 'apontamentos') . ': ' . __('todos os autorizados', 'apontamentos');
        if ($filters['appointmentTypeId'] > 0) {
            $parts[] = __('Tipo', 'apontamentos') . ': '
                . PluginApontamentosAppointmentType::displayName($filters['appointmentTypeId']);
        }
        if ($filters['itemtype'] !== '') {
            $linkLabels = PluginApontamentosAppointment::getAllowedItemtypes() + [
                'Project' => __('Projeto', 'apontamentos'),
            ];
            $parts[] = __('Vínculo', 'apontamentos') . ': '
                . ($linkLabels[$filters['itemtype']] ?? $filters['itemtype']);
        }
        if ($filters['projectId'] > 0) $parts[] = __('Projeto', 'apontamentos') . ' #' . $filters['projectId'];
        if ($filters['taskId'] > 0) $parts[] = __('Tarefa', 'apontamentos') . ' #' . $filters['taskId'];
        return implode(' | ', $parts);
    }
}
