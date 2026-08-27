<?php

$root = dirname(__DIR__);
$appointment = (string) file_get_contents($root . '/inc/appointment.class.php');
$errors = [];
$checks = [];
$check = static function (string $label, bool $condition) use (&$errors, &$checks): void {
    $checks[] = $label;
    if (!$condition) {
        $errors[] = 'Falhou: ' . $label;
    }
};

preg_match('/<style>(.*?)<\/style>/s', $appointment, $styleMatch);
$style = (string) ($styleMatch[1] ?? '');

$check(
    'formulário possui um único contêiner exclusivo',
    substr_count($appointment, "class='plugin-apontamentos-form'") === 1
        && str_contains($appointment, "<td colspan='4'><div class='plugin-apontamentos-form'>")
);
$check(
    'linhas visuais não dependem das colunas da tabela',
    str_contains($appointment, "class='ap-layout-row ap-link-row'")
        && str_contains($appointment, "class='ap-layout-row ap-main-row'")
        && str_contains($appointment, "class='ap-layout-row ap-content-row'")
);
$check(
    'tipo, data, início e fim compartilham a primeira linha',
    preg_match(
        "/ap-layout-row ap-main-row.*appointmenttypes_id.*appointment_date.*begin_time_hour.*end_time_hour/s",
        $appointment
    ) === 1
);
$check(
    'rótulo e controle usam distância compacta',
    str_contains($style, '.ap-field-group{display:inline-flex;align-items:center;justify-content:flex-start;gap:8px')
);
$check(
    'linhas e controles possuem altura uniforme',
    str_contains($style, 'min-height:40px')
        && str_contains($style, '.select2-selection--single{display:flex;align-items:center;min-height:40px;height:40px}')
        && str_contains($style, '.form-control:not(textarea){height:40px;min-height:40px}')
);
$check(
    'larguras são proporcionais ao conteúdo',
    str_contains($style, '.ap-type-control{width:270px}')
        && str_contains($style, '.ap-date-control{width:170px}')
        && str_contains($style, '.ap-time-control{width:90px}')
        && str_contains($style, '.ap-link-type-control{width:180px}')
        && str_contains($style, '.ap-linked-control{width:320px}')
        && str_contains($style, '.ap-project-control{width:270px}')
        && str_contains($style, '.ap-task-control{width:320px}')
);
$check(
    'CSS de componentes GLPI está limitado ao formulário',
    !str_contains($style, "\n.asset")
        && !str_contains($style, "\n.tab_bg_1")
        && !str_contains($style, "\n.select2-container")
        && !str_contains($style, "\n.form-control")
        && str_contains($style, '.plugin-apontamentos-form .select2-selection--single')
);
$check(
    'campos vinculados ocultos não reservam espaço',
    str_contains($style, '.plugin-apontamentos-form .ap-linked-picker{display:none;width:100%}')
        && str_contains($appointment, "el.style.display = !projectSelected && el.dataset.linkType === type.value ? 'block' : 'none'")
        && str_contains($appointment, "el.style.display = projectSelected ? 'inline-flex' : 'none'")
);
$check(
    'JavaScript atua somente dentro do formulário',
    str_contains($appointment, "const form = document.querySelector('.plugin-apontamentos-form')")
        && str_contains($appointment, "form.querySelector('[name=\"link_type\"]')")
        && str_contains($appointment, "form.querySelectorAll('.ap-linked-picker')")
);
$check(
    'conteúdo ocupa o espaço restante sem afastar o rótulo',
    str_contains($style, '.ap-content-group{display:flex;width:100%;align-items:flex-start}')
        && str_contains($style, '.ap-content-group textarea{flex:1 1 auto;width:auto;min-width:0}')
);
$check(
    'layout móvel empilha grupos sem cortar controles',
    str_contains($style, '@media(max-width:768px)')
        && str_contains($style, '.ap-field-group{display:flex;align-items:flex-start;flex-direction:column;gap:4px;width:100%}')
        && str_contains($style, 'width:min(100%,320px)')
);
$check(
    'teste não altera banco nem esquema',
    !preg_match('/\$DB->(?:insert|update|delete)|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+TABLE/i', (string) file_get_contents(__FILE__))
);

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo 'OK - ' . count($checks) . " verificações do alinhamento do formulário concluídas.\n";
