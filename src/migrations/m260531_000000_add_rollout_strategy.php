<?php

namespace craftquest\featureflags\migrations;

use craft\db\Migration;
use craftquest\featureflags\records\FlagRecord;

class m260531_000000_add_rollout_strategy extends Migration
{
    public function safeUp(): bool
    {
        $table = FlagRecord::tableName();

        if (!$this->db->columnExists($table, 'rolloutStrategy')) {
            // 'all' (default) preserves existing behavior: rules and rollout are independent.
            $this->addColumn(
                $table,
                'rolloutStrategy',
                $this->string(10)->notNull()->defaultValue('all')->after('rolloutPercentage')
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        $table = FlagRecord::tableName();

        if ($this->db->columnExists($table, 'rolloutStrategy')) {
            $this->dropColumn($table, 'rolloutStrategy');
        }

        return true;
    }
}
