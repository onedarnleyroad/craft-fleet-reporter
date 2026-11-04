<?php

namespace onedarnleyroad\fleetreporter\console\controllers;

use craft\console\Controller;
use yii\console\ExitCode;

/**
 * `php craft fleet-reporter/report`: collect and send a report now, without the queue.
 */
class ReportController extends Controller
{
    public function actionIndex()
    {
        $this->stderr("Not implemented yet (milestone M2).\n");

        return ExitCode::UNAVAILABLE;
    }
}
