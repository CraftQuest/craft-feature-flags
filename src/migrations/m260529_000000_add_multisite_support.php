<?php

use CraftCms\Cms\Database\Migration;
use craftquest\featureflags\records\FlagRecord;
use craftquest\featureflags\records\FlagSiteRecord;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.1.0: per-site flag settings. Already applied on installs upgraded from 1.x;
 * new installs record it as applied without running it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable(FlagSiteRecord::TABLE)) {
            return;
        }

        Schema::create(FlagSiteRecord::TABLE, function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('flagId');
            $table->integer('siteId')->index();
            $table->boolean('enabled')->default(true);
            $table->dateTime('dateCreated');
            $table->dateTime('dateUpdated');
            $table->char('uid', 36)->default('0');

            $table->unique(['flagId', 'siteId']);
            $table->foreign('flagId')->references('id')->on(FlagRecord::TABLE)->cascadeOnDelete();
            $table->foreign('siteId')->references('id')->on('sites')->cascadeOnDelete();
        });
    }
};
