<?php

namespace craftquest\featureflags\records;

use CraftCms\Cms\Shared\BaseModel;
use CraftCms\Cms\Shared\Concerns\HasUid;

/**
 * An audit log entry. flagId is set to null when the flag is deleted.
 *
 * @property int $id
 * @property int|null $flagId
 * @property int|null $userId
 * @property string $action
 * @property string|null $details JSON
 */
class AuditRecord extends BaseModel
{
    use HasUid;

    public const TABLE = 'featureflags_audit';

    protected $table = self::TABLE;

    /** Yii-style table name, used by the migrations. */
    public static function tableName(): string
    {
        return '{{%' . self::TABLE . '}}';
    }
}
