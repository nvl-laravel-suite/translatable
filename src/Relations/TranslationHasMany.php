<?php

declare(strict_types=1);

namespace Nvl\Translatable\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Preserves native related-translation behavior with canonical ownership constraints.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends HasMany<TRelatedModel, TDeclaringModel>
 */
final class TranslationHasMany extends HasMany
{
    /** @use GuardsTranslationRelation<TRelatedModel, TDeclaringModel> */
    use GuardsTranslationRelation;
}
