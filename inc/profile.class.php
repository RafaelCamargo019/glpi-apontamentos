<?php

class PluginApontamentosProfile extends Profile
{
    public static $rightname = 'profile';

    public static function getTypeName($nb = 0): string
    {
        return __('Apontamentos', 'apontamentos');
    }

    public static function getAllRights(): array
    {
        return [
            ['itemtype' => 'PluginApontamentosAppointment', 'label' => __('Apontamentos', 'apontamentos'), 'field' => PluginApontamentosAppointment::$rightname],
            ['itemtype' => 'PluginApontamentosReport', 'label' => __('Exportar relatórios de apontamentos', 'apontamentos'), 'field' => PluginApontamentosReport::$rightname],
            ['itemtype' => 'PluginApontamentosConfig', 'label' => __('Configurar cores e jornadas', 'apontamentos'), 'field' => PluginApontamentosConfig::$rightname],
            ['itemtype' => 'PluginApontamentosProjectRight', 'label' => __('Vincular apontamentos a projetos', 'apontamentos'), 'field' => PluginApontamentosProjectRight::$rightname],
        ];
    }

    public static function changeProfile(): void
    {
        foreach (self::getAllRights() as $right) {
            $_SESSION['glpiactiveprofile'][$right['field']] = $_SESSION['glpiactiveprofile'][$right['field']] ?? 0;
        }
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        return $item instanceof Profile && $item->getID()
            ? self::createTabEntry(self::getTypeName())
            : '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Profile || !$item->getID()) {
            return false;
        }
        $canEdit = Session::haveRight('profile', UPDATE);
        if ($canEdit) {
            echo "<form method='post' action='" . htmlescape(Profile::getFormURL()) . "'>";
        }
        $item->displayRightsChoiceMatrix(
            self::getAllRights(),
            ['canedit' => $canEdit, 'title' => self::getTypeName()]
        );
        if ($canEdit) {
            echo Html::hidden('id', ['value' => $item->getID()]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            Html::closeForm();
        }
        return true;
    }
}
