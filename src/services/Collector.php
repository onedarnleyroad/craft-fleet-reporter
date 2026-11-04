<?php

namespace onedarnleyroad\fleetreporter\services;

use Composer\InstalledVersions;
use Craft;
use onedarnleyroad\fleetreporter\helpers\Compat;
use onedarnleyroad\fleetreporter\models\Settings;
use onedarnleyroad\fleetreporter\Plugin;
use Throwable;

/**
 * Builds a report matching docs/schema/report-v1.schema.json.
 *
 * Every section is collected independently: a failure becomes null plus an entry in `unknowns`,
 * never a guessed value.
 */
class Collector
{
    const UPDATE_CHECK_INTERVAL = 86400;

    /** @var array<string, string> JSON pointer → reason */
    private $unknowns = [];

    /** @var State */
    private $state;

    public function __construct(State $state)
    {
        $this->state = $state;
    }

    public static function fingerprint(): string
    {
        return hash('sha256', self::normaliseUrl(self::primaryBaseUrl()) . '|' . (string) Craft::$app->env);
    }

    public static function primaryBaseUrl()
    {
        try {
            return self::resolvedUrl(Craft::$app->getSites()->getPrimarySite()->getBaseUrl(true));
        } catch (Throwable $e) {
            return null;
        }
    }

    public function collect(string $reportId, string $trigger, array $delivery): array
    {
        $this->unknowns = [];

        $updates = $this->updates();
        $licences = $this->licenceInfo();

        $report = [
            'schema_version' => Plugin::SCHEMA_VERSION,
            'report_id' => $reportId,
            'observed_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'fingerprint' => self::fingerprint(),
            'reporter' => [
                'version' => Plugin::getInstance()->getVersion(),
                'trigger' => $trigger,
            ],
            'environment' => [
                'craft_environment' => Craft::$app->env ? (string) Craft::$app->env : null,
                'primary_base_url' => self::primaryBaseUrl(),
            ],
            'craft' => $this->craft($licences, $updates),
            'php' => ['version' => PHP_VERSION],
            'database' => $this->database(),
            'sites' => $this->sites(),
            'aliases' => $this->aliases(),
            'plugins' => $this->plugins($licences, $updates),
            'updates' => $this->updatesSection($updates),
            'unknowns' => (object) $this->unknowns,
            'delivery' => $delivery,
        ];

        return $report;
    }

    private function craft($licences, $updates): array
    {
        $licence = ['status' => null, 'expired' => null, 'expires_on' => null];
        if ($licences === null) {
            $this->unknown('/craft/licence/status', 'not_configured');
        } else {
            $licence['status'] = Compat::licenceStatus(isset($licences['craft']['status']) ? $licences['craft']['status'] : null);
        }
        if ($updates !== null && isset($updates->cms)) {
            $licence['expired'] = $updates->cms->status === 'expired';
        }

        $edition = null;
        try {
            $handle = Craft::$app->getEditionHandle();
            $edition = in_array($handle, ['solo', 'team', 'pro', 'enterprise'], true) ? $handle : null;
        } catch (Throwable $e) {
            $this->unknown('/craft/edition', 'error');
        }

        return [
            'version' => Craft::$app->getVersion(),
            'edition' => $edition,
            'licence' => $licence,
            'dev_mode' => (bool) Craft::$app->getConfig()->getGeneral()->devMode,
            'system_on' => $this->attempt('/craft/system_on', function () {
                return (bool) Craft::$app->getIsLive();
            }),
            'pending_migrations' => $this->attempt('/craft/pending_migrations', function () {
                return Compat::pendingMigrations();
            }),
        ];
    }

    private function database(): array
    {
        try {
            list($driver, $version) = Compat::database();
        } catch (Throwable $e) {
            $driver = $version = null;
        }
        if ($driver === null) {
            $this->unknown('/database/driver', 'error');
        }
        if ($version === null) {
            $this->unknown('/database/version', 'error');
        }

        return ['driver' => $driver, 'version' => $version];
    }

    private function sites(): array
    {
        $sites = [];
        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $setting = $site->getBaseUrl(false);
            $sites[] = [
                'uid' => $site->uid,
                'handle' => $site->handle,
                'name' => method_exists($site, 'getName') ? $site->getName() : $site->name,
                'language' => $site->language,
                'enabled' => (bool) (method_exists($site, 'getEnabled') ? $site->getEnabled() : $site->enabled),
                'primary' => (bool) $site->primary,
                'base_url_setting' => self::safeSetting($setting),
                'base_url' => self::resolvedUrl($site->getBaseUrl(true)),
            ];
        }

