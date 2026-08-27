<?php

class PluginApontamentosConfig extends CommonGLPI
{
    public static $rightname = 'plugin_apontamentos_config';
    public const DEFAULT_COLOR = '#206BC4';
    public const DAYS = [
        1 => 'monday_minutes', 2 => 'tuesday_minutes', 3 => 'wednesday_minutes',
        4 => 'thursday_minutes', 5 => 'friday_minutes', 6 => 'saturday_minutes', 7 => 'sunday_minutes',
    ];

    public function getRights($interface = 'central'): array
    {
        return [UPDATE => __('Configurar', 'apontamentos')];
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Configurar tipos e jornadas', 'apontamentos');
    }

    public static function validColor($value): ?string
    {
        $value = strtoupper(trim((string) $value));
        return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : null;
    }

    public static function normalizeMinutes($value): ?int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1440]]);
        return $value === false ? null : (int) $value;
    }

    public static function scheduleFor(int $userId, DateTimeImmutable $date): ?int
    {
        global $DB;
        $table = 'glpi_plugin_apontamentos_userschedules';
        if ($userId <= 0 || !$DB->tableExists($table)) {
            return null;
        }
        $column = self::DAYS[(int) $date->format('N')];
        $schedule = $DB->request([
            'SELECT' => [$column], 'FROM' => $table,
            'WHERE' => ['users_id' => $userId], 'LIMIT' => 1,
        ])->current();
        return $schedule ? (int) $schedule[$column] : null;
    }

    public static function scheduleForUser(int $userId): ?array
    {
        global $DB;
        $table = 'glpi_plugin_apontamentos_userschedules';
        if ($userId <= 0 || !$DB->tableExists($table)) {
            return null;
        }
        $row = $DB->request([
            'SELECT' => array_merge(['id', 'users_id'], array_values(self::DAYS)),
            'FROM' => $table,
            'WHERE' => ['users_id' => $userId],
            'LIMIT' => 1,
        ])->current();
        return $row ? (array) $row : null;
    }

    public static function defaultSchedule(): array
    {
        return [
            'monday_minutes' => 480,
            'tuesday_minutes' => 480,
            'wednesday_minutes' => 480,
            'thursday_minutes' => 480,
            'friday_minutes' => 480,
            'saturday_minutes' => 0,
            'sunday_minutes' => 0,
        ];
    }

    public static function scheduleInput(array $input): ?array
    {
        $result = [];
        foreach (self::DAYS as $column) {
            $minutes = self::normalizeMinutes($input[$column] ?? null);
            if ($minutes === null) {
                return null;
            }
            $result[$column] = $minutes;
        }
        return $result;
    }

    public static function dailyTargetState(
        ?int $expectedMinutes,
        int $actualMinutes,
        DateTimeImmutable $date,
        ?DateTimeImmutable $today = null
    ): string {
        // A cor representa o resultado de horas efetivamente apontadas, não
        // uma cobrança automática por calendário. Sem apontamento, qualquer
        // data permanece neutra; havendo horas, passado, hoje e futuro usam a
        // mesma comparação com a jornada configurada do dia da semana.
        if ($actualMinutes <= 0) {
            return 'empty';
        }
        if ($expectedMinutes === null) {
            return 'unconfigured';
        }
        if ($expectedMinutes === 0) {
            return 'off';
        }
        if ($actualMinutes < $expectedMinutes) {
            return 'short';
        }
        return $actualMinutes > $expectedMinutes ? 'exceeded' : 'met';
    }
}
