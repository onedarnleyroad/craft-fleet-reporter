<?php

namespace onedarnleyroad\fleetreporter;

use Craft;
use craft\base\Plugin as BasePlugin;

/**
 * Fleet Reporter.
 *
 * Written in PHP 7.2-compatible syntax so one codebase runs on Craft 3.7, 4 and 5.
 * Collectors, registration, the report job and the after-request trigger land in milestone M2.
 */
class Plugin extends BasePlugin
{
    /**
     * Report schema version this build produces. Must match docs/schema/report-v1.schema.json in the fleet repo.
     */
    const SCHEMA_VERSION = '1';

    // `: void` is required by Craft 4/5 and still valid against Craft 3's untyped parent.
    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'onedarnleyroad\\fleetreporter\\console\\controllers';
        }
    }
}
