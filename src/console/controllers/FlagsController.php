<?php

namespace craftquest\featureflags\console\controllers;

use Craft;
use craft\console\Controller;
use craftquest\featureflags\FeatureFlags;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Manage feature flags from the command line.
 */
class FlagsController extends Controller
{
    /**
     * @var bool Whether to skip confirmation prompts.
     */
    public bool $force = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if (in_array($actionID, ['delete', 'cleanup-expired'], true)) {
            $options[] = 'force';
        }

        return $options;
    }

    /**
     * Lists all feature flags.
     */
    public function actionList(): int
    {
        $flags = FeatureFlags::getInstance()->flagService->getAllFlags();

        if (empty($flags)) {
            $this->stdout(Craft::t('feature-flags', 'No feature flags yet.') . PHP_EOL);
            return ExitCode::OK;
        }

        $rows = [];
        foreach ($flags as $flag) {
            $rows[] = [
                $flag->name,
                $flag->handle,
                $flag->flagType,
                $flag->enabled ? Craft::t('feature-flags', 'Yes') : Craft::t('feature-flags', 'No'),
                $flag->rolloutPercentage !== null ? $flag->rolloutPercentage . '%' : '—',
                count($flag->rules),
                $flag->expiresAt ? $flag->expiresAt->format('Y-m-d H:i') : '—',
            ];
        }

        $this->stdout(PHP_EOL);
        $this->table(
            [
                Craft::t('feature-flags', 'Name'),
                Craft::t('feature-flags', 'Handle'),
                Craft::t('feature-flags', 'Type'),
                Craft::t('feature-flags', 'Enabled'),
                Craft::t('feature-flags', 'Rollout %'),
                Craft::t('feature-flags', 'Rules'),
                Craft::t('feature-flags', 'Expires'),
            ],
            $rows,
        );
        $this->stdout(PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Displays detailed information about a flag.
     *
     * @param string $handle The flag handle
     */
    public function actionInfo(string $handle): int
    {
        $flag = FeatureFlags::getInstance()->flagService->getFlagByHandle($handle);

        if (!$flag) {
            $this->stderr(Craft::t('feature-flags', 'Flag not found') . ": $handle" . PHP_EOL, Console::FG_RED);
            return ExitCode::UNPROCESSABLE_ENTITY;
        }

        $this->stdout(PHP_EOL);
        $this->stdout(Craft::t('feature-flags', 'Name') . ': ', Console::BOLD);
        $this->stdout($flag->name . PHP_EOL);

        $this->stdout(Craft::t('feature-flags', 'Handle') . ': ', Console::BOLD);
        $this->stdout($flag->handle . PHP_EOL);

        $this->stdout(Craft::t('feature-flags', 'Description') . ': ', Console::BOLD);
        $this->stdout(($flag->description ?: '—') . PHP_EOL);

        $this->stdout(Craft::t('feature-flags', 'Type') . ': ', Console::BOLD);
        $this->stdout($flag->flagType . PHP_EOL);

        $this->stdout(Craft::t('feature-flags', 'Enabled') . ': ', Console::BOLD);
        if ($flag->enabled) {
            $this->stdout(Craft::t('feature-flags', 'Yes') . PHP_EOL, Console::FG_GREEN);
        } else {
            $this->stdout(Craft::t('feature-flags', 'No') . PHP_EOL, Console::FG_RED);
        }

        $this->stdout(Craft::t('feature-flags', 'Rollout Percentage') . ': ', Console::BOLD);
        $this->stdout(($flag->rolloutPercentage !== null ? $flag->rolloutPercentage . '%' : '—') . PHP_EOL);

        $this->stdout(Craft::t('feature-flags', 'Expires At') . ': ', Console::BOLD);
        $this->stdout(($flag->expiresAt ? $flag->expiresAt->format('Y-m-d H:i:s') : '—') . PHP_EOL);

        $this->stdout(Craft::t('feature-flags', 'Date Created') . ': ', Console::BOLD);
        $this->stdout(($flag->dateCreated ? $flag->dateCreated->format('Y-m-d H:i:s') : '—') . PHP_EOL);

        $this->stdout(Craft::t('feature-flags', 'Date Updated') . ': ', Console::BOLD);
        $this->stdout(($flag->dateUpdated ? $flag->dateUpdated->format('Y-m-d H:i:s') : '—') . PHP_EOL);

        $this->stdout(PHP_EOL);

        if (empty($flag->rules)) {
            $this->stdout(Craft::t('feature-flags', 'No targeting rules.') . PHP_EOL);
        } else {
            $this->stdout(Craft::t('feature-flags', 'Targeting Rules') . ':' . PHP_EOL, Console::BOLD);

            $ruleRows = [];
            foreach ($flag->rules as $rule) {
                $ruleRows[] = [$rule->ruleType, $rule->ruleValue];
            }

            $this->table(
                [
                    Craft::t('feature-flags', 'Rule Type'),
                    Craft::t('feature-flags', 'Value'),
                ],
                $ruleRows,
            );
        }

        $this->stdout(PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Enables a feature flag.
     *
     * @param string $handle The flag handle
     */
    public function actionEnable(string $handle): int
    {
        $flag = FeatureFlags::getInstance()->flagService->getFlagByHandle($handle);

        if (!$flag) {
            $this->stderr(Craft::t('feature-flags', 'Flag not found') . ": $handle" . PHP_EOL, Console::FG_RED);
            return ExitCode::UNPROCESSABLE_ENTITY;
        }

        if (!FeatureFlags::getInstance()->flagService->toggleFlag($flag->id, true)) {
            $this->stderr(Craft::t('feature-flags', 'Could not toggle flag.') . PHP_EOL, Console::FG_RED);
            return ExitCode::UNPROCESSABLE_ENTITY;
        }

        $this->stdout(Craft::t('feature-flags', 'Flag "{name}" enabled.', ['name' => $flag->name]) . PHP_EOL, Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Disables a feature flag.
     *
     * @param string $handle The flag handle
     */
    public function actionDisable(string $handle): int
    {
        $flag = FeatureFlags::getInstance()->flagService->getFlagByHandle($handle);

        if (!$flag) {
            $this->stderr(Craft::t('feature-flags', 'Flag not found') . ": $handle" . PHP_EOL, Console::FG_RED);
            return ExitCode::UNPROCESSABLE_ENTITY;
        }

        if (!FeatureFlags::getInstance()->flagService->toggleFlag($flag->id, false)) {
            $this->stderr(Craft::t('feature-flags', 'Could not toggle flag.') . PHP_EOL, Console::FG_RED);
            return ExitCode::UNPROCESSABLE_ENTITY;
        }

        $this->stdout(Craft::t('feature-flags', 'Flag "{name}" disabled.', ['name' => $flag->name]) . PHP_EOL, Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Deletes a feature flag.
     *
     * @param string $handle The flag handle
     */
    public function actionDelete(string $handle): int
    {
        $flag = FeatureFlags::getInstance()->flagService->getFlagByHandle($handle);

        if (!$flag) {
            $this->stderr(Craft::t('feature-flags', 'Flag not found') . ": $handle" . PHP_EOL, Console::FG_RED);
            return ExitCode::UNPROCESSABLE_ENTITY;
        }

        if (!$this->force && !$this->confirm(Craft::t('feature-flags', 'Are you sure you want to delete this flag?'))) {
            $this->stdout(Craft::t('feature-flags', 'Aborted.') . PHP_EOL);
            return ExitCode::OK;
        }

        if (!FeatureFlags::getInstance()->flagService->deleteFlag($flag->id)) {
            $this->stderr(Craft::t('feature-flags', 'Could not delete flag.') . PHP_EOL, Console::FG_RED);
            return ExitCode::UNPROCESSABLE_ENTITY;
        }

        $this->stdout(Craft::t('feature-flags', 'Flag "{name}" deleted.', ['name' => $flag->name]) . PHP_EOL, Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Deletes all expired feature flags.
     */
    public function actionCleanupExpired(): int
    {
        $flagService = FeatureFlags::getInstance()->flagService;
        $expiredFlags = $flagService->getExpiredFlags();

        if (empty($expiredFlags)) {
            $this->stdout(Craft::t('feature-flags', 'No expired flags found.') . PHP_EOL);
            return ExitCode::OK;
        }

        $count = count($expiredFlags);
        $this->stdout(Craft::t('feature-flags', '{count} expired flag(s) found.', ['count' => $count]) . PHP_EOL);

        if (!$this->force && !$this->confirm(Craft::t('feature-flags', 'Delete all expired flags?'))) {
            $this->stdout(Craft::t('feature-flags', 'Aborted.') . PHP_EOL);
            return ExitCode::OK;
        }

        $deleted = 0;

        foreach ($expiredFlags as $flag) {
            if ($flagService->deleteFlag($flag->id)) {
                $this->stdout(Craft::t('feature-flags', 'Deleted: {name}', ['name' => $flag->name]) . PHP_EOL);
                $deleted++;
            } else {
                $this->stderr(Craft::t('feature-flags', 'Failed to delete: {name}', ['name' => $flag->name]) . PHP_EOL, Console::FG_RED);
            }
        }

        $this->stdout(PHP_EOL);
        $this->stdout(Craft::t('feature-flags', '{count} flag(s) deleted.', ['count' => $deleted]) . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }
}
