<?php

namespace onedarnleyroad\fleetreporter\services;

use Craft;
use craft\helpers\StringHelper;
use onedarnleyroad\fleetreporter\helpers\Compat;
use onedarnleyroad\fleetreporter\models\Settings;
use onedarnleyroad\fleetreporter\Plugin;
use Throwable;

/**
 * Decides when to report, registers when needed, sends, and schedules the next attempt.
 */
class Reporter
{
    const CACHE_NEXT_DUE = 'fleet-reporter:next-due';
    const DEFAULT_INTERVAL = 3600;
    const BACKOFF = [300, 900, 1800];
    const LOCAL_ENVIRONMENTS = ['dev', 'local'];
    const LOCAL_SUFFIXES = ['.test', '.local', '.ddev.site', 'localhost'];

    /** @var State */
    private $state;

    public function __construct(?State $state = null)
    {
        $this->state = $state ?: new State();
    }

    public function state(): State
    {
        return $this->state;
    }

    public function isDue(): bool
    {
        $due = Craft::$app->getCache()->get(self::CACHE_NEXT_DUE);
        if ($due === false) {
            $due = (int) $this->state->get('nextDueAt', 0);
        }

        return time() >= (int) $due;
    }

    public function postpone(int $seconds)
    {
        $at = time() + $seconds;
        Craft::$app->getCache()->set(self::CACHE_NEXT_DUE, $at, $seconds + 60);
        $this->state->set(['nextDueAt' => $at]);
    }

    /**
     * Why this environment won't report, or null if it will.
     */
    public function skipReason()
    {
        if (!Compat::env('FLEET_URL') || !Compat::env('FLEET_ORG_KEY')) {
            return 'FLEET_URL and FLEET_ORG_KEY are not both set.';
        }

        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        if ($settings->registerLocal || filter_var(Compat::env('FLEET_REGISTER_LOCAL'), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        if (in_array(strtolower((string) Craft::$app->env), self::LOCAL_ENVIRONMENTS, true)) {
            return 'This is a local environment (CRAFT_ENVIRONMENT=' . Craft::$app->env . ').';
        }
        $host = strtolower((string) parse_url((string) Collector::primaryBaseUrl(), PHP_URL_HOST));
        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if ($host === ltrim($suffix, '.') || substr($host, -strlen($suffix)) === $suffix) {
                return 'This is a local environment (' . $host . ').';
            }
        }

        return null;
    }

    /**
     * Builds the report that would be sent now, without sending it.
     */
    public function preview(string $trigger = 'manual'): array
    {
        return (new Collector($this->state))->collect(StringHelper::UUID(), $trigger, $this->delivery());
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function run(string $trigger): array
    {
        $this->state->set(['lastAttemptAt' => gmdate('Y-m-d H:i:s')]);

        $skip = $this->skipReason();
        if ($skip !== null) {
            $this->postpone(self::DEFAULT_INTERVAL);
            return ['ok' => false, 'message' => 'Not reporting: ' . $skip];
        }

        try {
            if ($this->state->get('fingerprint') && $this->state->get('fingerprint') !== Collector::fingerprint()) {
                // A copied database or a changed primary URL: this is a different installation.
                $this->state->forgetCredentials();
            }

            $reportId = $this->reportId();
            $report = (new Collector($this->state))->collect($reportId, $trigger, $this->delivery());
            $body = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $client = new Client((string) Compat::env('FLEET_URL'));

            $keyId = $this->state->get('keyId');
            $secret = $this->state->secret();

            if (!$keyId || !$secret) {
                list($status, $response, $error) = $client->register((string) Compat::env('FLEET_ORG_KEY'), $body);
                if ($error === null && isset($response['key_id'], $response['secret'])) {
                    $this->state->set([
                        'installationUuid' => isset($response['installation_uuid']) ? $response['installation_uuid'] : null,
                        'keyId' => $response['key_id'],
                        'fingerprint' => $report['fingerprint'],
                    ]);
                    $this->state->setSecret($response['secret']);
                    return $this->succeeded($response, 'Registered with Fleet');
                }
                return $this->failed($error ?: 'Unexpected registration response');
            }

            list($status, $response, $error) = $client->report($keyId, $secret, $body);
            if ($error === null) {
                return $this->succeeded($response, 'Report delivered');
            }
            if ($status === 401 && isset($response['error']) && in_array($response['error'], ['unknown_key', 'revoked', 'fingerprint_mismatch'], true)) {
                $this->state->forgetCredentials();
            }

            return $this->failed($error);
        } catch (Throwable $e) {
            Craft::error('Fleet Reporter failed: ' . $e->getMessage(), __METHOD__);
            return $this->failed(get_class($e) . ': ' . $e->getMessage());
        }
    }

    private function succeeded($response, string $message): array
    {
        $interval = isset($response['next_report_after']) ? max(300, (int) $response['next_report_after']) : self::DEFAULT_INTERVAL;
        $this->state->set([
            'status' => isset($response['status']) ? $response['status'] : $this->state->get('status'),
            'pendingReportId' => null,
            'attempt' => 0,
            'consecutiveFailures' => 0,
            'lastError' => null,
            'lastSuccessAt' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->postpone($interval);

        return ['ok' => true, 'message' => $message . ' (' . $this->state->get('status', 'unknown') . ').'];
    }

    private function failed(string $error): array
    {
        $failures = (int) $this->state->get('consecutiveFailures', 0) + 1;
        $this->state->set([
            'consecutiveFailures' => $failures,
            'lastError' => mb_substr($error, 0, 500),
        ]);
        $this->postpone(self::BACKOFF[min($failures, count(self::BACKOFF)) - 1]);
        Craft::warning('Fleet Reporter: ' . $error, __METHOD__);

        return ['ok' => false, 'message' => 'Report failed: ' . $error];
    }

    /**
     * The same report_id is reused until a report is accepted, so retries are idempotent.
     */
    private function reportId(): string
    {
        $id = $this->state->get('pendingReportId');
        if (!$id) {
            $id = StringHelper::UUID();
            $this->state->set(['pendingReportId' => $id, 'attempt' => 0]);
        }
        $this->state->set(['attempt' => (int) $this->state->get('attempt', 0) + 1]);

        return $id;
    }

    private function delivery(): array
    {
        $error = $this->state->get('lastError');

        return [
            'attempt' => max(1, (int) $this->state->get('attempt', 1)),
            'consecutive_failures' => (int) $this->state->get('consecutiveFailures', 0),
            'last_error' => $error ? mb_substr($error, 0, 500) : null,
        ];
    }
}
