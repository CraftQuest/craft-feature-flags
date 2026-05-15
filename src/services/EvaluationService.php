<?php

namespace craftquest\featureflags\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Subscription;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use craftquest\featureflags\FeatureFlags;
use craftquest\featureflags\events\EvaluateRuleEvent;
use craftquest\featureflags\models\Flag;

class EvaluationService extends Component
{
    public const EVENT_EVALUATE_RULE = 'evaluateRule';

    private array $requestCache = [];
    private array $userGroupsCache = [];
    private array $userPlansCache = [];
    private ?string $anonymousVisitorId = null;

    public function isEnabled(string $handle, ?User $user = null, ?string $bucketKey = null): bool
    {
        if ($user === null && Craft::$app instanceof \craft\web\Application) {
            $user = Craft::$app->getUser()->getIdentity();
        }

        $cacheKey = $handle . ':' . ($user?->id ?? 0) . ':' . ($bucketKey ?? '');

        if (isset($this->requestCache[$cacheKey])) {
            return $this->requestCache[$cacheKey];
        }

        $result = $this->evaluate($handle, $user, $bucketKey);
        $this->requestCache[$cacheKey] = $result;

        return $result;
    }

    public function clearRequestCache(): void
    {
        $this->requestCache = [];
        $this->userGroupsCache = [];
        $this->userPlansCache = [];
        $this->anonymousVisitorId = null;
    }

    /** Rollout bucket (0-99) for a given input/flag pair. */
    public static function computeBucket(string $bucketInput, string $handle): int
    {
        return abs(crc32($bucketInput . ':' . $handle)) % 100;
    }

    private function getAnonymousVisitorId(): ?string
    {
        if ($this->anonymousVisitorId !== null) {
            return $this->anonymousVisitorId;
        }

        if (!Craft::$app instanceof \craft\web\Application) {
            return null;
        }

        /** @var \craftquest\featureflags\models\Settings $settings */
        $settings = FeatureFlags::getInstance()->getSettings();

        if ($settings->anonymousCookieTtl === 0) {
            return null;
        }

        $cookieName = $settings->anonymousCookieName;
        $request = Craft::$app->getRequest();
        $visitorId = $request->getCookies()->getValue($cookieName);

        if ($visitorId === null || $visitorId === '') {
            $visitorId = StringHelper::UUID();
            $cookie = new \yii\web\Cookie([
                'name' => $cookieName,
                'value' => $visitorId,
                'expire' => time() + $settings->anonymousCookieTtl,
                'path' => '/',
                'httpOnly' => true,
                'secure' => Craft::$app->getRequest()->getIsSecureConnection(),
                'sameSite' => \yii\web\Cookie::SAME_SITE_LAX,
            ]);
            Craft::$app->getResponse()->getCookies()->add($cookie);
        }

        $this->anonymousVisitorId = $visitorId;

        return $visitorId;
    }

    private function evaluate(string $handle, ?User $user, ?string $bucketKey = null): bool
    {
        $flag = $this->getFlagFromCache($handle);

        if ($flag === null) {
            return false;
        }

        if (!$flag->enabled) {
            return false;
        }

        if ($flag->expiresAt !== null && DateTimeHelper::isInThePast($flag->expiresAt)) {
            return false;
        }

        // No rules and no rollout = global on.
        if (empty($flag->rules) && $flag->rolloutPercentage === null) {
            return true;
        }

        foreach ($flag->rules as $rule) {
            switch ($rule->ruleType) {
                case 'environment':
                    if ($this->matchesEnvironment($rule->ruleValue)) {
                        return true;
                    }
                    break;

                case 'user':
                    if ($user && (string)$user->id === $rule->ruleValue) {
                        return true;
                    }
                    break;

                case 'userGroup':
                    if ($user && $this->userInGroup($user, $rule->ruleValue)) {
                        return true;
                    }
                    break;

                case 'subscriptionPlan':
                    if ($user && $this->userOnPlan($user, $rule->ruleValue)) {
                        return true;
                    }
                    break;

                default:
                    if ($this->hasEventHandlers(self::EVENT_EVALUATE_RULE)) {
                        $event = new EvaluateRuleEvent();
                        $event->flag = $flag;
                        $event->rule = $rule;
                        $event->user = $user;

                        $this->trigger(self::EVENT_EVALUATE_RULE, $event);

                        if ($event->handled && $event->matched) {
                            return true;
                        }
                    }
                    break;
            }
        }

        if ($flag->rolloutPercentage !== null && $flag->rolloutPercentage > 0) {
            $bucketInput = $bucketKey ?? ($user ? (string)$user->id : null);
            if ($bucketInput === null || $bucketInput === '') {
                $bucketInput = $this->getAnonymousVisitorId();
            }
            if ($bucketInput !== null && $bucketInput !== '') {
                if (self::computeBucket($bucketInput, $handle) < $flag->rolloutPercentage) {
                    return true;
                }
            }
        }

        return false;
    }

    private function getFlagFromCache(string $handle): ?Flag
    {
        /** @var \craftquest\featureflags\models\Settings $settings */
        $settings = FeatureFlags::getInstance()->getSettings();
        $cacheTtl = $settings->cacheTtl;

        if ($cacheTtl === 0) {
            return FeatureFlags::getInstance()->flagService->getFlagByHandle($handle);
        }

        $cacheKey = FlagService::cacheKey($handle);

        $cached = Craft::$app->getCache()->get($cacheKey);
        if ($cached instanceof Flag) {
            return $cached;
        }
        if ($cached === '') {
            return null;
        }

        $flag = FeatureFlags::getInstance()->flagService->getFlagByHandle($handle);

        if ($flag === null) {
            // Negative cache as empty string so repeat misses don't hit the DB.
            Craft::$app->getCache()->set($cacheKey, '', $cacheTtl);
            return null;
        }

        Craft::$app->getCache()->set($cacheKey, $flag, $cacheTtl);

        return $flag;
    }

    private function matchesEnvironment(string $envName): bool
    {
        $currentEnv = Craft::$app->env;
        return $currentEnv === $envName;
    }

    private function userInGroup(User $user, string $groupHandle): bool
    {
        return in_array($groupHandle, $this->getUserGroupHandles($user), true);
    }

    /**
     * @return list<string>
     */
    private function getUserGroupHandles(User $user): array
    {
        $userId = (int)$user->id;
        if (isset($this->userGroupsCache[$userId])) {
            return $this->userGroupsCache[$userId];
        }

        $handles = [];
        foreach ($user->getGroups() as $group) {
            $handles[] = $group->handle;
        }

        return $this->userGroupsCache[$userId] = $handles;
    }

    private function userOnPlan(User $user, string $planHandle): bool
    {
        if (!class_exists(Subscription::class)) {
            return false;
        }

        return in_array($planHandle, $this->getUserPlanHandles($user), true);
    }

    /**
     * @return list<string>
     */
    private function getUserPlanHandles(User $user): array
    {
        $userId = (int)$user->id;
        if (isset($this->userPlansCache[$userId])) {
            return $this->userPlansCache[$userId];
        }

        $handles = [];
        $subscriptions = Subscription::find()
            ->userId($userId)
            ->status('active')
            ->all();

        foreach ($subscriptions as $subscription) {
            $plan = $subscription->getPlan();
            if ($plan) {
                $handles[] = $plan->handle;
            }
        }

        return $this->userPlansCache[$userId] = $handles;
    }
}
