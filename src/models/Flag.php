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
    public string $flagType = 'release';
    public ?DateTime $expiresAt = null;
    public ?string $uid = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;

    /** @var Rule[] */
    public array $rules = [];

    public function safeAttributes(): array
    {
        return ['name', 'handle', 'description', 'enabled', 'rolloutPercentage', 'flagType', 'expiresAt', 'rules'];
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
            [['flagType'], 'in', 'range' => array_column(FlagType::cases(), 'value')],
            [['expiresAt'], 'safe'],
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
