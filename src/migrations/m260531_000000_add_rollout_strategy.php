<?php

use CraftCms\Cms\Database\Migration;
use craftquest\featureflags\records\FlagRecord;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.1.0: rollout strategy per flag. Already applied on installs upgraded from 1.x;
 * new installs record it as applied without running it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn(FlagRecord::TABLE, 'rolloutStrategy')) {
            return;
        }

        // 'all' (default) preserves existing behavior: rules and rollout are independent.
        Schema::table(FlagRecord::TABLE, function (Blueprint $table) {
            $table->string('rolloutStrategy', 10)->default('all')->after('rolloutPercentage');
        });
    }
};
