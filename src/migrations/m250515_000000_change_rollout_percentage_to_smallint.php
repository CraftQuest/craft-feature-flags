<?php

namespace craftquest\featureflags\migrations;

use craft\db\Migration;
use craftquest\featureflags\records\FlagRecord;

class m250515_000000_change_rollout_percentage_to_smallint extends Migration
{
    public function safeUp(): bool
    {
        $this->alterColumn(FlagRecord::tableName(), 'rolloutPercentage', $this->smallInteger()->defaultValue(null));

        return true;
    }

    public function safeDown(): bool
    {
        $this->alterColumn(FlagRecord::tableName(), 'rolloutPercentage', $this->tinyInteger()->unsigned()->defaultValue(null));

        return true;
    }
}
