<?php

include '../../../inc/includes.php';

Session::checkRight(PluginApontamentosReport::$rightname, READ);

try {
    $filters = PluginApontamentosReport::filtersFromRequest($_GET);
    $report = PluginApontamentosReport::build($filters);
    $filterError = '';
} catch (InvalidArgumentException $exception) {
    $filters = PluginApontamentosReport::filtersFromRequest([]);
    $report = PluginApontamentosReport::build($filters);
    $filterError = $exception->getMessage();
}

$canManageOthers = PluginApontamentosAppointment::canManageOthers();
$canUseProjects = PluginApontamentosAppointment::canUseProjects();
$activeEntity = (int) ($_SESSION['glpiactive_entity'] ?? 0);
$projectOptions = $canUseProjects ? PluginApontamentosAppointmentModal::projectOptions() : [];
$projectTaskOptions = $canUseProjects
    ? PluginApontamentosAppointmentModal::projectTaskOptions($projectOptions)
    : [];
$formatMinutes = static fn(?int $minutes): string => PluginApontamentosReport::formatMinutes($minutes);
$exportQuery = static function (string $kind) use ($filters): string {
    $query = [
        'kind' => $kind,
        'view' => $filters['view'],
        'start' => $filters['start']->format('Y-m-d'),
        'end' => $filters['end']->format('Y-m-d'),
        'users_id' => $filters['userId'],
        'appointmenttypes_id' => $filters['appointmentTypeId'],
        'itemtype' => $filters['itemtype'],
    ];
    if ($filters['itemtype'] === 'Project') {
        $query['projects_id'] = $filters['projectId'];
        $query['projecttasks_id'] = $filters['taskId'];
    } elseif ($filters['itemtype'] !== '') {
        $query['linked_' . $filters['itemtype']] = $filters['linkedId'];
    }
    return http_build_query($query);
};
$pageValue = static function ($value): int {
    $page = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $page === false ? 1 : (int) $page;
};
$summaryPage = $pageValue($_GET['summary_page'] ?? 1);
$detailPage = $pageValue($_GET['detail_page'] ?? 1);
$perPage = 50;
$summaryPages = max(1, (int) ceil(count($report['summary']) / $perPage));
$detailPages = max(1, (int) ceil(count($report['rows']) / $perPage));
$summaryPage = min($summaryPage, $summaryPages);
$detailPage = min($detailPage, $detailPages);
$summaryRows = array_slice($report['summary'], ($summaryPage - 1) * $perPage, $perPage);
$detailRows = array_slice($report['rows'], ($detailPage - 1) * $perPage, $perPage);
$pageLink = static function (array $changes): string {
    return '/plugins/apontamentos/front/report.php?' . http_build_query(array_merge($_GET, $changes));
};

Html::header(
    __('Relatório de produtividade', 'apontamentos'),
    $_SERVER['PHP_SELF'],
    'helpdesk',
    'PluginApontamentosAppointment'
);

echo "<div class='container-xl ap-report'>";
if ($filterError !== '') {
    echo "<div class='alert alert-danger alert-important'><i class='ti ti-alert-circle me-2'></i>"
        . htmlescape($filterError) . '</div>';
}
echo "<div class='card'><div class='card-header'><h2 class='card-title'>"
    . "<i class='ti ti-chart-bar me-2'></i>"
    . __('Relatório gerencial de produtividade', 'apontamentos')
    . "</h2></div><div class='card-body'>";
echo "<form method='get' class='row g-3' id='ap-report-filters'>";

