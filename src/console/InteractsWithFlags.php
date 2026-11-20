<?php

namespace craftquest\featureflags\console;

use craftquest\featureflags\FeatureFlags;
use craftquest\featureflags\models\Flag;

use function CraftCms\Cms\t;

/**
 * Shared helpers for the Feature Flags commands.
 */
trait InteractsWithFlags
{
    /** Finds a flag by handle, printing an error when there isn't one. */
    protected function findFlag(string $handle): ?Flag
    {
        $flag = FeatureFlags::getInstance()->flagService->getFlagByHandle($handle);

        if (!$flag) {
            $this->error(t('Flag not found', [], 'feature-flags') . ": $handle");
        }

        return $flag;
    }

    protected function printModelErrors(Flag $flag): void
    {
        $this->error(t('Couldn’t save flag.', [], 'feature-flags'));
        foreach ($flag->errors()->getMessages() as $attribute => $messages) {
            foreach ($messages as $message) {
                $this->error("  {$attribute}: {$message}");
            }
        }
    }

    /**
     * Reads a boolean option that accepts both `--name` and the Craft 5 form `--name=1`/`--name=0`.
     * Declare the option as `{--name=}` in the signature.
     */
    protected function boolOption(string $name): bool
    {
        if (!$this->input->hasParameterOption("--$name")) {
            return false;
        }

        $value = $this->option($name);

        return $value === null || filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