        return $sites;
    }

    private function aliases(): array
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        $out = [];

        foreach ((array) $settings->aliases as $handle => $entries) {
            foreach ((array) $entries as $entry) {
                $entry = is_string($entry) ? ['url' => $entry] : (array) $entry;
                $url = isset($entry['url']) ? self::resolvedUrl(Compat::parseEnv($entry['url'])) : null;
                if ($url === null) {
                    continue;
                }
                $out[] = [
                    'site_handle' => (string) $handle,
                    'url' => $url,
                    'expected' => (isset($entry['expected']) && $entry['expected'] === 'serve') ? 'serve' : 'redirect',
                    'note' => isset($entry['note']) ? mb_substr((string) $entry['note'], 0, 200) : null,
                ];
            }
        }

        return $out;
    }

    private function plugins($licences, $updates): array
    {
        $plugins = [];
        foreach (Craft::$app->getPlugins()->getAllPluginInfo() as $handle => $info) {
            if (empty($info['isInstalled'])) {
                continue;
            }
            $package = isset($info['packageName']) ? $info['packageName'] : null;

            $licence = ['status' => null, 'expired' => null, 'expires_on' => null];
            if ($licences !== null) {
                $key = 'plugin-' . $handle;
                // Craft's licence info only lists commercial plugins.
                $licence['status'] = isset($licences[$key])
                    ? Compat::licenceStatus($licences[$key]['status'])
                    : 'free';
            }
            if ($licence['status'] !== 'free' && $updates !== null && isset($updates->plugins[$handle])) {
                $licence['expired'] = $updates->plugins[$handle]->status === 'expired';
            }

            $plugins[] = [
                'handle' => (string) $handle,
                'package' => $package,
                'name' => (string) $info['name'],
                'version' => self::installedVersion($package, (string) $info['version']),
                'installed' => true,
                'enabled' => !empty($info['isEnabled']),
                'edition' => isset($info['edition']) && $info['edition'] !== 'standard' ? (string) $info['edition'] : null,
                'licence' => $licence,
            ];
        }

        return $plugins;
    }

    /**
     * Craft's update check, refreshed live at most once a day.
     */
    private function updates()
    {
        $service = Craft::$app->getUpdates();
        $last = $this->state->get('updatesCheckedAt');
        $due = !$last || (time() - strtotime($last)) >= self::UPDATE_CHECK_INTERVAL || !$service->getIsUpdateInfoCached();

        try {
            $updates = $service->getUpdates($due);
            if ($due) {
                $this->state->set(['updatesCheckedAt' => gmdate('Y-m-d H:i:s'), 'updatesSource' => 'live']);
            } else {
                $this->state->set(['updatesSource' => 'cache']);
            }
            return $updates;
        } catch (Throwable $e) {
            Craft::warning('Fleet Reporter could not read updates: ' . $e->getMessage(), __METHOD__);
            $this->unknown('/updates/checked_at', 'error');
            return null;
        }
    }

    private function updatesSection($updates): array
    {
        $checkedAt = $this->state->get('updatesCheckedAt');
        $plugins = [];

        if ($updates !== null) {
            foreach ((array) $updates->plugins as $handle => $update) {
                $entry = self::updateEntry($update);
                if ($entry !== null) {
                    $plugins[(string) $handle] = $entry;
                }
            }
        }

        return [
            'checked_at' => $checkedAt ? gmdate('Y-m-d\TH:i:s\Z', strtotime($checkedAt . ' UTC')) : null,
            'source' => $updates === null ? null : $this->state->get('updatesSource'),
            'craft' => ($updates !== null && isset($updates->cms)) ? self::updateEntry($updates->cms) : null,
            'plugins' => (object) $plugins,
        ];
    }

    private static function updateEntry($update)
    {
        if (!$update || !$update->getHasReleases()) {
            return null;
        }
        $latest = $update->getLatest();
        $entry = [
            'latest' => (string) $latest->version,
            'critical' => (bool) $update->getHasCritical(),
        ];
        if ($update->status === 'expired') {
            $entry['expired_licence_blocks_update'] = true;
        }

        return $entry;
    }

    /**
     * Craft's cached licence info, or null when Craft hasn't fetched it yet.
     */
    private function licenceInfo()
    {
        $info = Craft::$app->getCache()->get('licenseInfo');

        return is_array($info) ? $info : null;
    }

    private function attempt(string $pointer, callable $fn)
    {
        try {
            $value = $fn();
        } catch (Throwable $e) {
            $value = null;
        }
        if ($value === null) {
            $this->unknown($pointer, 'error');
        }

        return $value;
    }

    private function unknown(string $pointer, string $reason)
    {
        $this->unknowns[$pointer] = $reason;
    }

    private static function installedVersion($package, string $fallback): string
    {
        if ($package && class_exists(InstalledVersions::class)) {
            try {
                if (InstalledVersions::isInstalled($package)) {
                    return ltrim((string) InstalledVersions::getPrettyVersion($package), 'v');
                }
            } catch (Throwable $e) {
            }
        }

        return $fallback;
    }

    /**
     * Only env var / alias names and literal URLs are reported; anything else is dropped.
     */
    private static function safeSetting($setting)
    {
        if (!is_string($setting) || $setting === '') {
            return null;
        }
        if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $setting) || preg_match('/^@[A-Za-z][A-Za-z0-9_]*(\/.*)?$/', $setting)) {
            return $setting;
        }

        return self::resolvedUrl($setting);
    }

    private static function resolvedUrl($url)
    {
        if (!is_string($url) || !preg_match('#^https?://[^\s/$@]+#i', $url)) {
            return null;
        }

        return $url;
    }

    private static function normaliseUrl($url): string
    {
        if (!$url) {
            return '';
        }
        $parts = parse_url($url);
        $host = strtolower(isset($parts['host']) ? $parts['host'] : '');
        $scheme = strtolower(isset($parts['scheme']) ? $parts['scheme'] : 'https');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = rtrim(isset($parts['path']) ? $parts['path'] : '', '/');

        return $scheme . '://' . $host . $port . $path;
    }
}
