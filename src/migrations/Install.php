<?php

namespace craftquest\featureflags\migrations;

use Craft;
use craft\db\Migration;
use craftquest\featureflags\records\AuditRecord;
use craftquest\featureflags\records\FlagRecord;
use craftquest\featureflags\records\RuleRecord;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createFlagsTable();
        $this->createRulesTable();
        $this->createAuditTable();

        Craft::$app->db->schema->refresh();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(AuditRecord::tableName());
        $this->dropTableIfExists(RuleRecord::tableName());
        $this->dropTableIfExists(FlagRecord::tableName());

        return true;
    }

    private function createFlagsTable(): void
    {
        $table = FlagRecord::tableName();

        if ($this->db->tableExists($table)) {
            return;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'name' => $this->string(100)->notNull(),
            'handle' => $this->string(100)->notNull(),
            'description' => $this->text(),
            'enabled' => $this->boolean()->notNull()->defaultValue(false),
            'rolloutPercentage' => $this->smallInteger()->defaultValue(null),
            'flagType' => $this->string(20)->notNull()->defaultValue('release'),
            'expiresAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, $table, 'handle', true);
        $this->createIndex(null, $table, 'enabled', false);
        $this->createIndex(null, $table, 'flagType', false);
    }

    private function createRulesTable(): void
    {
        $table = RuleRecord::tableName();

        if ($this->db->tableExists($table)) {
            return;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'flagId' => $this->integer()->notNull(),
            'ruleType' => $this->string(50)->notNull(),
            'ruleValue' => $this->string(255)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, $table, 'flagId', false);
        $this->createIndex(null, $table, ['flagId', 'ruleType'], false);

        $this->addForeignKey(null, $table, 'flagId', FlagRecord::tableName(), 'id', 'CASCADE');
    }

    private function createAuditTable(): void
    {
        $table = AuditRecord::tableName();

        if ($this->db->tableExists($table)) {
            return;
        }

        $this->createTable($table, [
            'id' => $this->primaryKey(),
            'flagId' => $this->integer(),
            'userId' => $this->integer(),
            'action' => $this->string(50)->notNull(),
            'details' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, $table, 'flagId', false);
        $this->createIndex(null, $table, 'userId', false);
        $this->createIndex(null, $table, 'dateCreated', false);

        $this->addForeignKey(null, $table, 'flagId', FlagRecord::tableName(), 'id', 'SET NULL');
        $this->addForeignKey(null, $table, 'userId', '{{%users}}', 'id', 'SET NULL');
    }
}
