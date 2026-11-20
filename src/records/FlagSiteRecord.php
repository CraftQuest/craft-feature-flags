<?php

namespace craftquest\featureflags\records;

use CraftCms\Cms\Shared\BaseModel;
use CraftCms\Cms\Shared\Concerns\HasUid;

/**
 * Per-site enabled state for a flag scoped to specific sites.
 *
 * @property int $id
 * @property int $flagId
 * @property int $siteId
 * @property bool $enabled
 */
class FlagSiteRecord extends BaseModel
{
    use HasUid;

    public const TABLE = 'featureflags_flags_sites';

    protected $table = self::TABLE;

    protected $casts = ['flagId' => 'integer', 'siteId' => 'integer', 'enabled' => 'bool'];

    /** Yii-style table name, used by the migrations. */
    public static function tableName(): string
    {
        return '{{%' . self::TABLE . '}}';
    }
}
