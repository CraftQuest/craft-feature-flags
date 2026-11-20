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
use craftquest\featureflags\models\Rule;

class EvaluationService extends Component
{
    public const EVENT_EVALUATE_RULE = 'evaluateRule';

    private array $requestCache = [];
    private array $userGroupsCache = [];
    private array $userPlansCache = [];
    private ?string $anonymousVisitorId = null;

    public function isEnabled(string $handle, ?User $user = null, ?string $bucketKey = null, ?int $siteId = null): bool
    {
        if ($user === null && Craft::$app instanceof \craft\web\Application) {
            $user = Craft::$app->getUser()->getIdentity();
        }

        // Key the request cache by the requested site context. An explicit siteId is
        // kept distinct from the resolved current-site id so that, e.g., an invalid
        // explicit id can't collide with the no-site (console) bucket.
        $siteKey = $siteId !== null
            ? 's' . $siteId
            : 'c' . ($this->resolveCurrentSite(null)?->id ?? 0);

        $cacheKey = $handle . ':' . ($user?->id ?? 0) . ':' . ($bucketKey ?? '') . ':' . $siteKey;

        if (isset($this->requestCache[$cacheKey])) {
            return $this->requestCache[$cacheKey];
        }

        $result = $this->evaluate($handle, $user, $bucketKey, $siteId);
        $this->requestCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Clears everything this service caches for the current request or job: evaluation
     * results, user group/plan lookups, and the anonymous visitor ID.
     */
    public function clearRequestCache(): void
    {
        $this->clearEvaluationCache();
        $this->userGroupsCache = [];
        $this->userPlansCache = [];
        $this->anonymousVisitorId = null;
    }

    /**
     * Clears only the cached evaluation results, so the next check re-evaluates against
     * current flag data. Called whenever a flag changes in this process.
     */
    public function clearEvaluationCache(): void
    {
        $this->requestCache = [];
    }

    /** Rollout bucket (0-99) for a given input/flag pair. */
    public static function computeBucket(string $bucketInput, string $handle): int
    {
        return abs(crc32($bucketInput . ':' . $handle)) % 100;
    }

    /**
     * Whether `$now` falls outside a flag's optional schedule window.
     *
     * Pure logic with no Craft dependencies so it can be unit tested directly.
     * Both bounds are optional and independent: a flag is off before `$startsAt`
     * (if set) and off once `$expiresAt` (if set) has passed. A flag with neither
     * is never outside the window.
     */
    public static function isOutsideSchedule(?\DateTimeInterface $startsAt, ?\DateTimeInterface $expiresAt, \DateTimeInterface $now): bool
    {
        if ($startsAt !== null && $now < $startsAt) {
            return true;
        }

        if ($expiresAt !== null && $expiresAt < $now) {
            return true;
        }

        return false;
    }

    /**
     * Decides whether the per-site gate blocks a flag for a resolved site context.
     *
     * Pure logic with no Craft dependencies so it can be unit tested directly.
     *
     * @param bool $isMultiSite Whether the install has more than one site (gate is off on single-site).
     * @param int|null $resolvedSiteId The resolved site id, or null when no site could be resolved.
     * @param array<int, bool> $siteSettings Per-site enabled map (siteId => bool). Empty means "all sites";
     *                                       non-empty puts the flag in "specific sites" mode.
     * @return bool True if the flag should be gated OFF.
     */
    public static function isGatedOff(
        bool $isMultiSite,
        ?int $resolvedSiteId,
        array $siteSettings,
    ): bool {
        if (!$isMultiSite) {
            return false;
        }

        // "All sites" mode: a flag with no per-site settings applies everywhere.
        if (empty($siteSettings)) {
            return false;
        }

        // "Specific sites" mode: enabled only on sites that are explicitly turned on.
        // Unlisted sites, sites added later, and unresolvable sites (console/queue without
        // a valid siteId) are gated off — sites are an explicit opt-in.
        if ($resolvedSiteId === null) {
            return true;
        }

        return ($siteSettings[$resolvedSiteId] ?? false) !== true;
    }

    /**
     * Final enable decision once rule-matching and bucketing are known.
     *
     * Pure logic with no Craft dependencies so it can be unit tested directly.
     *
     * @param string $strategy 'all' (targeting rules and rollout are independent paths) or
     *                         'rule' (rollout percentage filters the rule-matched group).
     * @param bool $ruleMatched Whether a targeting rule matched. In 'rule' mode a flag with no
     *                          rules counts as matched (the rollout applies to everyone).
     * @param int|null $rolloutPercentage The flag's rollout percentage (null = no rollout configured).
     * @param bool $inBucket Whether the rollout bucket check passed (bucket < percentage).
     *                       Only consulted when a rollout actually applies.
     * @return bool True if the flag should be enabled.
     */
    public static function rolloutDecision(string $strategy, bool $ruleMatched, ?int $rolloutPercentage, bool $inBucket): bool
    {
        if ($strategy === 'rule') {
            if (!$ruleMatched) {
                return false;
            }
            if ($rolloutPercentage === null) {
                return true;
            }
            if ($rolloutPercentage === 0) {
                return false;
            }
            return $inBucket;
        }

        // 'all': a matching rule enables outright; otherwise the rollout (if any) decides.
        if ($ruleMatched) {
            return true;
        }
        if ($rolloutPercentage !== null && $rolloutPercentage > 0) {
            return $inBucket;
        }
        return false;
    }

    private function getAnonymousVisitorId(): ?string
    {
        if ($this->anonymousVisitorId !== null) {
            return $this->anonymousVisitorId;
        }

        if (app()->runningInConsole()) {
            return null;
        }

        /** @var \craftquest\featureflags\models\Settings $settings */
        $settings = FeatureFlags::getInstance()->getSettings();

        if ($settings->anonymousCookieTtl === 0) {
            return null;
        }

        // Craft 6 builds the HTTP response with Laravel, so the visitor cookie is read from
        // and queued on Laravel's request/response (cookies set on Yii's response are never
        // sent). Laravel's web middleware encrypts the value and attaches queued cookies.
        $cookieName = $settings->anonymousCookieName;
        $request = request();
        $visitorId = $request->cookie($cookieName);

        if (!is_string($visitorId) || $visitorId === '') {
            $visitorId = StringHelper::UUID();
            \Illuminate\Support\Facades\Cookie::queue(
                $cookieName,
                $visitorId,
                (int)ceil($settings->anonymousCookieTtl / 60), // Laravel takes minutes
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax',
            );
        }

        $this->anonymousVisitorId = $visitorId;

        return $visitorId;
    }

    /**
     * Resolves the site to evaluate against: an explicit site id if given, otherwise the
     * current site in a web request, or null when there is no site context (console/queue).
     */
    private function resolveCurrentSite(?int $siteId = null): ?\craft\models\Site
    {
        $sites = Craft::$app->getSites();

        if ($siteId !== null) {
            return $sites->getSiteById($siteId);
        }

        if (Craft::$app instanceof \craft\web\Application) {
            return $sites->getCurrentSite();
        }

        return null;
    }

    /**
     * Evaluates a flag and explains the result, without touching the request cache.
     *
     * Unlike isEnabled(), this never falls back to the logged-in user and never sets the
     * anonymous visitor cookie: pass `$user` and/or `$bucketKey` explicitly. With neither,
     * a percentage rollout has no bucket input and evaluates to off.
     *
     * @return array{enabled: bool, reason: string, bucket: int|null, matchedRule: array{type: string|null, value: string|null}|null}
     *   `reason` is one of: not_found, disabled, site_gated, not_started, expired, global_on,
     *   rule_matched, no_rule_matched, rollout_zero, rollout_in_bucket, rollout_out_of_bucket,
     *   rollout_no_bucket_input.
     */
    public function explain(string $handle, ?User $user = null, ?string $bucketKey = null, ?int $siteId = null): array
    {
        return $this->evaluateWithReason($handle, $user, $bucketKey, $siteId, false);
    }

    private function evaluate(string $handle, ?User $user, ?string $bucketKey = null, ?int $siteId = null): bool
    {
        return $this->evaluateWithReason($handle, $user, $bucketKey, $siteId, true)['enabled'];
    }

    private function evaluateWithReason(string $handle, ?User $user, ?string $bucketKey, ?int $siteId, bool $useVisitorCookie): array
    {
        $flag = $this->getFlagFromCache($handle);

        if ($flag === null) {
            // An unknown handle is indistinguishable from a disabled flag, so surface
            // likely typos in dev. No behavior change: the flag still evaluates to off.
            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                Craft::warning("Feature flag \"{$handle}\" was checked but does not exist.", __METHOD__);
            }
            return self::result(false, 'not_found');
        }

        if (!$flag->enabled) {
            return self::result(false, 'disabled');
        }

        // Per-site scope gate. A flag with no per-site settings applies to all sites.
        // A flag scoped to specific sites is enabled only on the sites explicitly turned
        // on; unlisted sites, sites added later, and unresolvable sites (console/queue, or
        // an invalid explicit siteId) are gated off — pass a valid siteId for a reliable
        // per-site answer outside web requests.
        $isMultiSite = Craft::$app->getIsMultiSite();
        $site = $isMultiSite ? $this->resolveCurrentSite($siteId) : null;
        if (self::isGatedOff($isMultiSite, $site?->id, $flag->siteSettings ?? [])) {
            return self::result(false, 'site_gated');
        }

        // Schedule window: off before the start date, off after the expiration date.
        $now = DateTimeHelper::now();
        if (self::isOutsideSchedule($flag->startsAt, $flag->expiresAt, $now)) {
            $notStarted = $flag->startsAt !== null && $now < $flag->startsAt;
            return self::result(false, $notStarted ? 'not_started' : 'expired');
        }

        // No rules and no rollout = global on.
        if (empty($flag->rules) && $flag->rolloutPercentage === null) {
            return self::result(true, 'global_on');
        }

        if ($flag->rolloutStrategy === 'rule') {
            return $this->evaluateRuleStrategy($flag, $handle, $user, $bucketKey, $useVisitorCookie);
        }

        return $this->evaluateAllStrategy($flag, $handle, $user, $bucketKey, $useVisitorCookie);
    }

