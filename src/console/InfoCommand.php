<?php

namespace craftquest\featureflags\console;

use CraftCms\Cms\Console\CraftCommand;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Console\Attributes\Aliases;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function CraftCms\Cms\t;

#[Aliases(['feature-flags/flags/info'])]
#[Description('Displays detailed information about a flag.')]
#[Signature('craft:feature-flags:flags:info {handle : The flag handle}')]
class InfoCommand extends Command
{
    use CraftCommand;
    use InteractsWithFlags;

    public function handle(): int
    {
        if (!$flag = $this->findFlag($this->argument('handle'))) {
            return self::FAILURE;
        }

        $label = fn(string $text) => t($text, [], 'feature-flags');
        $date = fn(?\DateTimeInterface $d, string $format = 'Y-m-d H:i:s') => $d?->format($format) ?? '—';

        $rows = [
            'Name' => $flag->name,
            'Handle' => $flag->handle,
            'Description' => $flag->description ?: '—',
            'Type' => $flag->flagType,
            'Enabled' => $flag->enabled ? $label('Yes') : $label('No'),
            'Rollout Percentage' => $flag->rolloutPercentage !== null ? $flag->rolloutPercentage . '%' : '—',
            'Rollout Strategy' => $flag->rolloutStrategy,
        ];

        if (Sites::isMultiSite()) {
            $enabledSites = [];
            foreach ($flag->siteSettings ?? [] as $siteId => $isEnabled) {
                if ($isEnabled) {
                    $enabledSites[] = Sites::getSiteById((int)$siteId)?->handle ?? "#{$siteId}";
                }
            }
            $rows['Sites'] = empty($flag->siteSettings)
                ? $label('All sites')
                : ($enabledSites ? implode(', ', $enabledSites) : $label('None'));
        }

        $rows += [
            'Starts At' => $date($flag->startsAt),
            'Expires At' => $date($flag->expiresAt),
            'Date Created' => $date($flag->dateCreated),
            'Date Updated' => $date($flag->dateUpdated),
        ];

        $this->newLine();
        foreach ($rows as $name => $value) {
            $this->line('<options=bold>' . $label($name) . ':</> ' . $value);
        }
        $this->newLine();

        if (empty($flag->rules)) {
            $this->line($label('No targeting rules.'));
        } else {
            $this->line('<options=bold>' . $label('Targeting Rules') . ':</>');
            $this->table(
                [$label('Rule Type'), $label('Value')],
                array_map(fn($rule) => [$rule->ruleType, $rule->ruleValue], $flag->rules),
            );
        }

        return self::SUCCESS;
    }
}
