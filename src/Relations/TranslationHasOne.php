<?php

declare(strict_types=1);

namespace Nvl\Translatable\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Preserves native related-translation behavior with canonical ownership constraints.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends HasOne<TRelatedModel, TDeclaringModel>
 */
final class TranslationHasOne extends HasOne
{
    /** @use GuardsTranslationRelation<TRelatedModel, TDeclaringModel> */
    use GuardsTranslationRelation;
}
