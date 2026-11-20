<?php

namespace craftquest\featureflags;

use CraftCms\Cms\Cp\Data\NavItem;
use CraftCms\Cms\Mcp\CapabilityRegistry;
use CraftCms\Cms\Mcp\Events\CollectingAdminInstructions;
use CraftCms\Cms\Mcp\Events\CollectingServerInstructions;
use CraftCms\Cms\Mcp\Events\DiscoveringCapability;
use CraftCms\Cms\Plugin\Plugin;
use CraftCms\Cms\Plugin\PluginSettings;
use CraftCms\Cms\Twig\Variables\CraftVariable;
use CraftCms\Cms\User\Data\Permission;
use craftquest\featureflags\console;
use craftquest\featureflags\mcp\FlagCapabilities;
use craftquest\featureflags\models\Settings;
use craftquest\featureflags\services\EvaluationService;
use craftquest\featureflags\services\FlagService;
use craftquest\featureflags\variables\FeatureFlagVariable;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Mcp\Capability\Registry\ToolReference;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Feature Flags for Craft CMS 6.
 *
 * Services are resolved from the container. `FeatureFlags::getInstance()->flagService` and
 * `->evaluationService` keep working, so templates, modules, and other plugins written for
 * 1.x don't need to change how they reach them.
 *
 * @author CraftQuest
 * @since 1.0.0
 *
 * @method Settings getSettings()
 */
final class FeatureFlags extends Plugin
{
    public string $schemaVersion = '1.2.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;
    public bool $hasReadOnlyCpSettings = true;

    /** @var array<class-string> Artisan commands; each keeps its Craft 5 name as an alias. */
    protected array $commands = [
        console\ListCommand::class,
        console\InfoCommand::class,
        console\EnableCommand::class,
        console\DisableCommand::class,
        console\DeleteCommand::class,
        console\CreateCommand::class,
        console\SetRolloutCommand::class,
        console\CleanupExpiredCommand::class,
    ];

    public FlagService $flagService {
        get => $this->app->make(FlagService::class);
    }

    public EvaluationService $evaluationService {
        get => $this->app->make(EvaluationService::class);
    }

    protected static function createSettings(): ?PluginSettings
    {
        return new Settings();
    }

    public function register(): void
    {
        $this->app->singleton(FlagService::class);

        // Evaluation results and the anonymous visitor ID are per request. Scoped instances
        // are discarded between requests and before every queue job.
        $this->app->scoped(EvaluationService::class);
    }

    public function boot(): void
    {
        // `craft.featureFlags` in Twig. Macro closures are bound to the CraftVariable, so resolve
        // through the app() helper rather than $this.
        CraftVariable::macro('featureFlags', fn() => app(FeatureFlagVariable::class));

        // Jobs run in the request that dispatched them (e.g. the sync queue) share its
        // scoped instance, so clear evaluation state explicitly too.
        Event::listen(JobProcessing::class, fn() => $this->evaluationService->clearRequestCache());

        $this->registerMcp();
    }

    protected function getPermissions(): array
    {
        return [
            new Permission(
                key: 'featureFlags:view',
                label: t('View feature flags', [], 'feature-flags'),
                nested: collect([
                    new Permission(
                        key: 'featureFlags:toggle',
                        label: t('Toggle feature flags on and off', [], 'feature-flags'),
                        info: t('Flip the master switch from the flag list without editing rules, rollout, or sites.', [], 'feature-flags'),
                    ),
                    new Permission(
                        key: 'featureFlags:manage',
                        label: t('Manage feature flags', [], 'feature-flags'),
                    ),
                ]),
            ),
        ];
    }

    public function getCpNavItem(): NavItem
    {
        $subnav = [
            new NavItem()->label(t('All Flags', [], 'feature-flags'))->href('feature-flags'),
            new NavItem()->label(t('Audit Log', [], 'feature-flags'))->href('feature-flags/audit'),
        ];

        if (currentUser()?->isAdmin()) {
            $subnav[] = new NavItem()->label(t('Settings', [], 'feature-flags'))->href('settings/plugins/feature-flags');
        }

        return parent::getCpNavItem()
            ->label($this->getSettings()->pluginName ?: t('Feature Flags', [], 'feature-flags'))
            ->href('feature-flags')
            ->subnav($subnav);
    }

    protected function cpNavIconPath(): ?string
    {
        return __DIR__ . '/icon-mask.svg';
    }

    private function registerMcp(): void
    {
        $this->app->make(CapabilityRegistry::class)->register(FlagCapabilities::class);

        // Toggling is allowed with either the toggle or the manage permission, which
        // #[RequiresPermission] can't express (it requires all listed permissions).
        // Hide the toggle tool from users who have neither; the tool re-checks on call.
        Event::listen(
            DiscoveringCapability::class,
            function (DiscoveringCapability $event) {
                $reference = $event->reference;
                if (
                    $reference instanceof ToolReference
                    && $reference->tool->name === FlagCapabilities::TOGGLE_TOOL
                    && !FlagCapabilities::canToggle($event->user)
                ) {
                    $event->allowed = false;
                }
            }
        );

        // Short guidance sent to every connecting client that can see flags.
        Event::listen(
            CollectingServerInstructions::class,
            function (CollectingServerInstructions $event) {
                if (currentUser()?->can('featureFlags:view')) {
                    $event->instructions[] = 'Feature Flags: use the feature_flags_* tools to inspect, evaluate, and change runtime feature flags. To disable a feature quickly, turn its flag off with feature_flags_toggle rather than deleting it.';
                }
            }
        );

        // Fuller guidance returned by Craft's info_get tool.
        Event::listen(
            CollectingAdminInstructions::class,
            function (CollectingAdminInstructions $event) {
                if (!currentUser()?->can('featureFlags:view')) {
                    return;
                }

                $event->instructions[] = <<<'MARKDOWN'
                    Feature Flags plugin:
                    - Flags are identified by kebab-case handles, which templates check with `craft.featureFlags.isEnabled('handle')`. An unknown handle evaluates to false, so a typo or a renamed handle silently turns a feature off.
                    - A flag is on only when its master switch is on, it is within its optional startsAt/expiresAt window, it applies to the current site (when scoped to specific sites), and its targeting rules and percentage rollout allow it. With no rules and no rollout, an enabled flag is on for everyone.
                    - Rollout strategy "all": a matching rule turns the flag on; otherwise the rollout decides. Strategy "rule": only rule-matched users are eligible, and the rollout filters within them.
                    - Use feature_flags_evaluate to answer "is this on for user X?" and to see why. It never changes data.
                    - Prefer feature_flags_toggle to switch a feature off; feature_flags_delete is permanent. feature_flags_update with rules replaces the whole rule list, so read the flag with feature_flags_get first.
                    - Every change is recorded in the audit log under the signed-in user (feature_flags_audit_log).
                    MARKDOWN;
            }
        );
    }
}