echo "<div class='col-md-2'><label class='form-label'>" . __('Visualização', 'apontamentos') . '</label>';
Dropdown::showFromArray('view', [
    'day' => __('Dia', 'apontamentos'),
    'week' => __('Semana', 'apontamentos'),
    'month' => __('Mês', 'apontamentos'),
    'custom' => __('Período personalizado', 'apontamentos'),
], ['value' => $filters['view'], 'display_emptychoice' => false]);
echo '</div>';
echo "<div class='col-md-2'><label class='form-label'>" . __('Data inicial', 'apontamentos') . "</label><input class='form-control' type='date' name='start' value='" . $filters['start']->format('Y-m-d') . "' required></div>";
echo "<div class='col-md-2'><label class='form-label'>" . __('Data final', 'apontamentos') . "</label><input class='form-control' type='date' name='end' value='" . $filters['end']->format('Y-m-d') . "' required></div>";
echo "<div class='col-md-2'><label class='form-label'>" . __('Tipo de apontamento', 'apontamentos') . '</label>';
Dropdown::showFromArray(
    'appointmenttypes_id',
    [0 => __('Todos', 'apontamentos')] + PluginApontamentosAppointmentType::reportOptions(),
    ['value' => $filters['appointmentTypeId'], 'display_emptychoice' => false]
);
echo '</div>';
echo "<div class='col-md-2'><label class='form-label'>" . __('Usuário', 'apontamentos') . '</label>';
if ($canManageOthers) {
    User::dropdown([
        'name' => 'users_id',
        'value' => $filters['userId'],
        'entity' => $activeEntity,
        'right' => 'all',
        'display_emptychoice' => true,
    ]);
} else {
    echo htmlescape(getUserName(Session::getLoginUserID()));
    echo Html::hidden('users_id', ['value' => Session::getLoginUserID()]);
}
echo '</div>';

$linkTypeOptions = ['' => __('Todos', 'apontamentos')]
    + PluginApontamentosAppointment::getAllowedItemtypes();
if ($canUseProjects) {
    $linkTypeOptions['Project'] = __('Projeto', 'apontamentos');
}
echo "<div class='w-100 ap-report-link-row-break' aria-hidden='true'></div>";
echo "<div class='col-md-2'><label class='form-label'>" . __('Tipo de vínculo', 'apontamentos') . '</label>';
Dropdown::showFromArray(
    'itemtype',
    $linkTypeOptions,
    ['value' => $filters['itemtype'], 'display_emptychoice' => false]
);
echo '</div>';
echo "<div class='col-md-4 ap-report-linked-field'><label class='form-label'>" . __('Registro vinculado', 'apontamentos') . '</label>';
foreach (array_keys(PluginApontamentosAppointment::getAllowedItemtypes()) as $itemtype) {
    echo "<span class='ap-report-linked' data-itemtype='" . htmlescape($itemtype) . "'>";
    Dropdown::showFromArray(
        'linked_' . $itemtype,
        [0 => __('Todos', 'apontamentos')]
            + PluginApontamentosAppointment::getAccessibleLinkOptions($itemtype, $activeEntity),
        [
            'value' => $filters['itemtype'] === $itemtype ? $filters['linkedId'] : 0,
            'display_emptychoice' => false,
            'width' => '100%',
        ]
    );
    echo '</span>';
}
echo "<span class='ap-report-no-linked text-muted'>" . __('Selecione o tipo de vínculo.', 'apontamentos') . '</span></div>';

if ($canUseProjects) {
    echo "<div class='col-md-3 ap-report-project-field' hidden><label class='form-label' for='ap-report-project'>" . __('Projeto', 'apontamentos') . "</label><select class='form-select' id='ap-report-project' name='projects_id' disabled><option value='0'>" . htmlescape(__('Todos', 'apontamentos')) . '</option>';
    foreach ($projectOptions as $projectId => $projectName) {
        $selected = $filters['projectId'] === (int) $projectId ? ' selected' : '';
        echo "<option value='" . (int) $projectId . "'$selected>" . htmlescape((string) $projectName) . '</option>';
    }
    echo "</select></div><div class='col-md-3 ap-report-project-field' hidden><label class='form-label' for='ap-report-task'>" . __('Tarefa do projeto', 'apontamentos') . " <span class='text-muted'>(" . __('opcional', 'apontamentos') . ")</span></label><select class='form-select' id='ap-report-task' name='projecttasks_id' disabled><option value='0'>" . htmlescape(__('Todas', 'apontamentos')) . '</option>';
    foreach ($projectTaskOptions as $task) {
        $selected = $filters['taskId'] === (int) $task['id'] ? ' selected' : '';
        echo "<option value='" . (int) $task['id'] . "' data-project-id='" . (int) $task['projects_id'] . "'$selected>" . htmlescape((string) $task['name']) . '</option>';
    }
    echo '</select></div>';
}

