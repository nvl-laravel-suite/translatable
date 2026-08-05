<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Contracts\Config\Repository;
use JsonException;
use Nvl\Translatable\Exceptions\TranslatableException;
use Nvl\Translatable\TranslationDefinition;

/**
 * Enforces globally bounded translation mutation payloads.
 */
final readonly class TranslationPayloadValidator
{
    /**
     * Create the translation payload validator.
     */
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Validate mutation size, field count, and nesting limits.
     *
     * @param  array<array-key, mixed>  $translations
     */
    public function validate(
        TranslationDefinition $definition,
        array $translations,
    ): void {
        $maximumLocales = $this->positiveLimit('mutation_locales', 50);
        $maximumFields = $this->positiveLimit('mutation_fields', 100);
        $maximumBytes = $this->positiveLimit('mutation_value_bytes', 1_000_000);
        $maximumDepth = $this->positiveLimit('mutation_depth', 20);

        if (count($translations) > $maximumLocales) {
            throw new TranslatableException(
                "Translation mutations may contain at most {$maximumLocales} locales.",
            );
        }

        foreach ($translations as $locale => $attributes) {
            if (! is_string($locale)) {
                throw new TranslatableException(
                    'Translation mutations must use string locale keys.',
                );
            }

            if (! is_array($attributes)) {
                throw new TranslatableException(
                    "Translation locale [{$locale}] must contain a field-keyed array.",
                );
            }

            if (count($attributes) > $maximumFields) {
                throw new TranslatableException(
                    "Translation locale [{$locale}] may contain at most {$maximumFields} fields.",
                );
            }

            foreach (array_keys($attributes) as $field) {
                if (! is_string($field)) {
                    throw new TranslatableException(
                        "Translation locale [{$locale}] must use string field keys.",
                    );
                }

                $definition->assertTranslatableField($field);
            }
        }

        try {
            $encoded = json_encode(
                $translations,
                JSON_PRESERVE_ZERO_FRACTION
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR,
                $maximumDepth,
            );
        } catch (JsonException $exception) {
            if ($exception->getCode() === JSON_ERROR_DEPTH) {
                throw new TranslatableException(
                    "Translation mutation nesting may not exceed {$maximumDepth} levels.",
                    previous: $exception,
                );
            }

            throw new TranslatableException(
                'Translation mutations must contain JSON-serializable values.',
                previous: $exception,
            );
        }

        if (strlen($encoded) > $maximumBytes) {
            throw new TranslatableException(
                "Translation mutations may not exceed {$maximumBytes} encoded bytes.",
            );
        }
    }

    /**
     * Return one validated positive integer limit.
     *
     * @return positive-int
     */
    private function positiveLimit(string $key, int $default): int
    {
        $value = $this->config->get("translatable.limits.{$key}", $default);

        if (! is_int($value) || $value < 1) {
            throw new TranslatableException(
                "The translatable.limits.{$key} value must be a positive integer.",
            );
        }

        return $value;
    }
}
