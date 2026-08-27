<?php

function plugin_apontamentos_install(): bool
{
    global $DB;

    $table = 'glpi_plugin_apontamentos_appointments';
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL DEFAULT '0',
            `entities_id` int unsigned NOT NULL DEFAULT '0',
            `appointmenttypes_id` int unsigned NOT NULL DEFAULT '0',
            `begin_time` timestamp NULL DEFAULT NULL,
            `end_time` timestamp NULL DEFAULT NULL,
            `content` text COLLATE utf8mb4_unicode_ci,
            `status` tinyint NOT NULL DEFAULT '1',
            `itemtype` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
            `items_id` int unsigned NOT NULL DEFAULT '0',
            `projects_id` int unsigned NOT NULL DEFAULT '0',
            `projecttasks_id` int unsigned NOT NULL DEFAULT '0',
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            `is_deleted` tinyint NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            KEY `users_id` (`users_id`), KEY `entities_id` (`entities_id`),
            KEY `appointmenttypes_id` (`appointmenttypes_id`),
            KEY `begin_time` (`begin_time`), KEY `end_time` (`end_time`),
            KEY `status` (`status`), KEY `item` (`itemtype`,`items_id`),
            KEY `projects_id` (`projects_id`), KEY `projecttasks_id` (`projecttasks_id`),
            KEY `is_deleted` (`is_deleted`), KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC";
        if (!$DB->doQuery($query)) {
            return false;
        }
    }

    $typesTable = 'glpi_plugin_apontamentos_types';
    if (!$DB->tableExists($typesTable)) {
        if (!$DB->doQuery("CREATE TABLE `$typesTable` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
            `color` char(7) NOT NULL DEFAULT '#206BC4',
            `is_active` tinyint NOT NULL DEFAULT '1',
            `is_deleted` tinyint NOT NULL DEFAULT '0',
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`), KEY `is_active` (`is_active`),
            KEY `is_deleted` (`is_deleted`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC")) {
            return false;
        }
    }

    $migrationsTable = 'glpi_plugin_apontamentos_migrations';
    if (!$DB->tableExists($migrationsTable)) {
        if (!$DB->doQuery("CREATE TABLE `$migrationsTable` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC")) {
            return false;
        }
    }

    $schemaMigration = new Migration('2.8.0');
    $schemaMigration->addField($table, 'appointmenttypes_id', 'integer', [
        'value' => 0,
        'after' => 'entities_id',
    ]);
    $schemaMigration->addKey($table, 'appointmenttypes_id');
    $schemaMigration->executeMigration();
    if (!$DB->fieldExists($table, 'appointmenttypes_id', false)) {
        return false;
    }

    if (count($DB->request(['FROM' => $typesTable])) === 0) {
        $now = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        if (!$DB->insert($typesTable, [
            'name' => 'Geral',
            'color' => '#206BC4',
            'is_active' => 1,
            'is_deleted' => 0,
            'date_creation' => $now,
            'date_mod' => $now,
        ])) {
            return false;
        }
    }

    // Cancelamento deixou de existir na versão 2.3. Registros legados são
    // preservados no banco, mas passam a seguir a mesma exclusão lógica.
    $DB->update($table, ['is_deleted' => 1], ['status' => PluginApontamentosAppointment::LEGACY_STATUS_CANCELLED]);
    // A coluna legada continua preenchida apenas porque é NOT NULL.
    $DB->update($table, ['status' => PluginApontamentosAppointment::DB_STATUS_ACTIVE], ['is_deleted' => 0]);

    $entitySettings = 'glpi_plugin_apontamentos_entitysettings';
    if (!$DB->tableExists($entitySettings)) {
        if (!$DB->doQuery("CREATE TABLE `$entitySettings` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `entities_id` int unsigned NOT NULL,
            `color` char(7) NOT NULL DEFAULT '#206BC4',
            `effective_date` date NOT NULL,
            `monday_minutes` smallint unsigned NOT NULL DEFAULT 480,
            `tuesday_minutes` smallint unsigned NOT NULL DEFAULT 480,
            `wednesday_minutes` smallint unsigned NOT NULL DEFAULT 480,
            `thursday_minutes` smallint unsigned NOT NULL DEFAULT 480,
            `friday_minutes` smallint unsigned NOT NULL DEFAULT 480,
            `saturday_minutes` smallint unsigned NOT NULL DEFAULT 0,
            `sunday_minutes` smallint unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `entity_date` (`entities_id`,`effective_date`),
            KEY `entity` (`entities_id`), KEY `effective_date` (`effective_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC")) {
            return false;
        }
    }

    $exceptions = 'glpi_plugin_apontamentos_scheduleexceptions';
    if (!$DB->tableExists($exceptions)) {
        if (!$DB->doQuery("CREATE TABLE `$exceptions` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL,
            `entities_id` int unsigned NOT NULL,
            `effective_date` date NOT NULL,
            `monday_minutes` smallint unsigned NOT NULL DEFAULT 480,
            `tuesday_minutes` smallint unsigned NOT NULL DEFAULT 480,
            `wednesday_minutes` smallint unsigned NOT NULL DEFAULT 480,
            `thursday_minutes` smallint unsigned NOT NULL DEFAULT 480,
            `friday_minutes` smallint unsigned NOT NULL DEFAULT 480,
            `saturday_minutes` smallint unsigned NOT NULL DEFAULT 0,
            `sunday_minutes` smallint unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `user_entity_date` (`users_id`,`entities_id`,`effective_date`),
            KEY `entity_user` (`entities_id`,`users_id`), KEY `effective_date` (`effective_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC")) {
            return false;
        }
    }

    $userSchedules = 'glpi_plugin_apontamentos_userschedules';
    if (!$DB->tableExists($userSchedules)) {
        if (!$DB->doQuery("CREATE TABLE `$userSchedules` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL,
            `monday_minutes` smallint unsigned NOT NULL DEFAULT 480,
            `tuesday_minutes` smallint unsigned NOT NULL DEFAULT 480,
            `wednesday_minutes` smallint unsigned NOT NULL DEFAULT 480,
            `thursday_minutes` smallint unsigned NOT NULL DEFAULT 480,
            `friday_minutes` smallint unsigned NOT NULL DEFAULT 480,
            `saturday_minutes` smallint unsigned NOT NULL DEFAULT 0,
            `sunday_minutes` smallint unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `user` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC")) {
            return false;
        }
    }

    // Migração não destrutiva: cada usuário recebe a configuração histórica
    // mais recente apenas quando ainda não possui a nova jornada global.
    $migratedUsers = [];
    foreach ($DB->request([
        'FROM' => $exceptions,
        'ORDER' => ['users_id ASC', 'effective_date DESC', 'date_mod DESC', 'id DESC'],
    ]) as $legacySchedule) {
        $userId = (int) $legacySchedule['users_id'];
        if ($userId <= 0 || isset($migratedUsers[$userId])) {
            continue;
        }
        $migratedUsers[$userId] = true;
        $existingSchedule = $DB->request([
            'SELECT' => ['id'],
            'FROM' => $userSchedules,
            'WHERE' => ['users_id' => $userId],
            'LIMIT' => 1,
        ]);
        if (count($existingSchedule) > 0) {
            continue;
        }
        $values = ['users_id' => $userId];
        foreach (PluginApontamentosConfig::DAYS as $column) {
            $values[$column] = (int) $legacySchedule[$column];
        }
        $now = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $values['date_creation'] = $legacySchedule['date_creation'] ?: $now;
        $values['date_mod'] = $legacySchedule['date_mod'] ?: $now;
        $DB->insert($userSchedules, $values);
    }

    // Migração não destrutiva: registros legados sem tipo recebem um tipo
    // existente. Nenhum apontamento é apagado durante instalação ou atualização.
    $defaultType = $DB->request([
        'SELECT' => ['id'],
        'FROM' => $typesTable,
        'WHERE' => ['is_deleted' => 0],
        'ORDER' => ['is_active DESC', 'id ASC'],
        'LIMIT' => 1,
    ]);
    if (count($defaultType) === 0) {
        $now = (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        if (!$DB->insert($typesTable, [
            'name' => 'Geral',
            'color' => '#206BC4',
            'is_active' => 1,
            'is_deleted' => 0,
            'date_creation' => $now,
            'date_mod' => $now,
        ])) {
            return false;
        }
        $defaultTypeId = (int) $DB->insertId();
    } else {
        $defaultTypeId = (int) $defaultType->current()['id'];
    }
    if ($defaultTypeId <= 0) {
        return false;
    }

    $typeMigrationMarker = 'appointments_type_migration_2_9_0';
    $typeMigrationDone = count($DB->request([
        'SELECT' => ['id'],
        'FROM' => $migrationsTable,
        'WHERE' => ['name' => $typeMigrationMarker],
        'LIMIT' => 1,
    ])) > 0;
    if (!$typeMigrationDone) {
        $DB->beginTransaction();
        try {
            if (!$DB->update($table, [
                'appointmenttypes_id' => $defaultTypeId,
            ], [
                'appointmenttypes_id' => 0,
            ])) {
                throw new RuntimeException('Não foi possível associar um tipo aos apontamentos legados.');
            }
            if (!$DB->insert($migrationsTable, [
                'name' => $typeMigrationMarker,
                'date_creation' => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
            ])) {
                throw new RuntimeException('Não foi possível registrar a migração de tipos.');
            }
            $DB->commit();
        } catch (Throwable $error) {
            $DB->rollBack();
            trigger_error($error->getMessage(), E_USER_WARNING);
            return false;
        }
    }

    foreach (PluginApontamentosProfile::getAllRights() as $right) {
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_profiles']) as $profile) {
            $exists = $DB->request([
                'FROM' => 'glpi_profilerights',
                'WHERE' => [
                    'profiles_id' => (int) $profile['id'],
                    'name' => $right['field'],
                ],
                'LIMIT' => 1,
            ]);
            if (count($exists) === 0) {
                $DB->insert('glpi_profilerights', [
                    'profiles_id' => (int) $profile['id'],
                    'name' => $right['field'],
                    'rights' => 0,
                ]);
            }
        }
    }
    $migration = new Migration(PLUGIN_APONTAMENTOS_VERSION);
    $migration->replaceRight(
        PluginApontamentosAppointment::$rightname,
        ALLSTANDARDRIGHT | PluginApontamentosAppointment::MANAGE_OTHERS,
        [Config::$rightname => UPDATE]
    );
    $migration->replaceRight(PluginApontamentosConfig::$rightname, UPDATE, [Config::$rightname => UPDATE]);
    $migration->replaceRight(PluginApontamentosReport::$rightname, READ, [Config::$rightname => UPDATE]);
    $migration->replaceRight(PluginApontamentosProjectRight::$rightname, READ, [Config::$rightname => UPDATE]);
    return true;
}

function plugin_apontamentos_uninstall(): bool
{
    // Deliberadamente não remove tabela, registros nem direitos de perfil.
    return true;
}

function plugin_apontamentos_change_profile(): void
{
    unset($_SESSION['glpimenu'], $_SESSION['plugin']['apontamentos']['menu_schema']);
    PluginApontamentosProfile::changeProfile();
}