echo "<div class='col-12 d-flex gap-2 flex-wrap'>";
echo "<button class='btn btn-primary' type='submit'><i class='ti ti-filter'></i> " . __('Aplicar filtros', 'apontamentos') . '</button>';
echo "<a class='btn btn-outline-secondary' href='/plugins/apontamentos/front/report.php'><i class='ti ti-x'></i> " . __('Limpar filtros', 'apontamentos') . '</a>';
echo "<a class='btn btn-outline-primary' href='/plugins/apontamentos/ajax/export.php?" . htmlescape($exportQuery('detailed')) . "'><i class='ti ti-file-spreadsheet'></i> " . __('CSV detalhado', 'apontamentos') . '</a>';
echo "<a class='btn btn-outline-primary' href='/plugins/apontamentos/ajax/export.php?" . htmlescape($exportQuery('summary')) . "'><i class='ti ti-file-analytics'></i> " . __('CSV gerencial', 'apontamentos') . '</a>';
echo "<a class='btn btn-outline-danger' target='_blank' rel='noopener' href='/plugins/apontamentos/ajax/report.pdf.php?" . htmlescape($exportQuery('pdf')) . "'><i class='ti ti-file-type-pdf'></i> " . __('Abrir PDF', 'apontamentos') . '</a>';
echo '</div></form></div></div>';

if ($report['overlapIds'] !== []) {
    echo "<div class='alert alert-warning mt-3'><i class='ti ti-alert-triangle me-2'></i>"
        . __('Foram encontrados registros históricos sobrepostos. Os indicadores não contam minutos duplicados.', 'apontamentos')
        . '</div>';
}

$cards = [
    [__('Jornada esperada', 'apontamentos'), $formatMinutes($report['totals']['expected']), 'secondary', 'ti-calendar-time'],
    [__('Horas apontadas', 'apontamentos'), $formatMinutes($report['totals']['pointed']), 'primary', 'ti-clock-check'],
    [__('Horas não apontadas (ociosidade calculada)', 'apontamentos'), $formatMinutes($report['totals']['missing']), 'warning', 'ti-clock-pause'],
    [__('Horas excedentes', 'apontamentos'), $formatMinutes($report['totals']['extra']), 'purple', 'ti-clock-plus'],
    [__('Percentual de ocupação', 'apontamentos'), $report['totals']['occupation'] === null ? __('Jornada não configurada', 'apontamentos') : $report['totals']['occupation'] . '%', $report['totals']['occupation'] !== null && $report['totals']['missing'] === 0 ? 'success' : 'danger', 'ti-percentage'],
    [__('Quantidade de apontamentos', 'apontamentos'), (string) $report['totals']['count'], 'secondary', 'ti-list-check'],
    [__('Usuários analisados', 'apontamentos'), (string) $report['totals']['operators'], 'secondary', 'ti-users'],
];
echo "<div class='row row-cards mt-1'>";
foreach ($cards as [$label, $value, $color, $icon]) {
    echo "<div class='col-sm-6 col-xl-3'><div class='card card-sm h-100'><div class='card-body'>"
        . "<div class='text-secondary'><i class='ti " . htmlescape($icon) . " me-1'></i>" . htmlescape($label) . '</div>'
        . "<div class='h2 m-0 text-" . htmlescape($color) . "'>" . htmlescape($value) . '</div>'
        . '</div></div></div>';
}
echo '</div>';

