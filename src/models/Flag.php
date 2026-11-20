<?php

namespace craftquest\featureflags\models;

use Craft;
use craft\base\Model;
use craftquest\featureflags\enums\FlagType;
use craftquest\featureflags\FeatureFlags;
use DateTime;

class Flag extends Model
{
    public ?int $id = null;
    public ?string $name = null;
    public ?string $handle = null;
    public ?string $description = null;
    public bool $enabled = false;
    public ?int $rolloutPercentage = null;
    public string $rolloutStrategy = 'all';
    public string $flagType = 'release';
    public ?DateTime $startsAt = null;
    public ?DateTime $expiresAt = null;
    public ?string $uid = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;

    /** @var Rule[] */
    public array $rules = [];

    /**
     * Per-site enabled state, keyed by site ID. Null/empty means the flag is not
     * site-scoped and applies to all sites ("all sites" mode). A non-empty map puts
     * the flag in "specific sites" mode: it is enabled only on sites with an explicit
     * true value; every other site — unlisted today or added later — is off.
     *
     * @var array<int, bool>|null
     */
    public ?array $siteSettings = null;

    /**
     * A plain-array snapshot for the flag cache (Craft 6's cache won't unserialize plugin
     * objects). Round-trips through fromCacheArray(); dates keep their offset.
     */
    public function toCacheArray(): array
    {
        $date = static fn(?\DateTimeInterface $d): ?string => $d?->format(DATE_ATOM);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'handle' => $this->handle,
            'description' => $this->description,
            'enabled' => $this->enabled,
            'rolloutPercentage' => $this->rolloutPercentage,
            'rolloutStrategy' => $this->rolloutStrategy,
            'flagType' => $this->flagType,
            'startsAt' => $date($this->startsAt),
            'expiresAt' => $date($this->expiresAt),
            'uid' => $this->uid,
            'dateCreated' => $date($this->dateCreated),
            'dateUpdated' => $date($this->dateUpdated),
            'rules' => array_map(static fn(Rule $r): array => ['id' => $r->id, 'ruleType' => $r->ruleType, 'ruleValue' => $r->ruleValue], $this->rules),
            'siteSettings' => $this->siteSettings,
        ];
    }

    public static function fromCacheArray(array $data): self
    {
        $date = static fn(?string $d): ?DateTime => $d !== null ? new DateTime($d) : null;

        $flag = new self();
        $flag->id = $data['id'];
        $flag->name = $data['name'];
        $flag->handle = $data['handle'];
        $flag->description = $data['description'];
        $flag->enabled = $data['enabled'];
        $flag->rolloutPercentage = $data['rolloutPercentage'];
        $flag->rolloutStrategy = $data['rolloutStrategy'];
        $flag->flagType = $data['flagType'];
        $flag->startsAt = $date($data['startsAt']);
        $flag->expiresAt = $date($data['expiresAt']);
        $flag->uid = $data['uid'];
        $flag->dateCreated = $date($data['dateCreated']);
        $flag->dateUpdated = $date($data['dateUpdated']);
        $flag->rules = array_map(static function(array $r) use ($flag): Rule {
            $rule = new Rule();
            $rule->id = $r['id'];
            $rule->flagId = $flag->id;
            $rule->ruleType = $r['ruleType'];
            $rule->ruleValue = $r['ruleValue'];
            return $rule;
        }, $data['rules']);
        $flag->siteSettings = $data['siteSettings'] !== null
            ? array_map('boolval', array_combine(array_map('intval', array_keys($data['siteSettings'])), $data['siteSettings']))
            : null;

        return $flag;
    }

    public function safeAttributes(): array
    {
        return ['name', 'handle', 'description', 'enabled', 'rolloutPercentage', 'rolloutStrategy', 'flagType', 'startsAt', 'expiresAt', 'rules', 'siteSettings'];
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'handle', 'flagType'], 'required'],
            [['name'], 'string', 'max' => 100],
            [['handle'], 'string', 'max' => 100],
            [['handle'], 'match', 'pattern' => '/^[a-z][a-z0-9\-]*$/', 'message' => Craft::t('feature-flags', 'Handle must start with a lowercase letter and contain only lowercase letters, numbers, and hyphens.')],
            [['handle'], function ($attribute) {
                $query = \craftquest\featureflags\records\FlagRecord::find()
                    ->where(['handle' => $this->$attribute]);
                if ($this->id) {
                    $query->andWhere(['not', ['id' => $this->id]]);
                }
                if ($query->exists()) {
                    $this->addError($attribute, Craft::t('feature-flags', 'This handle is already in use.'));
                }
            }],
            [['description'], 'string', 'max' => 2000],
            [['enabled'], 'boolean'],
            [['rolloutPercentage'], 'integer', 'min' => 0, 'max' => 100, 'skipOnEmpty' => true],
            [['rolloutStrategy'], 'in', 'range' => ['all', 'rule']],
            [['flagType'], 'in', 'range' => array_column(FlagType::cases(), 'value')],
            [['startsAt', 'expiresAt'], 'safe'],
            [['expiresAt'], function ($attribute) {
                if ($this->startsAt !== null && $this->expiresAt !== null && $this->expiresAt <= $this->startsAt) {
                    $this->addError($attribute, Craft::t('feature-flags', 'The expiration date must be after the start date.'));
                }
            }],
            [['siteSettings'], function ($attribute) {
                // "Specific sites" mode with every site switched off would gate the flag
                // off everywhere with no indication in the CP. Require at least one site.
                $settings = $this->$attribute;
                if (is_array($settings) && $settings !== [] && !in_array(true, $settings, true)) {
                    $this->addError($attribute, Craft::t('feature-flags', 'Select at least one site, or choose “All sites”.'));
                }
            }],
            [['rules'], function ($attribute) {
                $allowedRuleTypes = FeatureFlags::getInstance()->flagService->getRuleTypeValues();
                foreach ($this->$attribute as $i => $rule) {
                    if (!$rule->validate()) {
                        foreach ($rule->getErrors() as $field => $errors) {
                            foreach ($errors as $error) {
                                $this->addError("rules[{$i}].{$field}", $error);
                            }
                        }
                    }
                    if ($rule->ruleType !== null && !in_array($rule->ruleType, $allowedRuleTypes, true)) {
                        $this->addError("rules[{$i}].ruleType", Craft::t('feature-flags', 'Unknown rule type: {type}.', ['type' => $rule->ruleType]));
                    }
                }
            }],
        ];
    }
}
