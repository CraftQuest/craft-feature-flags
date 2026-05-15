<?php

namespace craftquest\featureflags\records;

use craft\db\ActiveRecord;
use craft\records\User;

/**
 * @property int $id
 * @property int|null $flagId
 * @property int|null $userId
 * @property string $action
 * @property string|null $details
 * @property string $dateCreated
 * @property string $uid
 */
class AuditRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%featureflags_audit}}';
    }

    public function rules(): array
    {
        return [
            [['action'], 'required'],
            [['flagId', 'userId'], 'integer'],
            [['action'], 'string', 'max' => 50],
            [['details'], 'string'],
        ];
    }

    public function getFlag(): \yii\db\ActiveQueryInterface
    {
        return $this->hasOne(FlagRecord::class, ['id' => 'flagId']);
    }

    public function getUser(): \yii\db\ActiveQueryInterface
    {
        return $this->hasOne(User::class, ['id' => 'userId']);
    }
}