$dateTotals = [];
foreach ($summaryRows as $line) {
    $key = $line['dateKey'];
    $dateTotals[$key] ??= ['expected' => 0, 'pointed' => 0, 'missing' => 0, 'extra' => 0];
    if ($line['expectedMinutes'] !== null) {
        $dateTotals[$key]['expected'] += $line['expectedMinutes'];
        $dateTotals[$key]['missing'] += $line['missingMinutes'];
        $dateTotals[$key]['extra'] += $line['extraMinutes'];
    }
    $dateTotals[$key]['pointed'] += $line['pointedMinutes'];
}
echo "<div class='row row-cards mt-1'><div class='col-lg-7'><div class='card h-100'><div class='card-header'><h3 class='card-title'>" . __('Jornada versus horas apontadas', 'apontamentos') . "</h3></div><div class='card-body ap-report-bars'>";
foreach ($dateTotals as $date => $values) {
    $maximum = max(1, $values['expected'], $values['pointed']);
    echo "<div class='ap-report-bar-row'><span>" . htmlescape((new DateTimeImmutable($date))->format('d/m')) . "</span><div class='ap-report-track'>"
        . "<i class='ap-report-bar ap-expected' style='width:" . min(100, round($values['expected'] / $maximum * 100)) . "%' title='" . htmlescape(__('Jornada esperada', 'apontamentos')) . "'></i>"
        . "<i class='ap-report-bar ap-pointed' style='width:" . min(100, round($values['pointed'] / $maximum * 100)) . "%' title='" . htmlescape(__('Horas apontadas', 'apontamentos')) . "'></i></div>"
        . '<strong>' . htmlescape($formatMinutes($values['pointed'])) . '</strong></div>';
}
echo "<div class='ap-report-legend'><span><i class='ap-legend-expected'></i>" . __('Jornada esperada', 'apontamentos') . "</span><span><i class='ap-legend-pointed'></i>" . __('Horas apontadas', 'apontamentos') . '</span></div></div></div></div>';

echo "<div class='col-lg-5'><div class='card h-100'><div class='card-header'><h3 class='card-title'>" . __('Distribuição por tipo de apontamento', 'apontamentos') . "</h3></div><div class='card-body ap-report-types'>";
foreach ($report['types'] as $type) {
    echo "<div><span class='ap-type-color' style='background:" . htmlescape($type['color']) . "'></span>" . htmlescape($type['name'])
        . '<strong>' . htmlescape($formatMinutes($type['minutes'])) . ' (' . htmlescape((string) $type['percent']) . "%)</strong><div class='progress'><div class='progress-bar' style='width:"
        . min(100, $type['percent']) . '%;background:' . htmlescape($type['color']) . "'></div></div></div>";
}
if ($report['types'] === []) {
    echo "<span class='text-muted'>" . __('Sem dados para os filtros selecionados.', 'apontamentos') . '</span>';
}
echo '</div></div></div></div>';

echo "<div class='card mt-3'><div class='card-header'><h3 class='card-title'>" . __('Consolidado por tipo de apontamento', 'apontamentos') . "</h3></div><div class='table-responsive'><table class='table table-vcenter'><thead><tr><th>" . __('Cor', 'apontamentos') . '</th><th>' . __('Tipo de apontamento', 'apontamentos') . '</th><th>' . __('Quantidade', 'apontamentos') . '</th><th>' . __('Total de horas', 'apontamentos') . '</th><th>' . __('Participação', 'apontamentos') . '</th></tr></thead><tbody>';
foreach ($report['types'] as $type) {
    echo "<tr><td><span class='ap-type-color' style='background:" . htmlescape($type['color']) . "'></span></td><td>" . htmlescape($type['name']) . '</td><td>' . (int) $type['count'] . '</td><td>' . htmlescape($formatMinutes($type['minutes'])) . '</td><td>' . htmlescape((string) $type['percent']) . '%</td></tr>';
}
echo '</tbody></table></div></div>';

