<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Nvl\Translatable\TranslationDefinition;
use Nvl\Translatable\TranslationResolution;

/**
 * Resolves translated values from an already-selected collection of translation rows.
 */
final class TranslationResolver
{
    /**
     * Resolve one translated field through the configured locale chain.
     *
     * @param  Collection<int, Model>  $translations
     * @param  list<string>  $localeChain
     */
    public function resolve(
        Collection $translations,
        TranslationDefinition $options,
        string $field,
        string $requestedLocale,
        array $localeChain,
    ): TranslationResolution {
        $options->assertTranslatableField($field);
        $requestedTranslationExists = $translations->contains(
            $options->localeKey,
            $requestedLocale,
        );

        foreach ($localeChain as $locale) {
            $translation = $translations->firstWhere($options->localeKey, $locale);

            if (! $translation instanceof Model) {
                continue;
            }

            $value = $translation->getAttribute($field);

            if ($value === null && $options->shouldFallbackOnNull()) {
                continue;
            }

            return new TranslationResolution(
                field: $field,
                requestedLocale: $requestedLocale,
                resolvedLocale: $locale,
                value: $value,
                localeChain: $localeChain,
                requestedTranslationExists: $requestedTranslationExists,
            );
        }

        return new TranslationResolution(
            field: $field,
            requestedLocale: $requestedLocale,
            resolvedLocale: null,
            value: null,
            localeChain: $localeChain,
            requestedTranslationExists: $requestedTranslationExists,
        );
    }
}