    /**
     * 'all' strategy: a matching targeting rule enables the flag outright; otherwise the
     * percentage rollout (if any) decides for the remaining audience.
     */
    private function evaluateAllStrategy(Flag $flag, string $handle, ?User $user, ?string $bucketKey, bool $useVisitorCookie): array
    {
        foreach ($flag->rules as $rule) {
            if ($this->matchRule($rule, $flag, $user)) {
                return self::result(true, 'rule_matched', matchedRule: $rule);
            }
        }

        if ($flag->rolloutPercentage === null) {
            return self::result(false, 'no_rule_matched');
        }

        return $this->rolloutResult('all', false, $flag->rolloutPercentage, $handle, $user, $bucketKey, $useVisitorCookie);
    }

    /**
     * 'rule' strategy: targeting rules define the audience (no rules = everyone), then the
     * percentage rollout filters within that audience.
     */
    private function evaluateRuleStrategy(Flag $flag, string $handle, ?User $user, ?string $bucketKey, bool $useVisitorCookie): array
    {
        $ruleMatched = empty($flag->rules);
        $matchedRule = null;

        foreach ($flag->rules as $rule) {
            if ($this->matchRule($rule, $flag, $user)) {
                $ruleMatched = true;
                $matchedRule = $rule;
                break;
            }
        }

        if (!$ruleMatched) {
            return self::result(false, 'no_rule_matched');
        }

        if ($flag->rolloutPercentage === null) {
            return self::result(true, 'rule_matched', matchedRule: $matchedRule);
        }

        return $this->rolloutResult('rule', true, $flag->rolloutPercentage, $handle, $user, $bucketKey, $useVisitorCookie, $matchedRule);
    }

