<?php

namespace craftquest\featureflags\console;

use CraftCms\Cms\Console\CraftCommand;
use craftquest\featureflags\FeatureFlags;
use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function CraftCms\Cms\t;

#[Aliases(['feature-flags/flags/delete'])]
#[Description('Deletes a feature flag.')]
#[Signature('craft:feature-flags:flags:delete {handle : The flag handle} {--force= : Skip the confirmation prompt}')]
class DeleteCommand extends Command
{
    use CraftCommand;
    use InteractsWithFlags;

    public function handle(): int
    {
        if (!$flag = $this->findFlag($this->argument('handle'))) {
            return self::FAILURE;
        }

        if (!$this->boolOption('force') && !$this->confirm(t('Are you sure you want to delete this flag?', [], 'feature-flags'))) {
            $this->line(t('Aborted.', [], 'feature-flags'));
            return self::SUCCESS;
        }

        if (!FeatureFlags::getInstance()->flagService->deleteFlag($flag->id)) {
            $this->error(t('Could not delete flag.', [], 'feature-flags'));
            return self::FAILURE;
        }

        $this->info(t('Flag "{name}" deleted.', ['name' => $flag->name], 'feature-flags'));
        return self::SUCCESS;
    }
}
