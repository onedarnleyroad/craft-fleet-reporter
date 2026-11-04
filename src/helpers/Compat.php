<?php

namespace onedarnleyroad\fleetreporter\helpers;

use Craft;
use craft\helpers\App;

/**
 * Wraps the Craft calls that differ between 3.7, 4 and 5.
 */
class Compat
{
    public static function env(string $name)
    {
        $value = method_exists(App::class, 'env') ? App::env($name) : getenv($name);

        return ($value === false || $value === '') ? null : $value;
    }

    public static function parseEnv($value)
    {
        return method_exists(App::class, 'parseEnv') ? App::parseEnv($value) : Craft::parseEnv($value);
    }

    /**
     * @return bool|null null when Craft can't tell
     */
    public static function pendingMigrations()
    {
        $updates = Craft::$app->getUpdates();

        if (method_exists($updates, 'getIsCraftUpdatePending')) {
            // Craft 4+
            return $updates->getIsCraftUpdatePending() || $updates->getIsPluginUpdatePending();
        }
        if (method_exists($updates, 'getIsCraftDbMigrationNeeded')) {
            // Craft 3
            return $updates->getIsCraftDbMigrationNeeded() || $updates->getIsPluginDbUpdateNeeded();
        }

        return null;
    }

    /**
     * @return array{0: string|null, 1: string|null} [driver, version]
     */
    public static function database(): array
    {
        $db = Craft::$app->getDb();
        $driver = $db->getDriverName();
        $raw = (string) $db->getSchema()->getServerVersion();

        if ($driver === 'mysql') {
            $isMaria = method_exists($db, 'getIsMaria') ? $db->getIsMaria() : stripos($raw, 'mariadb') !== false;
            $driver = $isMaria ? 'mariadb' : 'mysql';
        } elseif ($driver !== 'pgsql') {
            $driver = null;
        }

        // "10.11.8-MariaDB-ubu2204" → "10.11.8"; "15.6 (Debian 15.6-1)" → "15.6"
        $version = preg_match('/\d+(\.\d+){0,2}/', $raw, $m) ? $m[0] : null;

        return [$driver, $version];
    }

    /**
     * Licence statuses arrive as strings on every version (Craft 5 stores the enum's ->value).
     */
    public static function licenceStatus($status)
    {
        if (is_object($status) && property_exists($status, 'value')) {
            $status = $status->value;
        }

        return in_array($status, ['valid', 'invalid', 'mismatched', 'astray', 'trial'], true) ? $status : null;
    }

    public static function registerUtility(string $class)
    {
        $services = 'craft\\services\\Utilities';
        $event = defined($services . '::EVENT_REGISTER_UTILITIES')
            ? constant($services . '::EVENT_REGISTER_UTILITIES')   // Craft 5
            : constant($services . '::EVENT_REGISTER_UTILITY_TYPES'); // Craft 3/4

        \yii\base\Event::on($services, $event, function ($e) use ($class) {
            $e->types[] = $class;
        });
    }
}
