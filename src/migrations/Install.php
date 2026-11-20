<?php

namespace craftquest\featureflags\migrations;

use CraftCms\Cms\Database\Migration;
use craftquest\featureflags\records\AuditRecord;
use craftquest\featureflags\records\FlagRecord;
use craftquest\featureflags\records\FlagSiteRecord;
use craftquest\featureflags\records\RuleRecord;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the Feature Flags tables. The schema matches 1.x exactly, so installs upgraded from
 * Craft 5 keep their data and run no further migrations.
 */
class Install extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable(FlagRecord::TABLE)) {
            Schema::create(FlagRecord::TABLE, function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('name', 100);
                $table->string('handle', 100)->unique();
                $table->text('description')->nullable();
                $table->boolean('enabled')->default(false)->index();
                $table->smallInteger('rolloutPercentage')->nullable();
                $table->string('rolloutStrategy', 10)->default('all');
                $table->string('flagType', 20)->default('release')->index();
                $table->dateTime('startsAt')->nullable();
                $table->dateTime('expiresAt')->nullable();
                $table->dateTime('dateCreated');
                $table->dateTime('dateUpdated');
                $table->char('uid', 36)->default('0');
            });
        }

        if (!Schema::hasTable(FlagSiteRecord::TABLE)) {
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

        if (!Schema::hasTable(RuleRecord::TABLE)) {
            Schema::create(RuleRecord::TABLE, function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('flagId')->index();
                $table->string('ruleType', 50);
                $table->string('ruleValue', 255);
                $table->dateTime('dateCreated');
                $table->dateTime('dateUpdated');
                $table->char('uid', 36)->default('0');

                $table->index(['flagId', 'ruleType']);
                $table->foreign('flagId')->references('id')->on(FlagRecord::TABLE)->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable(AuditRecord::TABLE)) {
            Schema::create(AuditRecord::TABLE, function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('flagId')->nullable()->index();
                $table->integer('userId')->nullable()->index();
                $table->string('action', 50);
                $table->text('details')->nullable();
                $table->dateTime('dateCreated')->index();
                $table->dateTime('dateUpdated');
                $table->char('uid', 36)->default('0');

                // Audit history outlives the flag and the user who made the change.
                $table->foreign('flagId')->references('id')->on(FlagRecord::TABLE)->nullOnDelete();
                $table->foreign('userId')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(AuditRecord::TABLE);
        Schema::dropIfExists(RuleRecord::TABLE);
        Schema::dropIfExists(FlagSiteRecord::TABLE);
        Schema::dropIfExists(FlagRecord::TABLE);
    }
}
