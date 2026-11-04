<?php

namespace onedarnleyroad\fleetreporter\console\controllers;

use craft\console\Controller;
use onedarnleyroad\fleetreporter\services\Reporter;
use yii\console\ExitCode;

/**
 * Fleet reports from the command line.
 */
class ReportController extends Controller
{
    /**
     * Collect and send a report now, without the queue. Use from a scheduled task on quiet sites.
     */
    public function actionIndex(): int
    {
        $result = (new Reporter())->run('console');
        $this->stdout($result['message'] . "\n");

        return $result['ok'] ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Print the report that would be sent, without sending it.
     */
    public function actionShow(): int
    {
        $reporter = new Reporter();
        $this->stdout(json_encode($reporter->preview('console'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        $skip = $reporter->skipReason();
        if ($skip !== null) {
            $this->stderr('Note: this environment would not report. ' . $skip . "\n");
        }

        return ExitCode::OK;
    }
}