echo "<div class='card mt-3'><div class='card-header'><h3 class='card-title'>" . __('Evolução da ocupação no período', 'apontamentos') . "</h3></div><div class='card-body ap-occupation-chart'>";
foreach ($dateTotals as $date => $values) {
    $percent = $values['expected'] > 0 ? round($values['pointed'] / $values['expected'] * 100, 1) : null;
    echo "<div class='ap-occupation-column'><div class='ap-occupation-value'>" . ($percent === null ? '—' : htmlescape((string) $percent) . '%') . "</div><div class='ap-occupation-track'><i style='height:"
        . ($percent === null ? 0 : min(100, $percent)) . "%' class='" . ($percent !== null && $percent >= 100 ? 'is-met' : 'is-short') . "'></i></div><span>" . htmlescape((new DateTimeImmutable($date))->format('d/m')) . '</span></div>';
}
echo '</div></div>';

if ($canManageOthers) {
    echo "<div class='card mt-3'><div class='card-header'><h3 class='card-title'>" . __('Comparação entre usuários', 'apontamentos') . "</h3></div><div class='table-responsive'><table class='table table-vcenter'><thead><tr><th>" . __('Usuário', 'apontamentos') . '</th><th>' . __('Jornada', 'apontamentos') . '</th><th>' . __('Apontadas', 'apontamentos') . '</th><th>' . __('Não apontadas', 'apontamentos') . '</th><th>' . __('Ocupação', 'apontamentos') . '</th></tr></thead><tbody>';
    foreach ($report['operators'] as $userId => $name) {
        $operatorLines = array_filter($report['summary'], static fn(array $line): bool => $line['userId'] === $userId);
        $expected = array_sum(array_map(static fn(array $line): int => (int) ($line['expectedMinutes'] ?? 0), $operatorLines));
        $pointed = array_sum(array_column($operatorLines, 'pointedMinutes'));
        echo '<tr><td>' . htmlescape($name) . '</td><td>' . htmlescape($formatMinutes($expected)) . '</td><td>' . htmlescape($formatMinutes($pointed)) . '</td><td>' . htmlescape($formatMinutes(max($expected - $pointed, 0))) . '</td><td>' . ($expected > 0 ? htmlescape((string) round($pointed / $expected * 100, 1)) . '%' : '—') . '</td></tr>';
    }
    echo '</tbody></table></div></div>';
}

echo "<div class='card mt-3'><div class='card-header'><h3 class='card-title'>" . __('Consolidado por usuário e data', 'apontamentos') . "</h3></div><div class='table-responsive'><table class='table table-vcenter'><thead><tr><th>" . __('Usuário', 'apontamentos') . '</th><th>' . __('Data', 'apontamentos') . '</th><th>' . __('Jornada', 'apontamentos') . '</th><th>' . __('Apontadas', 'apontamentos') . '</th><th>' . __('Não apontadas', 'apontamentos') . '</th><th>' . __('Excedentes', 'apontamentos') . '</th><th>' . __('Ocupação', 'apontamentos') . '</th><th>' . __('Quantidade', 'apontamentos') . '</th><th>' . __('Situação', 'apontamentos') . '</th></tr></thead><tbody>';
foreach ($report['summary'] as $line) {
    echo '<tr><td>' . htmlescape($line['name']) . '</td><td>' . htmlescape((new DateTimeImmutable($line['dateKey']))->format('d/m/Y')) . '</td><td>' . htmlescape($formatMinutes($line['expectedMinutes'])) . '</td><td>' . htmlescape($formatMinutes($line['pointedMinutes'])) . '</td><td>' . htmlescape($formatMinutes($line['missingMinutes'])) . '</td><td>' . htmlescape($formatMinutes($line['extraMinutes'])) . '</td><td>' . ($line['occupation'] === null ? '—' : htmlescape((string) $line['occupation']) . '%') . '</td><td>' . (int) $line['count'] . '</td><td>' . htmlescape(PluginApontamentosReport::statusLabel($line['state'])) . '</td></tr>';
}
echo '</tbody></table></div>';
if ($summaryPages > 1) {
    echo "<div class='card-footer d-flex justify-content-between'><span>" . sprintf(__('Página %1$d de %2$d', 'apontamentos'), $summaryPage, $summaryPages) . '</span><div>';
    if ($summaryPage > 1) echo "<a class='btn btn-sm btn-outline-secondary me-1' href='" . htmlescape($pageLink(['summary_page' => $summaryPage - 1])) . "'>" . __('Anterior', 'apontamentos') . '</a>';
    if ($summaryPage < $summaryPages) echo "<a class='btn btn-sm btn-outline-secondary' href='" . htmlescape($pageLink(['summary_page' => $summaryPage + 1])) . "'>" . __('Próxima', 'apontamentos') . '</a>';
    echo '</div></div>';
}
echo '</div>';

