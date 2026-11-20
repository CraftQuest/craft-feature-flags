<?php

use CraftCms\Cms\Database\Migration;
use craftquest\featureflags\records\FlagRecord;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.0.1: rolloutPercentage becomes a smallint. Already applied on installs upgraded from 1.x;
 * new installs record it as applied without running it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(FlagRecord::TABLE, function (Blueprint $table) {
            $table->smallInteger('rolloutPercentage')->nullable()->change();
        });
    }
};
