<?php

namespace craftquest\featureflags\records;

use CraftCms\Cms\Shared\BaseModel;
use CraftCms\Cms\Shared\Concerns\HasUid;

/**
 * @property int $id
 * @property int $flagId
 * @property string $ruleType
 * @property string $ruleValue
 */
class RuleRecord extends BaseModel
{
    use HasUid;

    public const TABLE = 'featureflags_rules';

    protected $table = self::TABLE;

    protected $casts = ['flagId' => 'integer'];

    /** Yii-style table name, used by the migrations. */
    public static function tableName(): string
    {
        return '{{%' . self::TABLE . '}}';
    }
}
