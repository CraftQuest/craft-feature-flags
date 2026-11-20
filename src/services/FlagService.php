<?php

namespace craftquest\featureflags\services;

use function CraftCms\Cms\{currentUser, t};
use craft\base\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use craftquest\featureflags\FeatureFlags;
use craftquest\featureflags\events\FlagEvent;
use craftquest\featureflags\events\RegisterRuleTypesEvent;
use craftquest\featureflags\models\Flag;
use craftquest\featureflags\models\Rule;
use craftquest\featureflags\records\AuditRecord;
use craft\commerce\elements\Subscription;
use craftquest\featureflags\records\FlagRecord;
use craftquest\featureflags\records\FlagSiteRecord;
use craftquest\featureflags\records\RuleRecord;

class FlagService extends Component
{
    /** Fired before a flag is saved. Set `$event->isValid = false` to cancel. */
    public const EVENT_BEFORE_SAVE_FLAG = 'beforeSaveFlag';
    /** Fired after a flag is saved and its cache entry invalidated. */
    public const EVENT_AFTER_SAVE_FLAG = 'afterSaveFlag';
    /** Fired after a flag's enabled state is toggled. Not fired by a full save. */
    public const EVENT_AFTER_TOGGLE_FLAG = 'afterToggleFlag';
    /** Fired after a flag is deleted. */
    public const EVENT_AFTER_DELETE_FLAG = 'afterDeleteFlag';

    public const CACHE_VERSION = 5;

    private ?array $ruleTypesCache = null;

    /** Cache key for a given flag, versioned by CACHE_VERSION. */
    public static function cacheKey(string $flagHandle): string
    {
        return 'featureflags:v' . self::CACHE_VERSION . ':' . $flagHandle;
    }

    /**
     * @return array[]
     */
    public function getRuleTypes(): array
    {
        if ($this->ruleTypesCache !== null) {
            return $this->ruleTypesCache;
        }

        $ruleTypes = [
            ['label' => t('User ID', [], 'feature-flags'), 'value' => 'user'],
            ['label' => t('User Group', [], 'feature-flags'), 'value' => 'userGroup'],
            ['label' => t('Environment', [], 'feature-flags'), 'value' => 'environment'],
        ];

        if (class_exists(Subscription::class)) {
            $ruleTypes[] = ['label' => t('Subscription Plan', [], 'feature-flags'), 'value' => 'subscriptionPlan'];
        }

        $event = new RegisterRuleTypesEvent();
        $event->ruleTypes = $ruleTypes;
        FeatureFlags::getInstance()->trigger(FeatureFlags::EVENT_REGISTER_RULE_TYPES, $event);

        $this->ruleTypesCache = $event->ruleTypes;

        return $this->ruleTypesCache;
    }

    /**
     * @return string[]
     */
    public function getRuleTypeValues(): array
    {
        return array_column($this->getRuleTypes(), 'value');
    }

    /**
     * @return Flag[]
     */
    public function getAllFlags(): array
    {
        return FlagRecord::query()
            ->with(['rules', 'flagSites'])
            ->orderBy('name')
            ->get()
            ->map(fn(FlagRecord $record) => $this->populateFlag($record))
            ->all();
    }

    public function getFlagById(int $id): ?Flag
    {
        $record = FlagRecord::query()->with(['rules', 'flagSites'])->find($id);

        return $record ? $this->populateFlag($record) : null;
    }

    /**
     * @return Flag[]
     */
    public function getExpiredFlags(): array
    {
        return FlagRecord::query()
            ->whereNotNull('expiresAt')
            ->where('expiresAt', '<', now('UTC')->format('Y-m-d H:i:s'))
            ->with(['rules', 'flagSites'])
            ->get()
            ->map(fn(FlagRecord $r) => $this->populateFlag($r))
            ->all();
    }

    public function getFlagByHandle(string $handle): ?Flag
    {
        $record = FlagRecord::query()->with(['rules', 'flagSites'])->where('handle', $handle)->first();

        return $record ? $this->populateFlag($record) : null;
    }

    /**
     * Generate a kebab-case handle from a human-readable name.
     */
    public function generateHandle(string $name): string
    {
        $handle = strtolower($name);
        $handle = preg_replace('/[^a-z0-9]+/', '-', $handle);
        $handle = trim($handle, '-');
        $handle = ltrim($handle, '0123456789-');
        if ($handle === '') {
            $handle = 'flag';
        }

        return $handle;
    }

