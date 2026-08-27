<?php

$root = dirname(__DIR__);
$required = [
    'setup.php', 'hook.php',
    'inc/appointment.class.php', 'inc/appointmenttype.class.php', 'inc/config.class.php',
    'inc/profile.class.php', 'inc/report.class.php', 'inc/reportpdf.class.php', 'inc/projectright.class.php', 'inc/itemtab.class.php',
    'inc/appointmentmodal.class.php',
    'front/appointment.php', 'front/appointment.form.php', 'front/config.form.php', 'front/report.php',
    'front/calendar.css.php', 'front/calendar.js.php', 'front/itemtab.js.php',
    'ajax/events.php', 'ajax/export.php', 'ajax/report.pdf.php', 'ajax/delete.php', 'ajax/create.php',
    'ajax/create_context.php', 'ajax/itemtab.php',
    'js/calendar.js', 'js/itemtab.js', 'css/calendar.css', 'tests/runtime_read_only_test.php',
    'tests/report_read_only_test.php', 'tests/report_runtime_read_only_test.php',
    'tests/time_only_end_read_only_test.php',
    'tests/form_layout_read_only_test.php',
    'tests/modal_read_only_test.php', 'tests/modal_runtime_read_only_test.php',
    'tests/itemtab_modal_read_only_test.php',
    'tests/project_link_read_only_test.php',
    'tests/pdf_render_test.php', 'README.md', 'CHANGELOG.md', '.gitignore', '.gitattributes',
];
$errors = [];
$files = [];
foreach ($required as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        $errors[] = "Arquivo ausente: $file";
        continue;
    }
    $files[$file] = (string) file_get_contents($path);
}
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
$source = implode("\n", $files);
$hook = $files['hook.php'];
$appointment = $files['inc/appointment.class.php'];
$typeClass = $files['inc/appointmenttype.class.php'];
$config = $files['inc/config.class.php'];
$configForm = $files['front/config.form.php'];
$events = $files['ajax/events.php'];
$calendar = $files['js/calendar.js'];
$report = $files['front/report.php'];
$export = $files['ajax/export.php'];
$itemTab = $files['inc/itemtab.class.php'];

preg_match_all('/DELETE\s+FROM\s+`?[^\s`"\x27]+/i', implode("\n", array_diff_key($files, array_flip([
    'tests/runtime_read_only_test.php', 'README.md',
]))), $rawDeletes);
$typeMigrationPosition = strpos($hook, '$typeMigrationMarker');
$scheduleMigrationPosition = strpos($hook, 'foreach ($DB->request([', strpos($hook, 'Migração não destrutiva'));

