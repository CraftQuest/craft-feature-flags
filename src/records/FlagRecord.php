<?php

namespace craftquest\featureflags\records;

use CraftCms\Cms\Shared\BaseModel;
use CraftCms\Cms\Shared\Concerns\HasUid;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A feature flag row. Validation lives on models\Flag; FlagService converts between them.
 *
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string|null $description
 * @property bool $enabled
 * @property int|null $rolloutPercentage
 * @property string $rolloutStrategy
 * @property string $flagType
 * @property \Carbon\CarbonInterface|null $startsAt
 * @property \Carbon\CarbonInterface|null $expiresAt
 * @property string $uid
 */
class FlagRecord extends BaseModel
{
    use HasUid;

    public const TABLE = 'featureflags_flags';

    protected $table = self::TABLE;

    protected $casts = [
        'enabled' => 'bool',
        'rolloutPercentage' => 'integer',
        'startsAt' => 'datetime',
        'expiresAt' => 'datetime',
    ];

    /** Yii-style table name, used by the migrations. */
    public static function tableName(): string
    {
        return '{{%' . self::TABLE . '}}';
    }

    public function rules(): HasMany
    {
        return $this->hasMany(RuleRecord::class, 'flagId')->orderBy('id');
    }

    public function flagSites(): HasMany
    {
        return $this->hasMany(FlagSiteRecord::class, 'flagId');
    }
}
