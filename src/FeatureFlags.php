<?php

namespace craftquest\featureflags;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\helpers\UrlHelper;
use craftquest\featureflags\mcp\FlagCapabilities;
use craftquest\featureflags\models\Settings;
use craftquest\featureflags\services\EvaluationService;
use craftquest\featureflags\services\FlagService;
use craftquest\featureflags\variables\FeatureFlagVariable;
use yii\base\Event;

/**
 * Feature Flags Plugin
 *
 * @author CraftQuest
 * @since 1.0.0
 *
 * @method Settings getSettings()
 * @property-read FlagService $flagService
 * @property-read EvaluationService $evaluationService
 */
class FeatureFlags extends Plugin
{
    /**
     * Fired on the plugin instance when building the rule type list in the CP.
     * Attach to this event to register custom rule types.
     */
    public const EVENT_REGISTER_RULE_TYPES = 'registerRuleTypes';

    public string $schemaVersion = '1.2.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'flagService' => FlagService::class,
                'evaluationService' => EvaluationService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (Craft::$app instanceof \craft\console\Application) {
            $this->controllerNamespace = 'craftquest\featureflags\console\controllers';
        }

        $this->registerCpRoutes();
        $this->registerVariables();
        $this->registerPermissions();
        $this->registerQueueHooks();

        // Craft's native MCP server arrived in Craft 5.12. On older versions there is
        // nothing to register, and FlagCapabilities (which uses the MCP SDK) is never loaded.
        if (class_exists(\craft\services\Mcp::class)) {
            $this->registerMcp();
        }
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        $settings = $this->getSettings();
        $item['label'] = $settings->pluginName ?: Craft::t('feature-flags', 'Feature Flags');
        $item['url'] = 'feature-flags';

        $item['subnav'] = [
            'flags' => [
                'label' => Craft::t('feature-flags', 'All Flags'),
                'url' => 'feature-flags',
            ],
            'audit' => [
                'label' => Craft::t('feature-flags', 'Audit Log'),
                'url' => 'feature-flags/audit',
            ],
        ];

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('feature-flags', 'Settings'),
                'url' => 'feature-flags/settings',
            ];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('feature-flags/settings'));
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('feature-flags/_settings', [
            'settings' => $this->getSettings(),
        ]);
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['feature-flags'] = 'feature-flags/flags/index';
                $event->rules['feature-flags/new'] = 'feature-flags/flags/edit';
                $event->rules['feature-flags/<flagId:\\d+>'] = 'feature-flags/flags/edit';
                $event->rules['feature-flags/audit'] = 'feature-flags/flags/audit';
                $event->rules['feature-flags/audit/<flagId:\\d+>'] = 'feature-flags/flags/audit';
                $event->rules['feature-flags/settings'] = 'feature-flags/flags/settings';
            }
        );
    }

    private function registerVariables(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('featureFlags', FeatureFlagVariable::class);
            }
        );
    }

    private function registerMcp(): void
    {
        Event::on(
            \craft\services\Mcp::class,
            \craft\services\Mcp::EVENT_REGISTER_CAPABILITY_TYPES,
            function (\craft\events\RegisterComponentTypesEvent $event) {
                $event->types[] = FlagCapabilities::class;
            }
        );

        // Toggling is allowed with either the toggle or the manage permission, which
        // #[RequiresPermission] can't express (it requires all listed permissions).
        // Hide the toggle tool from users who have neither; the tool re-checks on call.
        Event::on(
            \craft\services\Mcp::class,
            \craft\services\Mcp::EVENT_DISCOVERING_CAPABILITY,
            function (\craft\mcp\events\DiscoveringCapability $event) {
                $reference = $event->reference;
                if (
                    $reference instanceof \Mcp\Capability\Registry\ToolReference
                    && $reference->tool->name === FlagCapabilities::TOGGLE_TOOL
                    && !FlagCapabilities::canToggle($event->user)
                ) {
                    $event->allowed = false;
                }
            }
        );

        // Short guidance sent to every connecting client that can see flags.
        Event::on(
            \craft\mcp\AdminInstructions::class,
            \craft\mcp\AdminInstructions::EVENT_COLLECT_SERVER_INSTRUCTIONS,
            function (\craft\mcp\events\CollectingServerInstructions $event) {
                $user = Craft::$app->getUser()->getIdentity();
                if ($user && $user->can('featureFlags:view')) {
                    $event->instructions[] = 'Feature Flags: use the feature_flags_* tools to inspect, evaluate, and change runtime feature flags. To disable a feature quickly, turn its flag off with feature_flags_toggle rather than deleting it.';
                }
            }
        );

        // Fuller guidance returned by Craft's info_get tool.
        Event::on(
            \craft\mcp\AdminInstructions::class,
            \craft\mcp\AdminInstructions::EVENT_COLLECT_INSTRUCTIONS,
            function (\craft\mcp\events\CollectingAdminInstructions $event) {
                $user = Craft::$app->getUser()->getIdentity();
                if (!$user || !$user->can('featureFlags:view')) {
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

    /**
     * A queue worker is one long-lived process, so the per-request evaluation cache would
     * otherwise carry results (and a stale schedule window) from one job into the next.
     * Start every job with a clean slate; flags themselves still come from the shared cache.
     */
    private function registerQueueHooks(): void
    {
        Event::on(
            \yii\queue\Queue::class,
            \yii\queue\Queue::EVENT_BEFORE_EXEC,
            function () {
                $this->evaluationService->clearRequestCache();
            }
        );
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('feature-flags', 'Feature Flags'),
                    'permissions' => [
                        'featureFlags:view' => [
                            'label' => Craft::t('feature-flags', 'View feature flags'),
                            'nested' => [
                                'featureFlags:toggle' => [
                                    'label' => Craft::t('feature-flags', 'Toggle feature flags on and off'),
                                    'info' => Craft::t('feature-flags', 'Flip the master switch from the flag list without editing rules, rollout, or sites.'),
                                ],
                                'featureFlags:manage' => [
                                    'label' => Craft::t('feature-flags', 'Manage feature flags'),
                                ],
                            ],
                        ],
                    ],
                ];
            }
        );
    }
}
