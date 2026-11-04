<?php

namespace onedarnleyroad\fleetreporter\controllers;

use Craft;
use craft\web\Controller;
use onedarnleyroad\fleetreporter\services\Reporter;

class UtilityController extends Controller
{
    /**
     * POST fleet-reporter/utility/send: send a report now. Admins only.
     */
    public function actionSend()
    {
        $this->requirePostRequest();
        $this->requireAdmin(false);

        $result = (new Reporter())->run('manual');
        $session = Craft::$app->getSession();
        if ($result['ok']) {
            $session->setNotice($result['message']);
        } else {
            $session->setError($result['message']);
        }

        return $this->redirectToPostedUrl();
    }
}
