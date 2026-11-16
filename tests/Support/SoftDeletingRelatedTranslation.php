<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Soft-deleting locale row for SoftDeletingRelatedOwner.
 *
 * @property int $id
 * @property int $related_identity_owner_id
 * @property string $locale
 * @property string|null $name
 * @property string|null $description
 */
final class SoftDeletingRelatedTranslation extends Model
{
    use SoftDeletes;

    protected $table = 'related_identity_translations';

    protected $fillable = ['locale', 'name', 'description'];

    public $timestamps = false;
}
