<?php

namespace craftquest\featureflags\migrations;

use craft\db\Migration;
use craftquest\featureflags\records\FlagRecord;

class m260928_000000_add_starts_at extends Migration
{
    public function safeUp(): bool
    {
        $table = FlagRecord::tableName();

        if (!$this->db->columnExists($table, 'startsAt')) {
            // Nullable: existing flags have no start date and keep evaluating as before.
            $this->addColumn($table, 'startsAt', $this->dateTime()->after('flagType'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $table = FlagRecord::tableName();

        if ($this->db->columnExists($table, 'startsAt')) {
            $this->dropColumn($table, 'startsAt');
        }

        return true;
    }
}
