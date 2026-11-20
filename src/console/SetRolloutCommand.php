<?php

namespace craftquest\featureflags\console;

use CraftCms\Cms\Console\CraftCommand;
use craftquest\featureflags\FeatureFlags;
use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function CraftCms\Cms\t;

#[Aliases(['feature-flags/flags/set-rollout'])]
#[Description('Sets a flag\'s rollout percentage.')]
#[Signature('craft:feature-flags:flags:set-rollout {handle : The flag handle} {percentage : 0–100, or "none" to remove the rollout}')]
class SetRolloutCommand extends Command
{
    use CraftCommand;
    use InteractsWithFlags;

    public function handle(): int
    {
        if (!$flag = $this->findFlag($this->argument('handle'))) {
            return self::FAILURE;
        }

        $percentage = (string)$this->argument('percentage');
        if (strtolower($percentage) === 'none') {
            $flag->rolloutPercentage = null;
        } elseif (ctype_digit($percentage) && (int)$percentage <= 100) {
            $flag->rolloutPercentage = (int)$percentage;
        } else {
            $this->error(t('Percentage must be a whole number from 0 to 100, or "none".', [], 'feature-flags'));
            return self::FAILURE;
        }

        if (!FeatureFlags::getInstance()->flagService->saveFlag($flag)) {
            $this->printModelErrors($flag);
            return self::FAILURE;
        }

        $value = $flag->rolloutPercentage !== null ? $flag->rolloutPercentage . '%' : t('none', [], 'feature-flags');
        $this->info(t('Rollout for "{name}" set to {value}.', ['name' => $flag->name, 'value' => $value], 'feature-flags'));
        return self::SUCCESS;
    }
}
