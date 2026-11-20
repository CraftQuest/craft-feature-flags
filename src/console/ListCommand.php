<?php

namespace craftquest\featureflags\console;

use CraftCms\Cms\Console\CraftCommand;
use craftquest\featureflags\FeatureFlags;
use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function CraftCms\Cms\t;

#[Aliases(['feature-flags/flags/list'])]
#[Description('Lists all feature flags.')]
#[Signature('craft:feature-flags:flags:list')]
class ListCommand extends Command
{
    use CraftCommand;

    public function handle(): int
    {
        $flags = FeatureFlags::getInstance()->flagService->getAllFlags();

        if (empty($flags)) {
            $this->line(t('No feature flags yet.', [], 'feature-flags'));
            return self::SUCCESS;
        }

        $this->table(
            array_map(fn(string $h) => t($h, [], 'feature-flags'), ['Name', 'Handle', 'Type', 'Enabled', 'Rollout %', 'Rules', 'Starts', 'Expires']),
            array_map(fn($flag) => [
                $flag->name,
                $flag->handle,
                $flag->flagType,
                $flag->enabled ? t('Yes', [], 'feature-flags') : t('No', [], 'feature-flags'),
                $flag->rolloutPercentage !== null ? $flag->rolloutPercentage . '%' : '—',
                count($flag->rules),
                $flag->startsAt?->format('Y-m-d H:i') ?? '—',
                $flag->expiresAt?->format('Y-m-d H:i') ?? '—',
            ], $flags),
        );

        return self::SUCCESS;
    }
}
