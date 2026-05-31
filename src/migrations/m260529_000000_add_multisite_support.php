<?php

namespace craftquest\featureflags\migrations;

use craft\db\Migration;
use craftquest\featureflags\records\FlagRecord;
use craftquest\featureflags\records\FlagSiteRecord;

class m260529_000000_add_multisite_support extends Migration
{
    public function safeUp(): bool
    {
        $flagsTable = FlagRecord::tableName();
        $sitesTable = FlagSiteRecord::tableName();

        if (!$this->db->tableExists($sitesTable)) {
            $this->createTable($sitesTable, [
                'id' => $this->primaryKey(),
                'flagId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, $sitesTable, ['flagId', 'siteId'], true);
            $this->createIndex(null, $sitesTable, 'siteId', false);

            $this->addForeignKey(null, $sitesTable, 'flagId', $flagsTable, 'id', 'CASCADE');
            $this->addForeignKey(null, $sitesTable, 'siteId', '{{%sites}}', 'id', 'CASCADE');
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(FlagSiteRecord::tableName());

        return true;
    }
}