echo "<div class='card mt-3'><div class='card-header'><h3 class='card-title'>" . __('Apontamentos que formam o total', 'apontamentos') . "</h3></div><div class='table-responsive'><table class='table table-vcenter'><thead><tr><th>" . __('Início', 'apontamentos') . '</th><th>' . __('Fim', 'apontamentos') . '</th><th>' . __('Duração', 'apontamentos') . '</th><th>' . __('Tipo de apontamento', 'apontamentos') . '</th><th>' . __('Vínculo', 'apontamentos') . '</th><th>' . __('Projeto / tarefa', 'apontamentos') . '</th><th>' . __('Conteúdo', 'apontamentos') . '</th></tr></thead><tbody>';
foreach ($detailRows as $row) {
    $minutes = (int) round(($row['_end']->getTimestamp() - $row['_begin']->getTimestamp()) / 60);
    echo '<tr><td>' . htmlescape($row['_begin']->format('d/m/Y H:i')) . '</td><td>' . htmlescape($row['_end']->format('d/m/Y H:i')) . '</td><td>' . htmlescape($formatMinutes($minutes)) . '</td><td>' . htmlescape(PluginApontamentosAppointmentType::displayName((int) $row['appointmenttypes_id'])) . '</td><td>' . htmlescape((string) $row['_link_label']) . '</td><td>' . htmlescape((string) $row['_project_label']) . '</td><td>' . htmlescape((string) $row['content']) . '</td></tr>';
}
echo '</tbody></table></div>';
if ($detailPages > 1) {
    echo "<div class='card-footer d-flex justify-content-between'><span>" . sprintf(__('Página %1$d de %2$d', 'apontamentos'), $detailPage, $detailPages) . '</span><div>';
    if ($detailPage > 1) echo "<a class='btn btn-sm btn-outline-secondary me-1' href='" . htmlescape($pageLink(['detail_page' => $detailPage - 1])) . "'>" . __('Anterior', 'apontamentos') . '</a>';
    if ($detailPage < $detailPages) echo "<a class='btn btn-sm btn-outline-secondary' href='" . htmlescape($pageLink(['detail_page' => $detailPage + 1])) . "'>" . __('Próxima', 'apontamentos') . '</a>';
    echo '</div></div>';
}
echo '</div></div>';