    public function saveFlag(Flag $flag): bool
    {
        // Auto-generate a handle from the name for new flags only. An existing flag with a
        // blank handle fails validation instead: silently generating a new handle would
        // rename the flag and turn it off everywhere the old handle is checked.
        if (!$flag->id && empty($flag->handle) && !empty($flag->name)) {
            $flag->handle = $this->generateHandle($flag->name);
        }

        if (!$flag->validate()) {
            return false;
        }

        $isNew = !$flag->id;
        $oldHandle = null;

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_FLAG)) {
            $event = new FlagEvent(['flag' => $flag, 'isNew' => $isNew]);
            $this->trigger(self::EVENT_BEFORE_SAVE_FLAG, $event);
            if (!$event->isValid) {
                return false;
            }
        }

        try {
            $oldHandle = DB::transaction(function () use ($flag, $isNew): ?string {
                $oldHandle = null;

                if ($isNew) {
                    $record = new FlagRecord();
                } else {
                    $record = FlagRecord::query()->find($flag->id)
                        ?? throw new \InvalidArgumentException("Flag not found: {$flag->id}");
                    $oldHandle = $record->handle;
                }

                $record->name = $flag->name;
                $record->handle = $flag->handle;
                $record->description = $flag->description;
                $record->enabled = $flag->enabled;
                $record->rolloutPercentage = $flag->rolloutPercentage;
                $record->rolloutStrategy = $flag->rolloutStrategy;
                $record->flagType = $flag->flagType;
                $record->startsAt = $flag->startsAt;
                $record->expiresAt = $flag->expiresAt;
                $record->save();

                $flag->id = $record->id;
                $flag->uid = $record->uid;

                RuleRecord::query()->where('flagId', $flag->id)->delete();
                foreach ($flag->rules as $rule) {
                    $ruleRecord = RuleRecord::query()->create([
                        'flagId' => $flag->id,
                        'ruleType' => $rule->ruleType,
                        'ruleValue' => $rule->ruleValue,
                    ]);
                    $rule->id = $ruleRecord->id;
                    $rule->flagId = $flag->id;
                }

                FlagSiteRecord::query()->where('flagId', $flag->id)->delete();
                foreach ($flag->siteSettings ?? [] as $siteId => $enabled) {
                    FlagSiteRecord::query()->create([
                        'flagId' => $flag->id,
                        'siteId' => (int)$siteId,
                        'enabled' => (bool)$enabled,
                    ]);
                }

                return $oldHandle;
            });
        } catch (\Throwable $e) {
            Log::error('Failed to save flag: ' . $e->getMessage());
            throw $e;
        }

        $details = [
            'name' => $flag->name,
            'handle' => $flag->handle,
            'enabled' => $flag->enabled,
            'rolloutPercentage' => $flag->rolloutPercentage,
            'rolloutStrategy' => $flag->rolloutStrategy,
            'rulesCount' => count($flag->rules),
            'sitesCount' => is_array($flag->siteSettings) ? count($flag->siteSettings) : 0,
        ];
        if ($oldHandle && $oldHandle !== $flag->handle) {
            $details['previousHandle'] = $oldHandle;
        }
        $this->logAudit($flag->id, $isNew ? 'created' : 'updated', $details);

        $this->invalidateCache($flag->handle);
        if ($oldHandle && $oldHandle !== $flag->handle) {
            $this->invalidateCache($oldHandle);
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_FLAG)) {
            $this->trigger(self::EVENT_AFTER_SAVE_FLAG, new FlagEvent(['flag' => $flag, 'isNew' => $isNew]));
        }

        return true;
    }

    public function deleteFlag(int $id): bool
    {
        $flag = $this->getFlagById($id);
        if (!$flag) {
            return false;
        }

        try {
            // Rules and site rows cascade; audit rows keep their history with flagId set to null.
            FlagRecord::query()->whereKey($id)->delete();
        } catch (\Throwable $e) {
            Log::error('Failed to delete flag: ' . $e->getMessage());
            throw $e;
        }

        // flagId is SET NULL on existing audit rows; log the deletion with null flagId.
        $this->logAudit(null, 'deleted', ['name' => $flag->name, 'handle' => $flag->handle]);
        $this->invalidateCache($flag->handle);

        if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_FLAG)) {
            $this->trigger(self::EVENT_AFTER_DELETE_FLAG, new FlagEvent(['flag' => $flag]));
        }

        return true;
    }

    public function toggleFlag(int $id, bool $enabled): bool
    {
        $record = FlagRecord::query()->find($id);
        if (!$record) {
            return false;
        }

        try {
            $record->enabled = $enabled;
            $record->save();
        } catch (\Throwable $e) {
            Log::error('Failed to toggle flag: ' . $e->getMessage());
            throw $e;
        }

        $this->logAudit($id, $enabled ? 'enabled' : 'disabled', [
            'name' => $record->name,
            'handle' => $record->handle,
        ]);

        $this->invalidateCache($record->handle);

        if ($this->hasEventHandlers(self::EVENT_AFTER_TOGGLE_FLAG)) {
            $flag = $this->getFlagById($id);
            if ($flag) {
                $this->trigger(self::EVENT_AFTER_TOGGLE_FLAG, new FlagEvent(['flag' => $flag]));
            }
        }

        return true;
    }

    /**
     * @return array[]
     */
    public function getAuditLog(?int $flagId = null, int $limit = 200, int $offset = 0): array
    {
        return DB::table(AuditRecord::TABLE . ' as a')
            ->select(['a.id', 'a.flagId', 'a.userId', 'a.action', 'a.details', 'a.dateCreated', 'f.name as flagName', 'u.username'])
            ->leftJoin(FlagRecord::TABLE . ' as f', 'a.flagId', '=', 'f.id')
            ->leftJoin('users as u', 'a.userId', '=', 'u.id')
            ->when($flagId !== null, fn($query) => $query->where('a.flagId', $flagId))
            ->orderByDesc('a.dateCreated')
            ->orderByDesc('a.id')
            ->limit($limit)
            ->offset($offset)
            ->get()
            ->map(fn(object $row): array => (array)$row)
            ->all();
    }

    private function populateFlag(FlagRecord $record): Flag
    {
        $flag = new Flag();
        $flag->id = $record->id;
        $flag->name = $record->name;
        $flag->handle = $record->handle;
        $flag->description = $record->description;
        $flag->enabled = $record->enabled;
        $flag->rolloutPercentage = $record->rolloutPercentage;
        $flag->rolloutStrategy = $record->rolloutStrategy ?? 'all';
        $flag->flagType = $record->flagType;
        // Eloquent's date casts read the stored UTC values into the system time zone.
        $flag->startsAt = $record->startsAt;
        $flag->expiresAt = $record->expiresAt;
        $flag->uid = $record->uid;
        $flag->dateCreated = $record->dateCreated;
        $flag->dateUpdated = $record->dateUpdated;

        $flag->rules = $record->rules->map(function (RuleRecord $ruleRecord) {
            $rule = new Rule();
            $rule->id = $ruleRecord->id;
            $rule->flagId = $ruleRecord->flagId;
            $rule->ruleType = $ruleRecord->ruleType;
            $rule->ruleValue = $ruleRecord->ruleValue;
            return $rule;
        })->all();

        $siteSettings = [];
        foreach ($record->flagSites ?? [] as $flagSite) {
            $siteSettings[(int)$flagSite->siteId] = (bool)$flagSite->enabled;
        }
        $flag->siteSettings = $siteSettings ?: null;

        return $flag;
    }

    private function logAudit(?int $flagId, string $action, array $details = []): void
    {
        /** @var \craftquest\featureflags\models\Settings $settings */
        $settings = FeatureFlags::getInstance()->getSettings();

        if (!$settings->enableAuditLog) {
            return;
        }

        try {
            AuditRecord::query()->create([
                'flagId' => $flagId,
                'userId' => currentUser()?->getCraftUserId(),
                'action' => $action,
                'details' => json_encode($details),
            ]);
        } catch (\Throwable $e) {
            // The audit log is best-effort: never fail the change it records.
            Log::error('Failed to save audit record: ' . $e->getMessage());
        }
    }

    private function invalidateCache(string $flagHandle): void
    {
        Cache::forget(self::cacheKey($flagHandle));

        // Results already evaluated in this process (a queue worker, a daemon, or the request
        // that made the change) would otherwise keep returning the old answer.
        FeatureFlags::getInstance()->evaluationService->clearEvaluationCache();
    }
}
