<?php

namespace onedarnleyroad\fleetreporter;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use onedarnleyroad\fleetreporter\helpers\Compat;
use onedarnleyroad\fleetreporter\jobs\ReportJob;
use onedarnleyroad\fleetreporter\models\Settings;
use onedarnleyroad\fleetreporter\services\Reporter;
use onedarnleyroad\fleetreporter\utilities\FleetUtility;
use Throwable;
use yii\base\Application;

/**
 * Fleet Reporter.
 *
 * Written in PHP 7.2-compatible syntax so one codebase runs on Craft 3.7, 4 and 5.
 * Return types are added where Craft 4/5 require them; they remain valid against Craft 3's untyped parents.
 */
class Plugin extends BasePlugin
{
    /**
     * Report schema version this build produces. Must match docs/schema/report-v1.schema.json in the fleet repo.
     */
    const SCHEMA_VERSION = '1';

    const MUTEX = 'fleet-reporter:enqueue';

    public function init(): void
    {
        parent::init();

        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest()) {
            $this->controllerNamespace = 'onedarnleyroad\\fleetreporter\\console\\controllers';
            return;
        }

        Compat::registerUtility(FleetUtility::class);

        // Only cost on a normal request: one cache read in Reporter::isDue().
        Craft::$app->on(Application::EVENT_AFTER_REQUEST, function () {
            $this->maybeQueueReport();
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    private function maybeQueueReport()
    {
        try {
            $request = Craft::$app->getRequest();
            if ($request->getIsActionRequest() && strpos((string) $request->getActionSegments()[0], 'fleet-reporter') === 0) {
                return;
            }

            $reporter = new Reporter();
            if (!$reporter->isDue()) {
                return;
            }

            $mutex = Craft::$app->getMutex();
            if (!$mutex->acquire(self::MUTEX, 0)) {
                return;
            }
            try {
                $cache = Craft::$app->getCache();
                if ($cache->get(ReportJob::CACHE_QUEUED) === false) {
                    Craft::$app->getQueue()->push(new ReportJob());
                    $cache->set(ReportJob::CACHE_QUEUED, true, 900);
                }
                // Hold further requests off until the job reschedules with the real interval.
                $reporter->postpone(900);
            } finally {
                $mutex->release(self::MUTEX);
            }
        } catch (Throwable $e) {
            Craft::warning('Fleet Reporter could not queue a report: ' . $e->getMessage(), __METHOD__);
        }
    }
}
