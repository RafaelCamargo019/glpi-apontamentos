<?php

class PluginApontamentosAppointmentType extends CommonDBTM
{
    public static $rightname = 'plugin_apontamentos_config';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_apontamentos_types';
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Tipo de apontamento', 'Tipos de apontamento', $nb, 'apontamentos');
    }

    public static function normalizeName($value): ?string
    {
        $name = trim((string) $value);
        return $name !== '' && mb_strlen($name) <= 255 ? $name : null;
    }

    public static function getRecord(int $id): ?array
    {
        global $DB;
        if ($id <= 0 || !$DB->tableExists(self::getTable())) {
            return null;
        }
        $row = $DB->request([
            'FROM' => self::getTable(),
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
        ])->current();
        return $row ? (array) $row : null;
    }

    public static function activeOptions(int $includeId = 0): array
    {
        global $DB;
        if (!$DB->tableExists(self::getTable())) {
            return [];
        }
        $options = [];
        foreach ($DB->request([
            'FROM' => self::getTable(),
            'ORDER' => ['name ASC', 'id ASC'],
        ]) as $row) {
            $id = (int) $row['id'];
            if ((!empty($row['is_active']) && empty($row['is_deleted'])) || $id === $includeId) {
                $suffix = !empty($row['is_deleted'])
                    ? ' (' . __('excluído', 'apontamentos') . ')'
                    : (empty($row['is_active']) ? ' (' . __('inativo', 'apontamentos') . ')' : '');
                $options[$id] = (string) $row['name'] . $suffix;
            }
        }
        return $options;
    }

    public static function reportOptions(): array
    {
        global $DB;
        if (!$DB->tableExists(self::getTable())) {
            return [];
        }
        $options = [];
        foreach ($DB->request([
            'FROM' => self::getTable(),
            'WHERE' => ['is_deleted' => 0],
            'ORDER' => ['name ASC', 'id ASC'],
        ]) as $row) {
            $options[(int) $row['id']] = (string) $row['name'];
        }
        return $options;
    }

    public static function displayName(int $id): string
    {
        $row = self::getRecord($id);
        return $row && trim((string) $row['name']) !== ''
            ? (string) $row['name']
            : sprintf(__('Tipo #%d', 'apontamentos'), $id);
    }

    public static function colorFor(int $id): string
    {
        $row = self::getRecord($id);
        return PluginApontamentosConfig::validColor($row['color'] ?? '')
            ?? PluginApontamentosConfig::DEFAULT_COLOR;
    }
}
