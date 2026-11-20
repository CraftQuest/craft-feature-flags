<?php

namespace craftquest\featureflags\console;

use CraftCms\Cms\Console\CraftCommand;
use craftquest\featureflags\FeatureFlags;
use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function CraftCms\Cms\t;

#[Aliases(['feature-flags/flags/disable'])]
#[Description('Disables a feature flag.')]
#[Signature('craft:feature-flags:flags:disable {handle : The flag handle}')]
class DisableCommand extends Command
{
    use CraftCommand;
    use InteractsWithFlags;

    public function handle(): int
    {
        if (!$flag = $this->findFlag($this->argument('handle'))) {
            return self::FAILURE;
        }

        if (!FeatureFlags::getInstance()->flagService->toggleFlag($flag->id, false)) {
            $this->error(t('Could not toggle flag.', [], 'feature-flags'));
            return self::FAILURE;
        }

        $this->info(t('Flag "{name}" disabled.', ['name' => $flag->name], 'feature-flags'));
        return self::SUCCESS;
    }
}
