<?php

declare(strict_types=1);

namespace Nvl\Translatable;

use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Enums\TranslationFallbackPolicy;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\Enums\TranslationStorageStrategy;
use Nvl\Translatable\Exceptions\TranslatableException;

/**
 * Defines translations stored as grouped locale rows in the resource table itself.
 */
final readonly class SelfTranslationDefinition extends TranslationDefinition
{
    /**
     * Create a self-row translation definition.
     *
     * @param  list<string>  $fields
     * @param  list<string>  $sharedFields
     * @param  list<string>|null  $locales
     * @param  list<string>  $fallbackLocales
     */
    public function __construct(
        public string $groupKey,
        array $fields,
        public array $sharedFields = [],
        string $localeKey = 'locale',
        ?array $locales = null,
        ?TranslationFallbackPolicy $fallbackPolicy = null,
        array $fallbackLocales = [],
        ?bool $fallbackOnNull = null,
        public bool $allowDeletingLastTranslation = false,
        TranslationMutationPolicy $mutationPolicy = TranslationMutationPolicy::Direct,
    ) {
        parent::__construct(
            fields: $fields,
            localeKey: $localeKey,
            locales: $locales,
            fallbackPolicy: $fallbackPolicy,
            fallbackLocales: $fallbackLocales,
            fallbackOnNull: $fallbackOnNull,
            mutationPolicy: $mutationPolicy,
        );

        $this->assertColumn($this->groupKey, 'group key');
        $this->assertColumns($this->sharedFields, 'shared');

        if (mb_strtolower($this->groupKey) === mb_strtolower($this->localeKey)) {
            throw new TranslatableException('The self-translation group and locale columns must differ.');
        }

        $this->assertFieldsExclude([$this->groupKey]);
        $structuralColumns = array_map(
            mb_strtolower(...),
            [$this->groupKey, $this->localeKey],
        );

        foreach ($this->sharedFields as $sharedField) {
            if (in_array(mb_strtolower($sharedField), $structuralColumns, true)) {
                throw new TranslatableException(
                    "Structural column [{$sharedField}] cannot be a shared field.",
                );
            }
        }

        $normalizedSharedFields = array_map(mb_strtolower(...), $this->sharedFields);
        $overlap = array_values(array_filter(
            $this->fields,
            static fn (string $field): bool => in_array(
                mb_strtolower($field),
                $normalizedSharedFields,
                true,
            ),
        ));

        if ($overlap !== []) {
            throw new TranslatableException(
                'Translated and shared fields must be disjoint: '.implode(', ', $overlap).'.',
            );
        }
    }

    /**
     * Return the self-row persistence strategy.
     */
    public function storage(): TranslationStorageStrategy
    {
        return TranslationStorageStrategy::Self;
    }

    /**
     * Assert that logical identity and the physical primary key remain distinct.
     */
    public function assertModel(Model $model): void
    {
        $primaryKey = $model->getKeyName();
        $identityColumns = array_map(
            mb_strtolower(...),
            [$primaryKey, $this->groupKey, $this->localeKey],
        );

        if (count(array_unique($identityColumns)) !== count($identityColumns)) {
            throw new TranslatableException(
                'Self-translation primary, group, and locale columns must be distinct.',
            );
        }

        $primaryKey = mb_strtolower($primaryKey);

        foreach ([...$this->fields, ...$this->sharedFields] as $field) {
            if (mb_strtolower($field) === $primaryKey) {
                throw new TranslatableException(
                    "Self-translation primary key [{$field}] cannot be translated or shared.",
                );
            }
        }

        $managedColumns = array_values(array_filter(
            $this->modelManagedColumns($model),
            static fn (string $column): bool => mb_strtolower($column) !== $primaryKey,
        ));
        $this->assertFieldsExclude($managedColumns);
        $normalizedManagedColumns = array_map(mb_strtolower(...), $managedColumns);

        foreach ($this->sharedFields as $field) {
            if (in_array(mb_strtolower($field), $normalizedManagedColumns, true)) {
                throw new TranslatableException(
                    "Self-translation model-managed column [{$field}] cannot be shared.",
                );
            }
        }
    }
}