?>
<style>
.ap-report-bar-row{display:grid;grid-template-columns:52px 1fr 75px;gap:8px;align-items:center;margin:12px 0}.ap-report-track{height:20px;background:#edf0f2;position:relative;border-radius:4px}.ap-report-bar{display:block;position:absolute;left:0;height:7px;border-radius:4px}.ap-report-bar.ap-expected{top:2px;background:#9ca3af}.ap-report-bar.ap-pointed{bottom:2px;background:#206bc4}.ap-report-legend{display:flex;gap:18px;margin-top:15px}.ap-report-legend i{width:12px;height:7px;display:inline-block;margin-right:5px}.ap-legend-expected{background:#9ca3af}.ap-legend-pointed{background:#206bc4}.ap-report-types>div{margin-bottom:14px}.ap-report-types strong{float:right}.ap-type-color{width:12px;height:12px;border-radius:50%;display:inline-block;margin-right:6px}.ap-report-types .progress{clear:both;height:7px;margin-top:5px}.ap-occupation-chart{display:flex;align-items:flex-end;gap:10px;min-height:220px;overflow-x:auto}.ap-occupation-column{min-width:44px;text-align:center;font-size:.75rem}.ap-occupation-track{height:150px;width:26px;background:#edf0f2;margin:4px auto;display:flex;align-items:flex-end;border-radius:4px;overflow:hidden}.ap-occupation-track i{display:block;width:100%;background:#d63939}.ap-occupation-track i.is-met{background:#2fb344}.ap-report-linked-field[hidden],.ap-report-project-field[hidden],.ap-report-linked[hidden]{display:none!important}@media(max-width:768px){.ap-report-bar-row{grid-template-columns:45px 1fr 65px}.ap-report .table{font-size:.8rem}}
</style>
<script>
(() => {
  const type = document.querySelector('#ap-report-filters [name="itemtype"]');
  if (type) {
    const linkedField = document.querySelector('.ap-report-linked-field');
    const projectFields = Array.from(document.querySelectorAll('.ap-report-project-field'));
    const project = document.querySelector('#ap-report-project');
    const task = document.querySelector('#ap-report-task');
    const refreshTasks = () => {
      if (!project || !task) return;
      const projectId = project.value;
      let selectedTaskIsValid = task.value === '0';
      Array.from(task.options).forEach((option) => {
        if (option.value === '0') return;
        const matches = projectId !== '0' && option.dataset.projectId === projectId;
        option.hidden = !matches;
        option.disabled = !matches;
        if (matches && option.selected) selectedTaskIsValid = true;
      });
      if (!selectedTaskIsValid) task.value = '0';
      task.disabled = type.value !== 'Project' || projectId === '0';
    };
    const refresh = () => {
      const isProject = type.value === 'Project';
      if (linkedField) linkedField.hidden = isProject;
      document.querySelectorAll('.ap-report-linked').forEach((item) => {
        const active = !isProject && item.dataset.itemtype === type.value;
        item.hidden = !active;
        const select = item.querySelector('select');
        if (select) select.disabled = !active;
      });
      const empty = document.querySelector('.ap-report-no-linked');
      if (empty) empty.hidden = type.value !== '';
      projectFields.forEach((field) => { field.hidden = !isProject; });
      if (project) {
        project.disabled = !isProject;
        if (!isProject) project.value = '0';
      }
      if (!isProject && task) task.value = '0';
      refreshTasks();
    };
    type.addEventListener('change', refresh);
    if (window.jQuery) window.jQuery(type).on('select2:select', refresh);
    project?.addEventListener('change', refreshTasks);
    refresh();
  }
  const view = document.querySelector('#ap-report-filters [name="view"]');
  const start = document.querySelector('#ap-report-filters [name="start"]');
  const end = document.querySelector('#ap-report-filters [name="end"]');
  const formatDate = (date) => `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
  const updatePeriod = () => {
    if (!view || !start || !end || view.value === 'custom') return;
    const anchor = /^\d{4}-\d{2}-\d{2}$/.test(start.value)
      ? new Date(`${start.value}T12:00:00`)
      : new Date();
    let first = new Date(anchor); let last = new Date(anchor);
    if (view.value === 'week') {
      first.setDate(first.getDate() - first.getDay());
      last = new Date(first); last.setDate(first.getDate() + 6);
    } else if (view.value === 'month') {
      first = new Date(anchor.getFullYear(), anchor.getMonth(), 1, 12);
      last = new Date(anchor.getFullYear(), anchor.getMonth() + 1, 0, 12);
    }
    start.value = formatDate(first); end.value = formatDate(last);
  };
  if (view) {
    view.addEventListener('change', updatePeriod);
    if (window.jQuery) window.jQuery(view).on('select2:select', updatePeriod);
  }
})();
</script>
<?php Html::footer();
