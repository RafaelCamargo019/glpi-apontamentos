<?php

if (!class_exists('CommonGLPI')) {
    class CommonGLPI {}
}
if (!function_exists('__')) {
    function __($message, $domain = null) { return $message; }
}

require_once dirname(__DIR__) . '/inc/report.class.php';

$root = dirname(__DIR__);
$reportSource = (string) file_get_contents($root . '/inc/report.class.php');
$frontSource = (string) file_get_contents($root . '/front/report.php');
$exportSource = (string) file_get_contents($root . '/ajax/export.php');
$pdfSource = (string) file_get_contents($root . '/inc/reportpdf.class.php');
$pdfEndpoint = (string) file_get_contents($root . '/ajax/report.pdf.php');
$errors = [];
$checks = [];
$check = static function (string $label, bool $condition) use (&$errors, &$checks): void {
    $checks[] = $label;
    if (!$condition) $errors[] = 'Falhou: ' . $label;
};

$exact = PluginApontamentosReport::calculateDay(480, 480);
$short = PluginApontamentosReport::calculateDay(480, 360);
$extra = PluginApontamentosReport::calculateDay(480, 600);
$zero = PluginApontamentosReport::calculateDay(0, 0);
$unconfigured = PluginApontamentosReport::calculateDay(null, 120);
$empty = PluginApontamentosReport::calculateDay(480, 0);
$consecutiveOverlaps = [];
$consecutive = PluginApontamentosReport::unionMinutes([[0, 3600, 1], [3600, 7200, 2]], $consecutiveOverlaps);
$overlaps = [];
$uniqueOverlap = PluginApontamentosReport::unionMinutes([[0, 3600, 1], [1800, 5400, 2]], $overlaps);

$check('8h de 8h resulta em 100%', $exact === ['missing'=>0,'extra'=>0,'occupation'=>100.0,'state'=>'met']);
$check('6h de 8h resulta em 2h e 75%', $short === ['missing'=>120,'extra'=>0,'occupation'=>75.0,'state'=>'short']);
$check('10h de 8h resulta em 2h excedentes e 125%', $extra === ['missing'=>0,'extra'=>120,'occupation'=>125.0,'state'=>'exceeded']);
$check('jornada zero não gera ociosidade', $zero['missing'] === 0 && $zero['occupation'] === null);
$check('jornada não configurada é distinguida', $unconfigured['state'] === 'unconfigured' && $unconfigured['missing'] === null);
$check('dia sem apontamento gera toda jornada não apontada', $empty['missing'] === 480 && $empty['occupation'] === 0.0);
$check('registros excluídos são filtrados', str_contains($reportSource, "'is_deleted' => 0"));
$check('intervalos consecutivos somam corretamente', $consecutive === 120 && $consecutiveOverlaps === []);
$check('sobreposição histórica não duplica minutos', $uniqueOverlap === 90 && isset($overlaps[1], $overlaps[2]));
$check('usuário comum é fixado no login', str_contains($reportSource, ': Session::getLoginUserID()'));
$check('gestor passa por autorização de entidades', str_contains($reportSource, 'userAllowedInEntities'));
$check('todos os filtros funcionais são validados', str_contains($reportSource, "'linked_' . \$itemtype")
    && str_contains($reportSource, "\$projectSelected = \$itemtype === 'Project'")
    && str_contains($reportSource, 'projecttasks_id'));
$check('entidade não é exibida nem aceita como filtro', !str_contains($frontSource, "'entities_id' => \$filters['entityId']")
    && !str_contains($frontSource, "'name' => 'entities_id'")
    && !str_contains($reportSource, "\$request['entities_id']")
    && str_contains($reportSource, '$entityId = 0'));
$check('terminologia de operador foi substituída por usuário', !str_contains($frontSource, 'Operador')
    && !str_contains($reportSource, "__('Operador'")
    && !str_contains($exportSource, "'Operador'")
    && !str_contains($pdfSource, 'Operador'));
$check('projeto integra o tipo de vínculo no formulário', str_contains($frontSource, "\$linkTypeOptions['Project'] = __('Projeto', 'apontamentos')")
    && str_contains($frontSource, 'ap-report-project-field')
    && str_contains($frontSource, "const isProject = type.value === 'Project'"));
$linkRowBreak = strpos($frontSource, 'ap-report-link-row-break');
$linkTypeField = strpos($frontSource, "__('Tipo de vínculo', 'apontamentos')");
$linkedField = strpos($frontSource, "__('Registro vinculado', 'apontamentos')");
$check('tipo e registro vinculado iniciam juntos a segunda linha', $linkRowBreak !== false
    && $linkTypeField !== false && $linkedField !== false
    && $linkRowBreak < $linkTypeField && $linkTypeField < $linkedField);
$check('servidor filtra somente projetos quando vínculo Projeto está ativo', str_contains($reportSource, "if (\$filters['itemtype'] === 'Project')")
    && str_contains($reportSource, "\$where['projects_id'] = ['>', 0]")
    && str_contains($reportSource, "if (\$projectSelected && !PluginApontamentosAppointment::canUseProjects())"));
$check('dia semana mês e personalizado são aceitos', str_contains($reportSource, "['day', 'week', 'month', 'custom']"));
$check('distribuição usa minutos sem duplicidade', str_contains($reportSource, 'creditedMinutes($records)'));
$check('gráficos usam somente resultado autorizado', str_contains($frontSource, "\$report['types']") && str_contains($frontSource, "\$report['summary']"));
$check('CSV detalhado permanece disponível', str_contains($exportSource, "'detailed'"));
$check('CSV gerencial usa os cálculos centralizados', str_contains($exportSource, "'summary'") && str_contains($exportSource, "\$report['summary']"));
$check('geração não escreve tabelas', !preg_match('/\$DB->(?:insert|update|delete)|INSERT\s+INTO|UPDATE\s+`|DELETE\s+FROM/i', $reportSource . $frontSource . $exportSource . $pdfSource . $pdfEndpoint));
$check('período excessivo é limitado', str_contains($reportSource, 'MAX_PERIOD_DAYS = 93') && str_contains($reportSource, '$days > self::MAX_PERIOD_DAYS'));
$check('conteúdo dinâmico é escapado e CSV é protegido', str_contains($frontSource, 'htmlescape((string) $row[\'content\'])') && str_contains($exportSource, "'/^[=+\\-@]/u'"));
$check('PDF é local seguro e abre inline', str_contains($pdfEndpoint, "Content-Type: application/pdf") && str_contains($pdfEndpoint, 'Content-Disposition: inline') && !preg_match('/https?:\/\//i', $pdfSource));

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
echo 'OK - ' . count($checks) . " verificações gerenciais somente leitura concluídas.\n";
