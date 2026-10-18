<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Concrete inherited child cannot acquire an owner separately from its persisted parent. */
final class OwnershipRelatedTranslation extends Model implements Ownable
{
    use HasOwnership;

    protected $table = 'translation_ownership_rows';

    protected $guarded = [];

    public $timestamps = false;

    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::inherited('owner');
    }

    /** @return BelongsTo<OwnershipRelatedRecord, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(OwnershipRelatedRecord::class, 'record_id');
    }
}
