<?php

namespace craftquest\featureflags\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $flagId
 * @property string $ruleType
 * @property string $ruleValue
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class RuleRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%featureflags_rules}}';
    }

    public function rules(): array
    {
        return [
            [['flagId', 'ruleType', 'ruleValue'], 'required'],
            [['flagId'], 'integer'],
            [['ruleType'], 'string', 'max' => 50],
            [['ruleValue'], 'string', 'max' => 255],
        ];
    }

    public function getFlag(): \yii\db\ActiveQueryInterface
    {
        return $this->hasOne(FlagRecord::class, ['id' => 'flagId']);
    }
}
