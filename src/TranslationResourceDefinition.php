<?php

declare(strict_types=1);

namespace Nvl\Translatable;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Contracts\SelfTranslatableModel;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Contracts\TranslatableResourceModel;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\Exceptions\TranslationResourceException;

/**
 * Defines one translatable Eloquent resource exposed through the central catalog.
 */
final readonly class TranslationResourceDefinition
{
    /**
     * Create a translation resource definition.
     *
     * @param  class-string  $modelClass
     * @param  list<string>  $searchableColumns
     * @param  list<string>  $displayColumns
     * @param  (Closure(TranslationActorData, TranslationResourceAbility, ?Model): bool)|null  $authorization
     * @param  (Closure(Builder<Model>): Builder<Model>)|null  $queryScope
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $modelClass,
        public array $searchableColumns = [],
        public array $displayColumns = [],
        public ?string $orderColumn = null,
        public int $maximumPageSize = 100,
        public ?Closure $authorization = null,
        public ?Closure $queryScope = null,
    ) {
        if (! preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $this->key)) {
            throw TranslationResourceException::invalid(
                "Translation resource key [{$this->key}] must use lowercase dot, dash, or underscore notation.",
            );
        }

        if (trim($this->label) === '') {
            throw TranslationResourceException::invalid("Translation resource [{$this->key}] requires a label.");
        }

        if ($this->maximumPageSize < 1 || $this->maximumPageSize > 500) {
            throw TranslationResourceException::invalid(
                "Translation resource [{$this->key}] page size must be between 1 and 500.",
            );
        }

        $model = $this->newModel();

        $this->assertColumns($this->searchableColumns, 'searchable');
        $this->assertColumns($this->displayColumns, 'display');

        if ($this->orderColumn !== null) {
            $this->assertColumns([$this->orderColumn], 'order');
        }

        $definition = $model->translationDefinition();
        $definition->supportedLocales();
        $definition->resolvedFallbackPolicy();
        $definition->configuredFallbackLocales();

        if ($model instanceof TranslatableModel && ! $definition instanceof RelatedTranslationDefinition) {
            throw TranslationResourceException::invalid(
                "Translation resource [{$this->key}] declares mismatched related-row storage.",
            );
        }

        if ($model instanceof TranslatableModel) {
            $definition->assertModel($model);

            if ($model->getConnection()->getName()
                !== $model->translations()->getRelated()->getConnection()->getName()) {
                throw TranslationResourceException::invalid(
                    "Translation resource [{$this->key}] owner and translation models must use the same connection.",
                );
            }
        }

        if ($model instanceof SelfTranslatableModel && ! $definition instanceof SelfTranslationDefinition) {
            throw TranslationResourceException::invalid(
                "Translation resource [{$this->key}] declares mismatched self-row storage.",
            );
        }

        if ($model instanceof SelfTranslatableModel) {
            $definition->assertModel($model);
        }

        if (! $model instanceof TranslatableModel && ! $model instanceof SelfTranslatableModel) {
            throw TranslationResourceException::invalid(
                "Translation resource [{$this->key}] must implement a supported translation storage contract.",
            );
        }
    }

    /**
     * Return a resource-specific authorization decision when configured.
     */
    public function authorize(
        TranslationActorData $actor,
        TranslationResourceAbility $ability,
        ?Model $record,
    ): ?bool {
        if ($this->authorization === null) {
            return null;
        }

        return (bool) ($this->authorization)($actor, $ability, $record);
    }

    /**
     * Create a fresh translatable model instance.
     */
    public function newModel(): Model&TranslatableResourceModel
    {
        if (! class_exists($this->modelClass)) {
            throw TranslationResourceException::invalid(
                "Translation resource model [{$this->modelClass}] does not exist.",
            );
        }

        $model = new $this->modelClass;

        if (! $model instanceof Model || ! $model instanceof TranslatableResourceModel) {
            throw TranslationResourceException::invalid(
                "Translation resource model [{$this->modelClass}] must implement TranslatableResourceModel.",
            );
        }

        return $model;
    }

    /**
     * Return serializable metadata for administrative consumers.
     *
     * @param  list<string>  $defaultLocales
     * @return array{
     *     key: string,
     *     label: string,
     *     model: class-string<Model>,
     *     table: string,
     *     translationTable: string,
     *     storage: string,
     *     mutationPolicy: string,
     *     keyName: string,
     *     fields: list<string>,
     *     locales: list<string>,
     *     searchableColumns: list<string>,
     *     displayColumns: list<string>
     * }
     */
    public function metadata(array $defaultLocales): array
    {
        $model = $this->newModel();
        $definition = $model->translationDefinition();
        $translationTable = $model instanceof TranslatableModel
            ? $model->translations()->getRelated()->getTable()
            : $model->getTable();
        $locales = match (true) {
            $defaultLocales === [] => $definition->supportedLocales(),
            default => array_values(array_intersect(
                $definition->supportedLocales(),
                $defaultLocales,
            )),
        };

        return [
            'key' => $this->key,
            'label' => $this->label,
            'model' => $model::class,
            'table' => $model->getTable(),
            'translationTable' => $translationTable,
            'storage' => $definition->storage()->value,
            'mutationPolicy' => $definition->mutationPolicy->value,
            'keyName' => $definition instanceof SelfTranslationDefinition
                ? $definition->groupKey
                : $model->getKeyName(),
            'fields' => $definition->fields,
            'locales' => array_values(array_unique($locales)),
            'searchableColumns' => $this->searchableColumns,
            'displayColumns' => $this->displayColumns,
        ];
    }

    /**
     * Assert one resource-column list is safe and duplicate-free.
     *
     * @param  list<mixed>  $columns
     */
    private function assertColumns(array $columns, string $purpose): void
    {
        $seen = [];

        foreach ($columns as $column) {
            if (! is_string($column)
                || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column) !== 1) {
                $value = is_scalar($column) ? (string) $column : get_debug_type($column);

                throw TranslationResourceException::invalid(
                    "Translation resource [{$this->key}] contains an unsafe {$purpose} column [{$value}].",
                );
            }

            $normalized = mb_strtolower($column);

            if (in_array($normalized, $seen, true)) {
                throw TranslationResourceException::invalid(
                    "Translation resource [{$this->key}] contains duplicate {$purpose} column [{$column}].",
                );
            }

            $seen[] = $normalized;
        }
    }
}
