<?php

namespace onedarnleyroad\fleetreporter\jobs;

use Craft;
use craft\queue\BaseJob;
use onedarnleyroad\fleetreporter\services\Reporter;

/**
 * Sends one report. Never throws, so failures don't pile up in the failed-jobs list;
 * the reporter schedules its own retry instead.
 */
class ReportJob extends BaseJob
{
    const CACHE_QUEUED = 'fleet-reporter:queued';

    public function execute($queue): void
    {
        try {
            (new Reporter())->run('web');
        } finally {
            Craft::$app->getCache()->delete(self::CACHE_QUEUED);
        }
    }

    protected function defaultDescription(): ?string
    {
        return 'Sending Fleet report';
    }
}
