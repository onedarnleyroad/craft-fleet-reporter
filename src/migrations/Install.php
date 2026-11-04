<?php

namespace onedarnleyroad\fleetreporter\migrations;

use craft\db\Migration;

/**
 * Plugin state lives in its own table, never in project config, so it isn't synced between environments.
 */
class Install extends Migration
{
    const TABLE = '{{%fleetreporter_state}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::TABLE)) {
            return true;
        }

        $this->createTable(self::TABLE, [
            'id' => $this->primaryKey(),
            'name' => $this->string(64)->notNull(),
            'value' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, self::TABLE, ['name'], true);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::TABLE);

        return true;
    }
}
