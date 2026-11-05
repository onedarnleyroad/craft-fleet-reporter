<?php

namespace onedarnleyroad\fleetreporter\utilities;

use Craft;
use craft\base\Utility;
use onedarnleyroad\fleetreporter\services\Reporter;

/**
 * Utilities → Fleet: what this installation is registered as, and its last report.
 */
class FleetUtility extends Utility
{
    public static function displayName(): string
    {
        return 'Fleet';
    }

    public static function id(): string
    {
        return 'fleet-reporter';
    }

    // Craft 3/4
    public static function iconPath(): ?string
    {
        return null;
    }

    // Craft 5
    public static function icon(): ?string
    {
        return 'signal';
    }

    public static function contentHtml(): string
    {
        $reporter = new Reporter();
        $state = $reporter->state();

        return Craft::$app->getView()->renderTemplate('fleet-reporter/_utility', [
            'skipReason' => $reporter->skipReason(),
            'registered' => (bool) $state->get('keyId'),
            'status' => $state->get('status'),
            'filedAs' => implode(' · ', array_filter([$state->get('project'), $state->get('environment')])),
            'awaitingApproval' => strpos((string) $state->get('lastError'), 'awaiting_approval') !== false,
            'lastSuccessAt' => $state->get('lastSuccessAt'),
            'lastAttemptAt' => $state->get('lastAttemptAt'),
            'lastError' => $state->get('lastError'),
            'consecutiveFailures' => (int) $state->get('consecutiveFailures', 0),
            'nextDueAt' => $state->get('nextDueAt'),
            'updatesCheckedAt' => $state->get('updatesCheckedAt'),
        ]);
    }
}
