<?php

declare(strict_types=1);

namespace Nvl\Translatable;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Nvl\Translatable\Enums\TranslationFallbackPolicy;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\Enums\TranslationStorageStrategy;
use Nvl\Translatable\Exceptions\TranslatableException;

/**
 * Defines translations stored in rows related to a canonical owner model.
 */
final readonly class RelatedTranslationDefinition extends TranslationDefinition
{
    /**
     * Create a related-row translation definition.
     *
     * @param  class-string<Model>  $translationModel
     * @param  list<string>  $fields
     * @param  list<string>|null  $locales
     * @param  list<string>  $fallbackLocales
     */
    public function __construct(
        public string $translationModel,
        array $fields,
        public ?string $foreignKey = null,
        public string $ownerKey = 'id',
        string $localeKey = 'locale',
        ?array $locales = null,
        ?TranslationFallbackPolicy $fallbackPolicy = null,
        array $fallbackLocales = [],
        ?bool $fallbackOnNull = null,
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

        $this->assertColumn($this->ownerKey, 'owner key');

        if ($this->foreignKey !== null) {
            if (substr_count($this->foreignKey, '{table}') > 1) {
                throw new TranslatableException(
                    'A related translation foreign key may contain at most one {table} placeholder.',
                );
            }

            $foreignKey = str_replace('{table}', 'owners', $this->foreignKey);
            $this->assertColumn($foreignKey, 'foreign key');
            $this->assertFieldsExclude([$foreignKey]);
        }

        $this->assertTranslationModel($this->translationModel);
    }

    /**
     * Return the related-row persistence strategy.
     */
    public function storage(): TranslationStorageStrategy
    {
        return TranslationStorageStrategy::Related;
    }

    /**
     * Assert that declared fields cannot overwrite translation-row identity.
     */
    public function assertModel(Model $owner): void
    {
        $translationModel = new $this->translationModel;
        $primaryKey = $translationModel->getKeyName();
        $foreignKey = $this->foreignKey($owner->getTable());
        $identityColumns = array_map(
            mb_strtolower(...),
            [$primaryKey, $foreignKey, $this->localeKey],
        );

        if (count(array_unique($identityColumns)) !== count($identityColumns)) {
            throw new TranslatableException(
                'Related translation primary, foreign-key, and locale columns must be distinct.',
            );
        }

        $this->assertFieldsExclude([
            ...$this->modelManagedColumns($translationModel),
            $foreignKey,
            $this->localeKey,
        ]);
    }

    /**
     * Resolve the translation table foreign key for an owner table.
     */
    public function foreignKey(string $ownerTable): string
    {
        $foreignKey = $this->foreignKey !== null
            ? str_replace('{table}', $ownerTable, $this->foreignKey)
            : Str::singular($ownerTable).'_id';

        $this->assertColumn($foreignKey, 'foreign key');
        $this->assertFieldsExclude([$foreignKey]);

        return $foreignKey;
    }

    /**
     * Guard the PHPDoc-only class-string contract at runtime.
     */
    private function assertTranslationModel(string $translationModel): void
    {
        if (! is_a($translationModel, Model::class, true)) {
            throw new TranslatableException(
                "Translation model [{$translationModel}] must extend Eloquent Model.",
            );
        }
    }
}
