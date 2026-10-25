<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Canonical host owner for native partition tests. */
final class TenantTranslationOwner extends Model
{
    use HasUuids;

    protected $table = 'tenant_test_owners';

    protected $guarded = [];

    public $timestamps = false;
}
