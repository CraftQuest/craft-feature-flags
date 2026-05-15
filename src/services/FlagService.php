<?php

namespace craftquest\featureflags\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craftquest\featureflags\FeatureFlags;
use craftquest\featureflags\events\RegisterRuleTypesEvent;
use craftquest\featureflags\models\Flag;
use craftquest\featureflags\models\Rule;
use craftquest\featureflags\records\AuditRecord;
use craftquest\featureflags\records\FlagRecord;
use craftquest\featureflags\records\RuleRecord;

class FlagService extends Component
{
    public const CACHE_VERSION = 1;

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
            ['label' => Craft::t('feature-flags', 'User ID'), 'value' => 'user'],
            ['label' => Craft::t('feature-flags', 'User Group'), 'value' => 'userGroup'],
            ['label' => Craft::t('feature-flags', 'Environment'), 'value' => 'environment'],
        ];

        if (class_exists(Subscription::class)) {
            $ruleTypes[] = ['label' => Craft::t('feature-flags', 'Subscription Plan'), 'value' => 'subscriptionPlan'];
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
        $records = FlagRecord::find()->with('rules')->orderBy('name')->all();

        return array_map(fn(FlagRecord $record) => $this->populateFlag($record), $records);
    }

    public function getFlagById(int $id): ?Flag
    {
        $record = FlagRecord::find()->where(['id' => $id])->with('rules')->one();

        return $record ? $this->populateFlag($record) : null;
    }

    /**
     * @return Flag[]
     */
    public function getExpiredFlags(): array
    {
        $now = DateTimeHelper::currentUTCDateTime();
        $records = FlagRecord::find()
            ->where(['not', ['expiresAt' => null]])
            ->andWhere(['<', 'expiresAt', $now->format('Y-m-d H:i:s')])
            ->with('rules')
            ->all();

        return array_map(fn(FlagRecord $r) => $this->populateFlag($r), $records);
    }

    public function getFlagByHandle(string $handle): ?Flag
    {
        $record = FlagRecord::find()->where(['handle' => $handle])->with('rules')->one();

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
        // Auto-generate handle from name if blank
        if (empty($flag->handle) && !empty($flag->name)) {
            $flag->handle = $this->generateHandle($flag->name);
        }

        if (!$flag->validate()) {
            return false;
        }

        $isNew = !$flag->id;
        $oldHandle = null;

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            if ($isNew) {
                $record = new FlagRecord();
            } else {
                $record = FlagRecord::findOne($flag->id);
                if (!$record) {
                    throw new \yii\base\InvalidArgumentException("Flag not found: {$flag->id}");
                }
                $oldHandle = $record->handle;
            }

            $record->name = $flag->name;
            $record->handle = $flag->handle;
            $record->description = $flag->description;
            $record->enabled = $flag->enabled;
            $record->rolloutPercentage = $flag->rolloutPercentage;
            $record->flagType = $flag->flagType;
            $record->expiresAt = Db::prepareDateForDb($flag->expiresAt);

            if (!$record->save()) {
                $flag->addErrors($record->getErrors());
                $transaction->rollBack();
                Craft::error('Failed to save flag record: ' . Json::encode($record->errors), __METHOD__);
                return false;
            }

            $flag->id = $record->id;

            RuleRecord::deleteAll(['flagId' => $flag->id]);

            foreach ($flag->rules as $rule) {
                $ruleRecord = new RuleRecord();
                $ruleRecord->flagId = $flag->id;
                $ruleRecord->ruleType = $rule->ruleType;
                $ruleRecord->ruleValue = $rule->ruleValue;

                if (!$ruleRecord->save()) {
                    $transaction->rollBack();
                    Craft::error('Failed to save rule record: ' . Json::encode($ruleRecord->errors), __METHOD__);
                    return false;
                }

                $rule->id = $ruleRecord->id;
                $rule->flagId = $flag->id;
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Craft::error('Failed to save flag: ' . $e->getMessage(), __METHOD__);
            throw $e;
        }

        $this->logAudit($flag->id, $isNew ? 'created' : 'updated', [
            'name' => $flag->name,
            'handle' => $flag->handle,
            'enabled' => $flag->enabled,
            'rolloutPercentage' => $flag->rolloutPercentage,
            'rulesCount' => count($flag->rules),
        ]);

        $this->invalidateCache($flag->handle);
        if ($oldHandle && $oldHandle !== $flag->handle) {
            $this->invalidateCache($oldHandle);
        }

        return true;
    }

    public function deleteFlag(int $id): bool
    {
        $flag = $this->getFlagById($id);
        if (!$flag) {
            return false;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            FlagRecord::deleteAll(['id' => $id]);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Craft::error('Failed to delete flag: ' . $e->getMessage(), __METHOD__);
            throw $e;
        }

        // flagId is SET NULL on existing audit rows; log the deletion with null flagId.
        $this->logAudit(null, 'deleted', ['name' => $flag->name, 'handle' => $flag->handle]);
        $this->invalidateCache($flag->handle);

        return true;
    }

    public function toggleFlag(int $id, bool $enabled): bool
    {
        $record = FlagRecord::findOne($id);
        if (!$record) {
            return false;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $record->enabled = $enabled;
            if (!$record->save()) {
                $transaction->rollBack();
                Craft::error('Failed to toggle flag: ' . Json::encode($record->getErrors()), __METHOD__);
                return false;
            }
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Craft::error('Failed to toggle flag: ' . $e->getMessage(), __METHOD__);
            throw $e;
        }

        $this->logAudit($id, $enabled ? 'enabled' : 'disabled', [
            'name' => $record->name,
            'handle' => $record->handle,
        ]);

        $this->invalidateCache($record->handle);

        return true;
    }

    /**
     * @return array[]
     */
    public function getAuditLog(?int $flagId = null, int $limit = 200, int $offset = 0): array
    {
        $query = (new Query())
            ->select([
                'a.id',
                'a.flagId',
                'a.userId',
                'a.action',
                'a.details',
                'a.dateCreated',
                'f.name as flagName',
                'u.username',
            ])
            ->from(['a' => AuditRecord::tableName()])
            ->leftJoin(['f' => FlagRecord::tableName()], '[[a.flagId]] = [[f.id]]')
            ->leftJoin(['u' => '{{%users}}'], '[[a.userId]] = [[u.id]]')
            ->orderBy(['a.dateCreated' => SORT_DESC])
            ->limit($limit)
            ->offset($offset);

        if ($flagId !== null) {
            $query->where(['a.flagId' => $flagId]);
        }

        return $query->all();
    }

    private function populateFlag(FlagRecord $record): Flag
    {
        $flag = new Flag();
        $flag->id = $record->id;
        $flag->name = $record->name;
        $flag->handle = $record->handle;
        $flag->description = $record->description;
        $flag->enabled = (bool)$record->enabled;
        $flag->rolloutPercentage = $record->rolloutPercentage !== null ? (int)$record->rolloutPercentage : null;
        $flag->flagType = $record->flagType;
        $flag->expiresAt = DateTimeHelper::toDateTime($record->expiresAt) ?: null;
        $flag->uid = $record->uid;
        $flag->dateCreated = DateTimeHelper::toDateTime($record->dateCreated) ?: null;
        $flag->dateUpdated = DateTimeHelper::toDateTime($record->dateUpdated) ?: null;

        $flag->rules = array_map(function (RuleRecord $ruleRecord) {
            $rule = new Rule();
            $rule->id = $ruleRecord->id;
            $rule->flagId = $ruleRecord->flagId;
            $rule->ruleType = $ruleRecord->ruleType;
            $rule->ruleValue = $ruleRecord->ruleValue;
            return $rule;
        }, $record->rules ?? []);

        return $flag;
    }

    private function logAudit(?int $flagId, string $action, array $details = []): void
    {
        /** @var \craftquest\featureflags\models\Settings $settings */
        $settings = FeatureFlags::getInstance()->getSettings();

        if (!$settings->enableAuditLog) {
            return;
        }

        $audit = new AuditRecord();
        $audit->flagId = $flagId;
        $audit->userId = Craft::$app->getUser()->getId();
        $audit->action = $action;
        $audit->details = Json::encode($details);
        if (!$audit->save()) {
            Craft::error('Failed to save audit record: ' . Json::encode($audit->getErrors()), __METHOD__);
        }
    }

    private function invalidateCache(string $flagHandle): void
    {
        Craft::$app->getCache()->delete(self::cacheKey($flagHandle));
    }
}
