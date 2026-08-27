<?php

class PluginApontamentosProjectRight extends CommonGLPI
{
    public static $rightname = 'plugin_apontamentos_project';

    public function getRights($interface = 'central'): array
    {
        return [READ => __('Vincular', 'apontamentos')];
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Vincular apontamentos a projetos', 'apontamentos');
    }
}
