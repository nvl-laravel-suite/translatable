<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/** Native host owner without an NVL tenancy or authentication package. */
final class OwnershipCompany extends Model
{
    protected $table = 'translation_ownership_companies';

    protected $guarded = [];

    public $timestamps = false;
}
