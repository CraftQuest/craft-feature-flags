<?php

namespace craftquest\featureflags\records;

use craft\db\ActiveRecord;
use craftquest\featureflags\enums\FlagType;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property bool $enabled
 * @property int|null $rolloutPercentage
 * @property string $flagType
 * @property string|null $expiresAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class FlagRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%featureflags_flags}}';
    }

    public function rules(): array
    {
        return [
            [['handle'], 'unique'],
            [['name', 'handle', 'flagType'], 'required'],
            [['name'], 'string', 'max' => 100],
            [['handle'], 'string', 'max' => 100],
            [['description'], 'string'],
            [['enabled'], 'boolean'],
            [['rolloutPercentage'], 'integer', 'min' => 0, 'max' => 100, 'skipOnEmpty' => true],
            [['flagType'], 'in', 'range' => array_column(FlagType::cases(), 'value')],
            [['expiresAt'], 'datetime', 'format' => 'php:Y-m-d H:i:s'],
        ];
    }

    public function getRules(): \yii\db\ActiveQueryInterface
    {
        return $this->hasMany(RuleRecord::class, ['flagId' => 'id']);
    }
}
