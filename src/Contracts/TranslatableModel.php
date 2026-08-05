<?php

declare(strict_types=1);

namespace Nvl\Translatable\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nvl\Translatable\RelatedTranslationDefinition;

/**
 * Defines a translatable owner model using a dedicated related-row table.
 */
interface TranslatableModel extends TranslatableResourceModel
{
    /**
     * Return the model's related-row translation definition.
     */
    public function translationDefinition(): RelatedTranslationDefinition;

    /**
     * Return the model's translation relationship.
     *
     * @return HasMany<Model, *>
     */
    public function translations(): HasMany;
}
