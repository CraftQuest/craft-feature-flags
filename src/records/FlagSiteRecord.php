<?php

namespace craftquest\featureflags\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $flagId
 * @property int $siteId
 * @property bool $enabled
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class FlagSiteRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%featureflags_flags_sites}}';
    }

    public function rules(): array
    {
        return [
            [['flagId', 'siteId'], 'required'],
            [['flagId', 'siteId'], 'integer'],
            [['enabled'], 'boolean'],
        ];
    }

    public function getFlag(): \yii\db\ActiveQueryInterface
    {
        return $this->hasOne(FlagRecord::class, ['id' => 'flagId']);
    }
}
