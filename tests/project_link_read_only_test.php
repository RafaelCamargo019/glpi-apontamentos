<?php

$root = dirname(__DIR__);
$appointment = (string) file_get_contents($root . '/inc/appointment.class.php');
$modal = (string) file_get_contents($root . '/inc/appointmentmodal.class.php');
$calendar = (string) file_get_contents($root . '/js/calendar.js');
$css = (string) file_get_contents($root . '/css/calendar.css');
$itemTab = (string) file_get_contents($root . '/inc/itemtab.class.php');
$errors = [];
$checks = [];
$check = static function (string $label, bool $condition) use (&$errors, &$checks): void {
    $checks[] = $label;
    if (!$condition) $errors[] = 'Falhou: ' . $label;
};

$check('Projeto entra nas opções de Vincular a somente com permissão', str_contains($modal, "\$linkOptions['Project'] = __('Projeto', 'apontamentos')")
    && str_contains($modal, 'if ($canUseProjects)'));
$check('seletores de projeto ficam no bloco de vínculo', str_contains($modal, "class='ap-details-grid ap-modal-project-fields' hidden")
    && str_contains($modal, "id='ap-modal-project'") && str_contains($modal, "id='ap-modal-task'"));
$check('projeto começa oculto e desabilitado', str_contains($modal, "name='projects_id' disabled")
    && str_contains($modal, "name='projecttasks_id' disabled"));
$check('seleção Projeto mostra os dois campos', str_contains($calendar, "const isProject = selectedType === 'Project'")
    && str_contains($calendar, 'modalProjectFields.hidden = !isProject'));
$check('seleção ITIL oculta projeto e mostra registro', str_contains($calendar, 'linkedField.hidden = isProject')
    && str_contains($calendar, 'const active = !isProject && select.dataset.linkType === selectedType'));
$check('troca para ITIL limpa projeto anterior', str_contains($calendar, "if (!isProject) modalProject.value = '0'")
    && str_contains($calendar, "modalTask.value = '0'"));
$check('tarefas dependem do projeto selecionado', str_contains($calendar, 'option.dataset.projectId === projectId')
    && str_contains($calendar, 'modalTask.disabled = modalProject.disabled'));
$check('servidor reconhece Project sem tratá-lo como ITIL', str_contains($appointment, "if (\$type === 'Project')")
    && str_contains($appointment, "\$input['itemtype'] = null")
    && str_contains($appointment, "\$input['items_id'] = 0"));
$check('servidor torna vínculos ITIL e projeto exclusivos', str_contains($appointment, "\$input['projects_id'] = 0")
    && str_contains($appointment, "\$input['projecttasks_id'] = 0"));
$check('formulário tradicional oferece Projeto no mesmo seletor', str_contains($appointment, "\$linkTypeOptions['Project'] = __('Projeto', 'apontamentos')")
    && str_contains($appointment, "Dropdown::showFromArray('link_type'"));
$check('formulário tradicional move os campos para a linha do vínculo', str_contains($appointment, "ap-layout-row ap-link-row")
    && str_contains($appointment, 'ap-itil-link-fields') && substr_count($appointment, 'ap-project-fields') >= 3);
$check('formulário tradicional alterna os grupos', str_contains($appointment, "const projectSelected = type.value === 'Project'")
    && str_contains($appointment, "el.style.display = projectSelected ? 'inline-flex' : 'none'"));
$check('formulário preserva Projeto depois de erro', str_contains($appointment, "(string) (\$formInput['link_type'] ?? '') === 'Project'"));
$check('modal contextual mantém vínculo fixo', str_contains($modal, 'if ($fixedContext !== null)')
    && str_contains($modal, 'ap-fixed-link') && str_contains($itemTab, 'bindInputToContext'));
$check('campos condicionais não ocupam espaço', str_contains($css, '.ap-modal-project-fields[hidden] { display: none !important; }'));
$check('teste não grava dados', !preg_match('/^\s*\$[A-Za-z_][A-Za-z0-9_]*->add\(/m', (string) file_get_contents(__FILE__)));
$check('implementação não altera esquema', !preg_match('/ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE|ADD\s+COLUMN/i', $appointment . $modal . $calendar . $css));

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
echo 'OK - ' . count($checks) . " verificações do vínculo por projeto concluídas.\n";
