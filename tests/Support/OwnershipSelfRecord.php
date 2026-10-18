<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Translatable\Contracts\SelfTranslatableModel;
use Nvl\Translatable\SelfTranslatable;
use Nvl\Translatable\SelfTranslationDefinition;

/** Native owned locale rows share logical group keys only inside one primary partition. */
final class OwnershipSelfRecord extends Model implements Ownable, SelfTranslatableModel
{
    use HasOwnership;
    use SelfTranslatable;

    protected $table = 'translation_ownership_self_records';

    protected $guarded = [];

    public $timestamps = false;

    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(OwnershipCompany::class, 'company_id');
    }

    protected function defineTranslations(): SelfTranslationDefinition
    {
        return new SelfTranslationDefinition('entry_key', ['name'], locales: ['en', 'bg'], ownershipResource: 'fixture.self');
    }
}
