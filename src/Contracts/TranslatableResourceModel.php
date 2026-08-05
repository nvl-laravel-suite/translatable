<?php

declare(strict_types=1);

namespace Nvl\Translatable\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\TranslationDefinition;
use Nvl\Translatable\TranslationResolution;

/**
 * Defines behavior shared by every translatable Eloquent storage strategy.
 */
interface TranslatableResourceModel
{
    /**
     * Return the model's resolved immutable translation definition.
     */
    public function translationDefinition(): TranslationDefinition;

    /**
     * Resolve one translated field for the requested locale.
     */
    public function resolveTranslation(string $field, ?string $locale = null): TranslationResolution;

    /**
     * Return one translated field for the requested locale.
     */
    public function translated(string $field, ?string $locale = null): mixed;

    /**
     * Return every locale persisted for the logical resource.
     *
     * @return list<string>
     */
    public function getAvailableLocales(): array;

    /**
     * Return every persisted row representing the logical resource.
     *
     * @return Collection<int, covariant Model>
     */
    public function getAllTranslations(): Collection;

    /**
     * Return the stable identifier used by centralized translation tooling.
     */
    public function translationResourceKey(): int|string;
}
