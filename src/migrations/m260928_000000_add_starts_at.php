<?php

use CraftCms\Cms\Database\Migration;
use craftquest\featureflags\records\FlagRecord;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.2.0: optional start date. Already applied on installs upgraded from 1.x;
 * new installs record it as applied without running it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn(FlagRecord::TABLE, 'startsAt')) {
            return;
        }

        // Nullable: existing flags have no start date and keep evaluating as before.
        Schema::table(FlagRecord::TABLE, function (Blueprint $table) {
            $table->dateTime('startsAt')->nullable()->after('flagType');
        });
    }
};
