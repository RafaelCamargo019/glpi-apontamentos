<?php

if (!class_exists('CommonDBTM')) {
    class CommonDBTM {}
}
require_once dirname(__DIR__) . '/inc/appointment.class.php';

$root = dirname(__DIR__);
$appointment = (string) file_get_contents($root . '/inc/appointment.class.php');
$form = (string) file_get_contents($root . '/front/appointment.form.php');
$itemTab = (string) file_get_contents($root . '/inc/itemtab.class.php');
$errors = [];
$checks = [];
$check = static function (string $label, bool $condition) use (&$errors, &$checks): void {
    $checks[] = $label;
    if (!$condition) $errors[] = 'Falhou: ' . $label;
};

$check(
    'data e hora inicial formam begin_time',
    PluginApontamentosAppointment::combineDateAndTime('2026-08-25', '10:00')
        === '2026-08-25 10:00:00'
);
$check(
    'data e hora final formam end_time',
    PluginApontamentosAppointment::combineDateAndTime('2026-08-25', '11:00')
        === '2026-08-25 11:00:00'
);
$check(
    'alterar a data altera internamente início e fim',
    PluginApontamentosAppointment::combineDateAndTime('2026-08-26', '10:00') === '2026-08-26 10:00:00'
        && PluginApontamentosAppointment::combineDateAndTime('2026-08-26', '11:00')
        === '2026-08-26 11:00:00'
);
$check('hora malformada é rejeitada', PluginApontamentosAppointment::combineDateAndTime('2026-08-25', '25:00') === null);
$check('data malformada é rejeitada', PluginApontamentosAppointment::combineDateAndTime('25/08/2026', '11:00') === null);
$check('fim igual ao início é rejeitado', !PluginApontamentosAppointment::isValidSameDayInterval('2026-08-25 10:00:00', '2026-08-25 10:00:00'));
$check('fim anterior ao início é rejeitado', !PluginApontamentosAppointment::isValidSameDayInterval('2026-08-25 10:00:00', '2026-08-25 09:59:00'));
$check('dia seguinte não é inferido', !PluginApontamentosAppointment::isValidSameDayInterval('2026-08-25 23:00:00', '2026-08-26 01:00:00'));
$check('intervalo válido no mesmo dia é aceito', PluginApontamentosAppointment::isValidSameDayInterval('2026-08-25 10:00:00', '2026-08-25 11:00:00'));
$check('tela possui três campos independentes', str_contains($appointment, "type='date' name='appointment_date'")
    && str_contains($appointment, "type='time' name='begin_time_hour'")
    && str_contains($appointment, "type='time' name='end_time_hour'"));
$check('não há datetime completo visível', !str_contains($appointment, "type='datetime-local'"));
$check('tipo, data e horários ficam na mesma linha e compactos', str_contains($appointment, "class='ap-layout-row ap-main-row'")
    && substr_count($appointment, "class='ap-layout-row ap-main-row'") === 1
    && str_contains($appointment, '.ap-date-control{width:170px}')
    && str_contains($appointment, '.ap-time-control{width:90px}'));
$check('rótulos permanecem próximos dos campos', str_contains($appointment, '.ap-field-group{display:inline-flex;align-items:center;justify-content:flex-start;gap:8px'));
$check('seletores e conteúdo não usam colunas largas da tabela', str_contains($appointment, "class='plugin-apontamentos-form'")
    && str_contains($appointment, "class='ap-field-group ap-content-group'")
    && str_contains($appointment, '.plugin-apontamentos-form .ap-field-control .select2-container{width:100%!important'));
$check('relógios ficam próximos e seletores podem ultrapassar', str_contains($appointment, 'ti ti-clock ap-compact-clock')
    && str_contains($appointment, '::-webkit-calendar-picker-indicator')
    && str_contains($appointment, 'opacity:0;cursor:pointer')
    && str_contains($appointment, 'right:8px'));
$check('edição extrai data e duas horas', str_contains($appointment, "substr((string) \$this->fields['begin_time'], 0, 10)")
    && str_contains($appointment, "substr((string) \$this->fields['begin_time'], 11, 5)")
    && str_contains($appointment, "substr((string) \$this->fields['end_time'], 11, 5)"));
$check('datas completas forjadas são ignoradas', str_contains($appointment, "unset(\$input['begin_time'], \$input['end_time'])")
    && str_contains($appointment, 'normalizeSubmittedTimes'));
$check('mensagem unificada é clara', str_contains($appointment, 'O horário final deve ser posterior ao horário inicial e permanecer no mesmo dia.'));
$check('erro preserva os três campos e os demais dados', str_contains($appointment, "\$input['appointment_date']")
    && str_contains($appointment, "\$input['begin_time_hour']")
    && str_contains($appointment, "\$input['end_time_hour']")
    && substr_count($form, "\$formOptions['form_input'] = \$_POST") >= 2);
$check('calendário continua preenchendo o período', str_contains($appointment, "\$_GET['begin_time']")
    && str_contains($appointment, "\$_GET['end_time']")
    && str_contains($appointment, 'validPrefillDateTime'));
$check('sobreposição permanece ativa', str_contains($appointment, 'findOverlap(') && str_contains($appointment, 'intervalsOverlap('));
$check('intervalo consecutivo permanece permitido', str_contains($appointment, "['begin_time' => ['<', \$end]]") && str_contains($appointment, "['end_time' => ['>', \$begin]]"));
$check('formulário central atende abas ITIL e projeto', str_contains($itemTab, 'SUPPORTED_ITEMTYPES')
    && str_contains($itemTab, "'Ticket'") && str_contains($itemTab, "'Problem'")
    && str_contains($itemTab, "'Change'") && str_contains($itemTab, "'Project'")
    && str_contains($itemTab, 'getFormURL'));
$check('sem alteração de esquema ou escrita em teste', !preg_match('/ALTER\s+TABLE|ADD\s+COLUMN|DROP\s+TABLE/i', $appointment)
    && !preg_match('/\$DB->(?:insert|update|delete)/i', (string) file_get_contents(__FILE__)));

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
echo 'OK - ' . count($checks) . " verificações dos campos Data, Início e Fim concluídas.\n";
