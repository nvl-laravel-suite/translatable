<?php

declare(strict_types=1);

namespace Nvl\Translatable\Contracts;

use Nvl\Translatable\SelfTranslationDefinition;

/**
 * Marks a model whose locale rows are grouped in the resource table itself.
 */
interface SelfTranslatableModel extends TranslatableResourceModel
{
    /**
     * Return the model's grouped self-row translation definition.
     */
    public function translationDefinition(): SelfTranslationDefinition;
}
