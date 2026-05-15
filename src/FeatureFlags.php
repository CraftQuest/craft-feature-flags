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

    public string $schemaVersion = '1.0.0';
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
