<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\Translatable;

/** Host-native owned resource whose translations inherit its canonical partition. */
class OwnershipRelatedRecord extends Model implements Ownable, TranslatableModel
{
    use HasOwnership;
    use Translatable;

    protected $table = 'translation_ownership_records';

    protected $guarded = [];

    public $timestamps = false;

    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(OwnershipCompany::class, 'company_id');
    }

    protected function defineTranslations(): RelatedTranslationDefinition
    {
        return new RelatedTranslationDefinition(OwnershipRelatedTranslation::class, ['name'], foreignKey: 'record_id', locales: ['en', 'bg'], ownershipResource: 'fixture.records');
    }
}