    /**
     * Applies the percentage rollout and builds the explained result. The final decision
     * always goes through rolloutDecision() so isEnabled() and explain() can't diverge.
     */
    private function rolloutResult(
        string $strategy,
        bool $ruleMatched,
        int $rolloutPercentage,
        string $handle,
        ?User $user,
        ?string $bucketKey,
        bool $useVisitorCookie,
        ?Rule $matchedRule = null,
    ): array {
        $bucket = null;

        if ($rolloutPercentage > 0) {
            $bucketInput = $this->resolveBucketInput($user, $bucketKey, $useVisitorCookie);
            if ($bucketInput !== null) {
                $bucket = self::computeBucket($bucketInput, $handle);
            }
        }

        $inBucket = $bucket !== null && $bucket < $rolloutPercentage;
        $enabled = self::rolloutDecision($strategy, $ruleMatched, $rolloutPercentage, $inBucket);

        $reason = match (true) {
            $rolloutPercentage === 0 => 'rollout_zero',
            $bucket === null => 'rollout_no_bucket_input',
            $inBucket => 'rollout_in_bucket',
            default => 'rollout_out_of_bucket',
        };

        return self::result($enabled, $reason, $bucket, $matchedRule);
    }

    private static function result(bool $enabled, string $reason, ?int $bucket = null, ?Rule $matchedRule = null): array
    {
        return [
            'enabled' => $enabled,
            'reason' => $reason,
            'bucket' => $bucket,
            'matchedRule' => $matchedRule ? ['type' => $matchedRule->ruleType, 'value' => $matchedRule->ruleValue] : null,
        ];
    }

    private function matchRule(Rule $rule, Flag $flag, ?User $user): bool
    {
        switch ($rule->ruleType) {
            case 'environment':
                return $this->matchesEnvironment($rule->ruleValue);

            case 'user':
                return $user !== null && (string)$user->id === $rule->ruleValue;

            case 'userGroup':
                return $user !== null && $this->userInGroup($user, $rule->ruleValue);

            case 'subscriptionPlan':
                return $user !== null && $this->userOnPlan($user, $rule->ruleValue);

            default:
                if ($this->hasEventHandlers(self::EVENT_EVALUATE_RULE)) {
                    $event = new EvaluateRuleEvent();
                    $event->flag = $flag;
                    $event->rule = $rule;
                    $event->user = $user;

                    $this->trigger(self::EVENT_EVALUATE_RULE, $event);

                    return $event->handled && $event->matched;
                }
                return false;
        }
    }

    /**
     * The value a visitor is bucketed by: an explicit bucket key, else the user ID, else
     * (in web requests, when allowed) the anonymous visitor cookie. Null when there is none.
     */
    private function resolveBucketInput(?User $user, ?string $bucketKey, bool $useVisitorCookie): ?string
    {
        // An empty bucket key (e.g. `account.id ?? ''` in Twig) means "no key", so a logged-in
        // user still falls back to their user ID rather than the anonymous visitor cookie.
        if ($bucketKey === '') {
            $bucketKey = null;
        }

        $bucketInput = $bucketKey ?? ($user ? (string)$user->id : null);
        if ($bucketInput === null && $useVisitorCookie) {
            $bucketInput = $this->getAnonymousVisitorId();
        }

        return ($bucketInput !== null && $bucketInput !== '') ? $bucketInput : null;
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
