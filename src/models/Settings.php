<?php

namespace onedarnleyroad\fleetreporter\models;

use craft\base\Model;

/**
 * Set in the project's config/fleet-reporter.php. FLEET_URL and FLEET_ORG_KEY are env vars only.
 */
class Settings extends Model
{
    /**
     * Alias URLs per site handle. Each entry is a URL string, or
     * ['url' => …, 'expected' => 'redirect'|'serve', 'note' => …].
     *
     * @var array<string, array>
     */
    public $aliases = [];

    /**
     * Register and report from local environments (dev/local, *.test, *.local, *.ddev.site).
     * The FLEET_REGISTER_LOCAL=true env var does the same.
     *
     * @var bool
     */
    public $registerLocal = false;
}
