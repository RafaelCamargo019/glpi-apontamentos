<?php

define('PLUGIN_APONTAMENTOS_VERSION', '2.9.0');
define('PLUGIN_APONTAMENTOS_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_APONTAMENTOS_MAX_GLPI_VERSION', '11.99.99');

function plugin_init_apontamentos(): void
{
    global $PLUGIN_HOOKS;

    if (Session::getLoginUserID()) {
        $menuSchema = 'helpdesk-v8';
        if (($_SESSION['plugin']['apontamentos']['menu_schema'] ?? '') !== $menuSchema) {
            unset($_SESSION['glpimenu']);
            $_SESSION['plugin']['apontamentos']['menu_schema'] = $menuSchema;
        }
    }

    $PLUGIN_HOOKS['csrf_compliant']['apontamentos'] = true;
    $PLUGIN_HOOKS['change_profile']['apontamentos'] = 'plugin_apontamentos_change_profile';

    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if (str_contains($requestUri, '/plugins/apontamentos/front/appointment.php')) {
        // Publish through plugin PHP routes because some GLPI 11 web-server
        // layouts intentionally keep plugin static directories outside webroot.
        $PLUGIN_HOOKS['add_css']['apontamentos'][] = 'front/calendar.css.php';
        $PLUGIN_HOOKS['add_javascript']['apontamentos'][] = 'front/calendar.js.php';
    }
    if (preg_match('~/front/(ticket|problem|change|project)\.form\.php(?:\?|$)~i', $requestUri) === 1) {
        // As abas são carregadas por AJAX, portanto os recursos do modal
        // precisam estar presentes desde a página principal do registro.
        $PLUGIN_HOOKS['add_css']['apontamentos'][] = 'front/calendar.css.php';
        $PLUGIN_HOOKS['add_javascript']['apontamentos'][] = 'front/itemtab.js.php';
    }

    Plugin::registerClass('PluginApontamentosAppointment');
    Plugin::registerClass('PluginApontamentosAppointmentModal');
    Plugin::registerClass('PluginApontamentosAppointmentType');
    Plugin::registerClass('PluginApontamentosItemTab', [
        'addtabon' => ['Ticket', 'Problem', 'Change', 'Project'],
    ]);
    Plugin::registerClass('PluginApontamentosProfile', ['addtabon' => 'Profile']);
    Plugin::registerClass('PluginApontamentosConfig');
    Plugin::registerClass('PluginApontamentosReport');
    Plugin::registerClass('PluginApontamentosProjectRight');

    if (Session::getLoginUserID() && PluginApontamentosAppointment::canView()) {
        $PLUGIN_HOOKS['menu_toadd']['apontamentos'] = [
            'helpdesk' => 'PluginApontamentosAppointment',
        ];
    }
}

function plugin_version_apontamentos(): array
{
    return [
        'name'         => 'Apontamentos',
        'version'      => PLUGIN_APONTAMENTOS_VERSION,
        'author'       => 'Rafael - Mkdata',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_APONTAMENTOS_MIN_GLPI_VERSION,
                'max' => PLUGIN_APONTAMENTOS_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

function plugin_apontamentos_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_APONTAMENTOS_MIN_GLPI_VERSION, '>=')
        && version_compare(GLPI_VERSION, PLUGIN_APONTAMENTOS_MAX_GLPI_VERSION, '<=');
}

function plugin_apontamentos_check_config(): bool
{
    return true;
}
