<?php

namespace onedarnleyroad\fleetreporter\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use onedarnleyroad\fleetreporter\migrations\Install;

/**
 * Small name → value store in {{%fleetreporter_state}}.
 *
 * Known names: installationUuid, keyId, secret (encrypted), fingerprint, status, nextReportAfter,
 * pendingReportId, attempt, consecutiveFailures, lastError, lastSuccessAt, lastAttemptAt,
 * updatesCheckedAt, updatesSource.
 */
class State
{
    const SECRET = 'secret';

    /** @var array<string, string|null>|null */
    private $values;

    public function get(string $name, $default = null)
    {
        $this->load();

        return array_key_exists($name, $this->values) ? $this->values[$name] : $default;
    }

    public function secret()
    {
        $stored = $this->get(self::SECRET);
        if (!$stored) {
            return null;
        }
        $decrypted = Craft::$app->getSecurity()->decryptByKey(base64_decode($stored));

        return $decrypted === false ? null : $decrypted;
    }

    public function setSecret($secret)
    {
        $this->set([self::SECRET => $secret === null ? null : base64_encode(Craft::$app->getSecurity()->encryptByKey($secret))]);
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public function set(array $values)
    {
        $this->load();
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());

        foreach ($values as $name => $value) {
            $value = $value === null ? null : (string) $value;
            if (array_key_exists($name, $this->values)) {
                $db->createCommand()->update(Install::TABLE, ['value' => $value, 'dateUpdated' => $now], ['name' => $name])->execute();
            } else {
                $db->createCommand()->insert(Install::TABLE, [
                    'name' => $name,
                    'value' => $value,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => StringHelper::UUID(),
                ])->execute();
            }
            $this->values[$name] = $value;
        }
    }

    public function forgetCredentials()
    {
        $this->set(['installationUuid' => null, 'keyId' => null, 'fingerprint' => null, 'status' => null]);
        $this->setSecret(null);
    }

    private function load()
    {
        if ($this->values !== null) {
            return;
        }
        $this->values = (new Query())
            ->select(['value', 'name'])
            ->from(Install::TABLE)
            ->indexBy('name')
            ->column();
    }
}
