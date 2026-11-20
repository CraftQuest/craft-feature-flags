<?php

namespace craftquest\featureflags\console;

use CraftCms\Cms\Console\CraftCommand;
use craftquest\featureflags\FeatureFlags;
use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function CraftCms\Cms\t;

#[Aliases(['feature-flags/flags/cleanup-expired'])]
#[Description('Deletes all expired feature flags.')]
#[Signature('craft:feature-flags:flags:cleanup-expired {--force= : Skip the confirmation prompt}')]
class CleanupExpiredCommand extends Command
{
    use CraftCommand;
    use InteractsWithFlags;

    public function handle(): int
    {
        $flagService = FeatureFlags::getInstance()->flagService;
        $expiredFlags = $flagService->getExpiredFlags();

        if (empty($expiredFlags)) {
            $this->line(t('No expired flags found.', [], 'feature-flags'));
            return self::SUCCESS;
        }

        $this->line(t('{count} expired flag(s) found.', ['count' => count($expiredFlags)], 'feature-flags'));

        // Without --force, ask. In non-interactive runs (cron, CI) confirm() returns the default
        // (no), so nothing is deleted unless --force is passed.
        if (!$this->boolOption('force') && !$this->confirm(t('Delete all expired flags?', [], 'feature-flags'))) {
            $this->line(t('Aborted.', [], 'feature-flags'));
            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($expiredFlags as $flag) {
            if ($flagService->deleteFlag($flag->id)) {
                $this->line(t('Deleted: {name}', ['name' => $flag->name], 'feature-flags'));
                $deleted++;
            } else {
                $this->error(t('Failed to delete: {name}', ['name' => $flag->name], 'feature-flags'));
            }
        }

        $this->info(t('{count} flag(s) deleted.', ['count' => $deleted], 'feature-flags'));
        return self::SUCCESS;
    }
}