$checks = [
    'versão 2.9.0' => str_contains($files['setup.php'], "PLUGIN_APONTAMENTOS_VERSION', '2.9.0'"),
    'autoria interna identificada' => str_contains($files['setup.php'], "'author'       => 'Rafael - Mkdata'")
        && !str_contains($files['setup.php'], "'license'"),
    'classe de tipo registrada' => str_contains($files['setup.php'], "Plugin::registerClass('PluginApontamentosAppointmentType')"),
    'proteção CSRF declarada' => str_contains($files['setup.php'], "csrf_compliant"),
    'tabela de tipos condicional' => str_contains($hook, 'glpi_plugin_apontamentos_types')
        && str_contains($hook, 'if (!$DB->tableExists($typesTable))'),
    'estrutura do tipo completa' => str_contains($hook, '`name` varchar(255)')
        && str_contains($hook, '`color` char(7)')
        && str_contains($hook, '`is_active` tinyint')
        && str_contains($hook, '`is_deleted` tinyint'),
    'tipo Geral criado somente com tabela vazia' => str_contains($hook, "count(\$DB->request(['FROM' => \$typesTable])) === 0")
        && str_contains($hook, "'name' => 'Geral'") && str_contains($hook, "'color' => '#206BC4'"),
    'coluna de tipo criada condicionalmente' => str_contains($hook, "addField(\$table, 'appointmenttypes_id'")
        && str_contains($hook, "fieldExists(\$table, 'appointmenttypes_id'"),
    'índice de tipo criado' => str_contains($hook, "addKey(\$table, 'appointmenttypes_id')")
        && str_contains($hook, 'KEY `appointmenttypes_id`'),
    'marcador persistente de migração de tipos' => str_contains($hook, 'glpi_plugin_apontamentos_migrations')
        && str_contains($hook, "'appointments_type_migration_2_9_0'") && str_contains($hook, 'UNIQUE KEY `name`'),
    'migração de tipos usa transação' => str_contains($hook, '$DB->beginTransaction()')
        && str_contains($hook, '$DB->commit()') && str_contains($hook, '$DB->rollBack()'),
    'instalação não apaga apontamentos' => count($rawDeletes[0]) === 0
        && !str_contains($hook, 'DELETE FROM `$table`'),
    'registros legados recebem tipo padrão' => str_contains($hook, "'appointmenttypes_id' => \$defaultTypeId")
        && str_contains($hook, "'appointmenttypes_id' => 0"),
    'migração de tipos ocorre após migração de jornadas' => $typeMigrationPosition !== false
        && $scheduleMigrationPosition !== false && $typeMigrationPosition > $scheduleMigrationPosition,
    'desinstalação preserva dados' => !preg_match('/DROP\s+TABLE/i', $hook)
        && str_contains($hook, 'Deliberadamente não remove tabela'),
    'nome do tipo validado' => str_contains($typeClass, 'normalizeName')
        && str_contains($typeClass, 'mb_strlen($name) <= 255'),
    'cor hexadecimal validada' => str_contains($config, "'/^#[0-9A-F]{6}$/'"),
    'tipos ativos oferecidos' => str_contains($typeClass, 'activeOptions')
        && str_contains($typeClass, "!empty(\$row['is_active'])")
        && str_contains($typeClass, "empty(\$row['is_deleted'])"),
    'tipo histórico inativo continua identificável' => str_contains($typeClass, '$id === $includeId')
        && str_contains($typeClass, "__('inativo', 'apontamentos')"),
    'tipo obrigatório no servidor' => str_contains($appointment, "\$input['appointmenttypes_id'] = \$this->positiveInt")
        && str_contains($appointment, 'Selecione um tipo de apontamento válido.'),
    'tipo inexistente bloqueado' => str_contains($appointment, '$appointmentType === null'),
    'tipo inativo ou excluído bloqueado' => str_contains($appointment, "!empty(\$appointmentType['is_deleted'])")
        && str_contains($appointment, "empty(\$appointmentType['is_active'])"),
    'tipo histórico preservado na edição' => str_contains($appointment, '$keepsHistoricalType')
        && str_contains($appointment, '$currentTypeId'),
    'entidade separada e interna' => str_contains($appointment, "'entities_id', 'appointmenttypes_id'")
        && str_contains($appointment, "\$input['entities_id'] = self::activeEntityId()")
        && !str_contains($appointment, "Dropdown::showFromArray(\n            'entities_id'"),
    'somente tipo é obrigatório e visível' => str_contains($appointment, "PluginApontamentosAppointmentType::activeOptions")
        && str_contains($appointment, "__('Tipo de apontamento', 'apontamentos')")
        && str_contains($appointment, "'required' => true"),
    'entidade ativa é validada no servidor' => str_contains($appointment, 'Não foi possível determinar a entidade ativa do GLPI.')
        && str_contains($appointment, 'A entidade selecionada não existe mais.')
        && str_contains($appointment, 'Session::haveAccessToEntity($entityId)'),
    'entidade não pode ser forjada na edição' => str_contains($appointment, "\$input['entities_id'] = (int) \$current['entities_id']"),
    'proprietário respeita usuário filtrado somente para gestor' => str_contains($appointment, '$requestedUser = $this->positiveInt')
        && str_contains($appointment, 'self::canManageOthers() && $requestedUser !== null')
        && str_contains($appointment, ': Session::getLoginUserID()')
        && !str_contains($appointment, "User::dropdown(['name' => 'users_id'"),
    'proprietário não muda na edição' => str_contains($appointment, "\$input['users_id'] = (int) \$current['users_id']"),
    'mesmo dia obrigatório' => str_contains($appointment, 'isValidSameDayInterval')
        && str_contains($appointment, 'O horário final deve ser posterior ao horário inicial e permanecer no mesmo dia.'),
    'conteúdo opcional' => str_contains($appointment, "\$input['content'] = trim")
        && !str_contains($appointment, 'Informe o conteúdo do apontamento'),
    'vínculo ou projeto obrigatório' => str_contains($appointment, 'Vincule um chamado, problema, mudança ou projeto.'),
    'tarefa validada no projeto' => str_contains($appointment, "(int) \$task->fields['projects_id'] !== \$input['projects_id']"),
    'sobreposição centralizada e funcional' => str_contains($appointment, 'findOverlap(')
        && str_contains($appointment, "['begin_time' => ['<', \$end]]")
        && str_contains($appointment, "['end_time' => ['>', \$begin]]")
        && str_contains($appointment, "'is_deleted' => 0")
        && str_contains($appointment, "['NOT' => ['id' => \$ignoreId]]"),
    'conflito identifica registro com proteção de acesso' => str_contains($appointment, 'overlapErrorMessage')
        && str_contains($appointment, 'canExposeConflictDetails')
        && str_contains($appointment, 'canReadRelatedItem')
        && str_contains($appointment, 'Já existe o apontamento #%1$d')
        && str_contains($appointment, 'Já existe outro apontamento deste usuário'),
    'sem status funcional ou cancelamento' => !str_contains($appointment, "showFromArray('status'")
        && !str_contains($files['front/appointment.form.php'], "isset(\$_POST['cancel'])")
        && !str_contains($files['front/appointment.form.php'], "isset(\$_POST['finish'])"),
    'mesmo objeto aceita vários apontamentos' => !preg_match('/(?:vínculo|chamado|projeto).{0,80}(?:duplicado|já vinculado|já possui)/iu', $appointment),
    'valores preservados após erro' => str_contains($files['front/appointment.form.php'], "\$formOptions['form_input'] = \$_POST")
        && str_contains($appointment, 'applyFormInput($formInput)'),
    'cadastro de tipos exige configuração' => str_contains($configForm, 'Session::checkRight(PluginApontamentosConfig::$rightname, UPDATE)'),
    'CRUD de tipos é POST e CSRF' => substr_count($configForm, "<form method='post'") >= 4
        && substr_count($configForm, 'Html::closeForm()') >= 4,
    'CRUD valida nome e cor no servidor' => str_contains($configForm, 'normalizeName')
        && str_contains($configForm, 'validColor'),
    'tipo pode ativar e desativar' => str_contains($configForm, "isset(\$_POST['set_type_active'])")
        && str_contains($configForm, "'is_active' => \$active"),
    'exclusão de tipo é lógica' => str_contains($configForm, "isset(\$_POST['delete_type'])")
        && str_contains($configForm, "'is_deleted' => 1") && str_contains($configForm, "'is_active' => 0"),
    'cor por entidade removida da interface' => !str_contains($configForm, 'Cor atual por entidade')
        && !str_contains($configForm, 'color_entity_id') && !str_contains($config, 'colorForEntity'),
    'tabela histórica de entidade preservada' => str_contains($hook, 'glpi_plugin_apontamentos_entitysettings')
        && !preg_match('/DROP\s+TABLE/i', $hook),
    'cartão recebe cor do tipo' => str_contains($events, 'PluginApontamentosAppointmentType::colorFor')
        && str_contains($events, "'appointment_type' => \$appointmentTypeName")
        && str_contains($calendar, 'event.appointment_type'),
    'cartão não usa entidade como tipo' => !str_contains($calendar, 'event.entity')
        && !str_contains($calendar, 'ap-event-entity'),
    'cartão mantém horário vínculo conteúdo projeto e tarefa' => str_contains($calendar, 'event.reference')
        && str_contains($calendar, 'event.content') && str_contains($calendar, 'event.project')
        && str_contains($calendar, 'event.project_task'),
    'cartão possui editar e excluir' => str_contains($calendar, 'ti-pencil')
        && str_contains($calendar, 'ti-trash'),
    'cartão curto permanece compacto' => str_contains($calendar, "duration < 60")
        && str_contains($files['css/calendar.css'], '.ap-event.is-short'),
    'jornada independente da cor do tipo' => str_contains($config, 'dailyTargetState')
        && !str_contains($config, 'appointmenttypes_id'),
    'dia vazio neutro e meta vermelha/verde' => str_contains($config, 'if ($actualMinutes <= 0)')
        && str_contains($config, "return 'short'") && str_contains($config, "? 'exceeded' : 'met'")
        && str_contains($files['css/calendar.css'], '#2fb344') && str_contains($files['css/calendar.css'], '#d63939'),
    'relatório filtra tipo próprio' => str_contains($report, "'appointmenttypes_id'")
        && str_contains($report, 'PluginApontamentosAppointmentType::reportOptions()'),
    'exportação valida tipo solicitado' => str_contains($files['inc/report.class.php'], '$appointmentTypeId = self::nonNegativeInt')
        && str_contains($files['inc/report.class.php'], 'PluginApontamentosAppointmentType::getRecord'),
    'CSV separa tipo entidade e usuário' => str_contains($export, "'Tipo de apontamento', 'Entidade', 'Usuário'")
        && str_contains($export, 'PluginApontamentosAppointmentType::displayName'),
    'CSV possui vínculos projeto tarefa e conteúdo' => str_contains($export, "'Tipo do vínculo', 'ID vinculado', 'Projeto'")
        && str_contains($export, "'Tarefa', 'Conteúdo'"),
    'CSV é seguro e limitado' => str_contains($export, "'/^[=+\\-@]/u'")
        && str_contains($files['inc/report.class.php'], 'MAX_PERIOD_DAYS') && str_contains($export, 'xEF\\xBB\\xBF'),
    'relatório exclui removidos e respeita entidade' => str_contains($files['inc/report.class.php'], "'is_deleted' => 0")
        && str_contains($files['inc/report.class.php'], '$activeEntities'),
    'abas mostram o tipo próprio' => str_contains($itemTab, 'PluginApontamentosAppointmentType::displayName')
        && str_contains($itemTab, "__('Tipo de apontamento', 'apontamentos')"),
    'abas não mostram usuário ou conteúdo' => !str_contains($itemTab, 'getUserName(')
        && !str_contains($itemTab, "\$row['content']"),
    'abas preservam tipo de vínculo' => str_contains($itemTab, "__('Tipo de vínculo', 'apontamentos')")
        && str_contains($itemTab, "'Project' => __('Projeto', 'apontamentos')"),
    'abas respeitam entidades e outros usuários' => str_contains($itemTab, "'entities_id' => \$entities")
        && str_contains($itemTab, 'canManageOthers()'),
    'exclusão do calendário usa POST CSRF e permissão' => str_contains($calendar, "method: 'POST'")
        && str_contains($calendar, '_glpi_csrf_token')
        && str_contains($files['ajax/delete.php'], 'Session::validateCSRF($_POST, true)')
        && str_contains($files['ajax/delete.php'], 'canDeleteStoredFields($row)'),
    'jornada por usuário é preservada' => str_contains($hook, 'glpi_plugin_apontamentos_userschedules')
        && str_contains($hook, 'Migração não destrutiva') && str_contains($hook, 'UNIQUE KEY `user`'),
    'direitos existentes são usados' => str_contains($source, 'plugin_apontamentos_config')
        && str_contains($source, 'plugin_apontamentos_export')
        && str_contains($source, 'plugin_apontamentos_project')
        && str_contains($source, 'MANAGE_OTHERS'),
    'painel gerencial centraliza cálculo e segurança' => str_contains($files['inc/report.class.php'], 'filtersFromRequest')
        && str_contains($files['inc/report.class.php'], 'calculateDay')
        && str_contains($files['inc/report.class.php'], 'unionMinutes'),
    'PDF gerado pelo plugin sem internet' => str_contains($files['ajax/report.pdf.php'], 'application/pdf')
        && str_contains($files['inc/reportpdf.class.php'], '%PDF-1.4')
        && !preg_match('/https?:\/\//i', $files['inc/reportpdf.class.php']),
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        $errors[] = "Falhou: $label";
    }
}
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
echo 'OK - ' . count($checks) . " verificações estáticas somente leitura concluídas.\n";
