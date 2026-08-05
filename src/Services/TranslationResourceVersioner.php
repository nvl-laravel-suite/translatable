<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use Nvl\Translatable\Contracts\TranslatableResourceModel;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\SelfTranslationDefinition;
use stdClass;
use Stringable;
use UnitEnum;

/**
 * Calculates deterministic optimistic-concurrency tokens.
 */
final class TranslationResourceVersioner
{
    /**
     * Calculate a version from owner and translation state.
     */
    public function version(Model&TranslatableResourceModel $owner): string
    {
        $definition = $owner->translationDefinition();
        $rows = [];
        $translations = $owner->getAllTranslations();
        $versionedFields = [
            ...$definition->fields,
            ...($definition instanceof SelfTranslationDefinition
                ? $definition->sharedFields
                : []),
        ];

        foreach ($translations as $translation) {
            $locale = $translation->getAttribute($definition->localeKey);

            if (! is_string($locale)) {
                continue;
            }

            $values = [];

            foreach ($versionedFields as $field) {
                $values[$field] = $this->normalize($translation->getAttribute($field));
            }

            ksort($values);
            $translationUpdatedAt = $translation->getUpdatedAtColumn();
            $rows[$locale] = [
                'updatedAt' => $this->normalize(
                    $translationUpdatedAt !== null
                        ? $translation->getAttribute($translationUpdatedAt)
                        : null,
                ),
                'values' => $values,
            ];
        }

        ksort($rows);
        $updatedAtColumn = $owner->getUpdatedAtColumn();
        $state = [
            'id' => $owner->translationResourceKey(),
            'updatedAt' => $this->normalize(
                $updatedAtColumn !== null ? $owner->getAttribute($updatedAtColumn) : null,
            ),
            'translations' => $rows,
        ];

        return hash('sha256', (string) json_encode(
            $state,
            JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Normalize values before hashing.
     */
    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            $normalized = array_map(
                fn (mixed $item): mixed => $this->normalize($item),
                $value,
            );

            if (! array_is_list($normalized)) {
                ksort($normalized);
            }

            return $normalized;
        }

        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof Arrayable => $this->normalize($value->toArray()),
            $value instanceof JsonSerializable => $this->normalize($value->jsonSerialize()),
            $value instanceof stdClass => $this->normalize((array) $value),
            $value instanceof Stringable => (string) $value,
            is_object($value) => throw TranslationResourceException::invalid(
                'Translation versions require scalar, array, enum, date, arrayable, JSON-serializable, or stringable values; '
                .$value::class.' was given.',
            ),
            default => $value,
        };
    }
}
