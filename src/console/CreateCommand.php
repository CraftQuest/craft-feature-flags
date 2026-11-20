<?php

namespace craftquest\featureflags\console;

use CraftCms\Cms\Console\CraftCommand;
use craftquest\featureflags\enums\FlagType;
use craftquest\featureflags\FeatureFlags;
use craftquest\featureflags\models\Flag;
use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function CraftCms\Cms\t;

#[Aliases(['feature-flags/flags/create'])]
#[Description('Creates a feature flag.')]
#[Signature('craft:feature-flags:flags:create
    {handle : The flag handle (lowercase letters, numbers, and hyphens)}
    {--name= : Human-readable name. Defaults to a title-cased version of the handle}
    {--type=release : Flag type: release, experiment, ops, or permission}
    {--enabled= : Whether the new flag starts enabled}
    {--description= : Optional description}')]
class CreateCommand extends Command
{
    use CraftCommand;
    use InteractsWithFlags;

    public function handle(): int
    {
        $handle = $this->argument('handle');
        $flagService = FeatureFlags::getInstance()->flagService;

        if ($flagService->getFlagByHandle($handle)) {
            $this->error(t('A flag with the handle "{handle}" already exists.', ['handle' => $handle], 'feature-flags'));
            return self::FAILURE;
        }

        $type = (string)$this->option('type');
        $allowedTypes = array_column(FlagType::cases(), 'value');
        if (!in_array($type, $allowedTypes, true)) {
            $this->error(t('Unknown flag type "{type}". Use one of: {types}', ['type' => $type, 'types' => implode(', ', $allowedTypes)], 'feature-flags'));
            return self::FAILURE;
        }

        $flag = new Flag();
        $flag->handle = $handle;
        $flag->name = $this->option('name') ?? ucwords(str_replace('-', ' ', $handle));
        $flag->flagType = $type;
        $flag->enabled = $this->boolOption('enabled');
        $flag->description = $this->option('description');

        if (!$flagService->saveFlag($flag)) {
            $this->printModelErrors($flag);
            return self::FAILURE;
        }

        $this->info(t('Flag "{name}" created.', ['name' => $flag->name], 'feature-flags'));
        return self::SUCCESS;
    }
}
