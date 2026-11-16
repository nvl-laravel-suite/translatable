<?php

declare(strict_types=1);

namespace Nvl\Translatable\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/** Creates the soft-deleting related translation tables, with or without the owner and locale unique index. */
final class RelatedIdentitySchema
{
    public static function create(Builder $schema, bool $uniqueIndex): void
    {
        self::drop($schema);
        $schema->create('related_identity_owners', static function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->nullable();
            $table->timestamps();
        });
        $schema->create('related_identity_translations', static function (Blueprint $table) use ($uniqueIndex): void {
            $table->id();
            $table->foreignId('related_identity_owner_id')->constrained('related_identity_owners')->cascadeOnDelete();
            $table->string('locale', 35);
            $table->string('name')->nullable();
            $table->string('description')->nullable();
            $table->softDeletes();
            if ($uniqueIndex) {
                $table->unique(['related_identity_owner_id', 'locale'], 'related_identity_translation_unique');
            }
        });
    }

    public static function drop(Builder $schema): void
    {
        $schema->dropIfExists('related_identity_translations');
        $schema->dropIfExists('related_identity_owners');
    }
}
