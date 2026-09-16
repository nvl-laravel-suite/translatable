<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Builder;
use Nvl\Tenancy\Enums\TenantResourceKind;
use Nvl\Tenancy\Services\TenantInstallationState;
use Nvl\Tenancy\Services\TenantResourceRegistry;
use Nvl\Translatable\Contracts\TranslatableModel;
use Nvl\Translatable\Contracts\TranslatableResourceModel;
use Nvl\Translatable\Enums\TranslationFallbackPolicy;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\SelfTranslationDefinition;
use Nvl\Translatable\Support\LocaleCode;
use Nvl\Translatable\TranslationDefinition;
use Nvl\Translatable\TranslationDiagnosticReport;
use Nvl\Translatable\TranslationResourceDefinition;
use Throwable;

/**
 * Audits global configuration and registered model schemas against translation declarations.
 */
final readonly class TranslationDoctor
{
    /**
     * Create the translation diagnostics service.
     */
    public function __construct(
        private Repository $config,
        private TranslationResourceRegistry $resources,
        private TranslationOwnership $ownership,
        private TenantResourceRegistry $tenantResources,
        private TenantInstallationState $installation,
    ) {}

    /**
     * Inspect global configuration and every registered translation resource.
     */
    public function inspect(): TranslationDiagnosticReport
    {
        $errors = $this->configurationErrors();
        $warnings = $this->configurationWarnings();

        foreach ($this->resources->all() as $resource) {
            try {
                $this->inspectResource($resource, $errors);
            } catch (Throwable $exception) {
                $errors[] = "Resource [{$resource->key}] inspection failed: {$exception->getMessage()}";
            }
        }

        return new TranslationDiagnosticReport(
            errors: array_values(array_unique($errors)),
            warnings: array_values(array_unique($warnings)),
            checkedResources: count($this->resources->all()),
        );
    }

    /**
     * Return invalid global configuration findings.
     *
     * @return list<string>
     */
    private function configurationErrors(): array
    {
        $errors = [];
        $configuredLocales = $this->config->get('translatable.locales');

        if (! is_array($configuredLocales) || $configuredLocales === []) {
            $errors[] = 'translatable.locales must contain at least one locale.';
            $configuredLocales = [];
        }

        $locales = [];

        foreach ($configuredLocales as $locale) {
            if (! is_string($locale)) {
                $errors[] = 'Every translatable.locales value must be a string.';

                continue;
            }

            try {
                $normalized = (new LocaleCode($locale))->value;
            } catch (Throwable $exception) {
                $errors[] = "Configured locale [{$locale}] is invalid: {$exception->getMessage()}";

                continue;
            }

            if (in_array($normalized, $locales, true)) {
                $errors[] = "Configured locales contain duplicate normalized locale [{$normalized}].";
            }

            $locales[] = $normalized;
        }

        $default = $this->config->get('translatable.default_locale');

        if (! is_string($default) || ! $this->containsLocale($locales, $default)) {
            $errors[] = 'translatable.default_locale must be one of the supported locales.';
        }

        $fallbacks = $this->config->get('translatable.fallback_locales', []);

        if (! is_array($fallbacks)) {
            $errors[] = 'translatable.fallback_locales must be an array.';
        } else {
            $normalizedFallbacks = [];

            foreach ($fallbacks as $fallback) {
                if (! is_string($fallback) || ! $this->containsLocale($locales, $fallback)) {
                    $errors[] = 'Every configured fallback locale must be supported.';

                    continue;
                }

                $normalizedFallback = (new LocaleCode($fallback))->value;

                if (in_array($normalizedFallback, $normalizedFallbacks, true)) {
                    $errors[] = "Configured fallback locales contain duplicate normalized locale [{$normalizedFallback}].";
                }

                $normalizedFallbacks[] = $normalizedFallback;
            }
        }

        $policy = $this->config->get('translatable.fallback.policy');

        if (! is_string($policy) || TranslationFallbackPolicy::tryFrom($policy) === null) {
            $errors[] = 'translatable.fallback.policy is invalid.';
        }

        if (! is_bool($this->config->get('translatable.fallback.on_null'))) {
            $errors[] = 'translatable.fallback.on_null must be boolean.';
        }

        foreach ([
            'mutation_locales',
            'mutation_fields',
            'mutation_value_bytes',
            'mutation_depth',
        ] as $limit) {
            $value = $this->config->get("translatable.limits.{$limit}");

            if (! is_int($value) || $value < 1) {
                $errors[] = "translatable.limits.{$limit} must be a positive integer.";
            }
        }

        $transactionAttempts = $this->config->get('translatable.transactions.attempts');

        if (! is_int($transactionAttempts) || $transactionAttempts < 1) {
            $errors[] = 'translatable.transactions.attempts must be a positive integer.';
        }

        foreach (['query_parameter', 'session_key', 'cookie_name'] as $option) {
            $value = $this->config->get("translatable.middleware.{$option}");

            if ($value !== null && (! is_string($value) || trim($value) === '')) {
                $errors[] = "translatable.middleware.{$option} must be a non-empty string or null.";
            }
        }

        $cookieMinutes = $this->config->get('translatable.middleware.cookie_minutes');

        if (! is_int($cookieMinutes) || $cookieMinutes < 1) {
            $errors[] = 'translatable.middleware.cookie_minutes must be a positive integer.';
        }

        return $errors;
    }

    /**
     * Return non-blocking global configuration findings.
     *
     * @return list<string>
     */
    private function configurationWarnings(): array
    {
        $configuredLocales = $this->config->get('translatable.locales', []);
        $labels = $this->config->get('translatable.labels', []);

        if (! is_array($configuredLocales) || ! is_array($labels)) {
            return [];
        }

        $warnings = [];

        foreach ($configuredLocales as $locale) {
            if (! is_string($locale)) {
                continue;
            }

            try {
                $normalizedLocale = (new LocaleCode($locale))->value;
            } catch (Throwable) {
                continue;
            }

            $localeLabels = $labels[$normalizedLocale] ?? null;

            if (! is_array($localeLabels)
                || ! is_string($localeLabels['international'] ?? null)
                || ! is_string($localeLabels['native'] ?? null)) {
                $warnings[] = "Configured locale [{$normalizedLocale}] has incomplete labels.";
            }
        }

        return $warnings;
    }

    /**
     * Inspect one registered resource declaration and its database schema.
     *
     * @param  list<string>  $errors
     */
    private function inspectResource(
        TranslationResourceDefinition $resource,
        array &$errors,
    ): void {
        $model = $resource->newModel();
        $definition = $model->translationDefinition();
        $schema = $model->getConnection()->getSchemaBuilder();
        $table = $model->getTable();
        $this->assertModelLocales($resource, $definition, $errors);
        $partitionColumns = $this->ownershipColumns($resource, $model, $definition, $errors);

        if (! $schema->hasTable($table)) {
            $errors[] = "Resource [{$resource->key}] is missing table [{$table}].";

            return;
        }

        $resourceColumns = [
            ...$resource->searchableColumns,
            ...$resource->displayColumns,
            ...($resource->orderColumn !== null ? [$resource->orderColumn] : []),
        ];
        $this->assertColumns(
            $resource,
            $schema,
            $table,
            array_values(array_unique([
                ...$resourceColumns,
                ...$this->ownershipSchemaColumns($definition, $partitionColumns),
            ])),
            $errors,
        );
        $this->assertImmutableExclusions($resource, $definition, $errors);

        if ($definition instanceof SelfTranslationDefinition) {
            $this->inspectSelfResource(
                $resource,
                $model,
                $definition,
                $schema,
                $partitionColumns,
                $errors,
            );

            return;
        }

        if ($definition instanceof RelatedTranslationDefinition
            && $model instanceof TranslatableModel) {
            $this->inspectRelatedResource(
                $resource,
                $model,
                $definition,
                $schema,
                $partitionColumns,
                $errors,
            );
        }
    }

    /**
     * Inspect a related-row resource schema and connection invariants.
     *
     * @param  list<string>  $partitionColumns
     * @param  list<string>  $errors
     */
    private function inspectRelatedResource(
        TranslationResourceDefinition $resource,
        Model&TranslatableModel $model,
        RelatedTranslationDefinition $definition,
        Builder $ownerSchema,
        array $partitionColumns,
        array &$errors,
    ): void {
        $translationModel = Relation::noConstraints(fn () => $model->translations()->getRelated());
        $translationSchema = $translationModel->getConnection()->getSchemaBuilder();
        $translationTable = $translationModel->getTable();
        $foreignKey = $definition->foreignKey($model->getTable());

        if ($model->getConnection() !== $translationModel->getConnection()) {
            $errors[] = "Resource [{$resource->key}] owner and translation models use different actual connections.";
        }
        $this->inspectRelatedOwnership($resource, $model, $translationModel, $definition, $errors);

        $this->assertColumns(
            $resource,
            $ownerSchema,
            $model->getTable(),
            [...$this->ownershipSchemaColumns($definition, $partitionColumns), $definition->ownerKey],
            $errors,
        );
        $this->assertUniqueIndex(
            $resource,
            $ownerSchema,
            $model->getTable(),
            [...$partitionColumns, $definition->ownerKey],
            $errors,
        );

        if (! $translationSchema->hasTable($translationTable)) {
            $errors[] = "Resource [{$resource->key}] is missing translation table [{$translationTable}].";

            return;
        }

        $this->assertColumns(
            $resource,
            $translationSchema,
            $translationTable,
            [
                ...$this->ownershipSchemaColumns($definition, $partitionColumns),
                $foreignKey,
                $definition->localeKey,
                ...$definition->fields,
            ],
            $errors,
        );
        $this->assertNonNullableColumns(
            $resource,
            $translationSchema,
            $translationTable,
            [...$partitionColumns, $foreignKey, $definition->localeKey],
            $errors,
        );
        $this->assertLocaleColumn(
            $resource,
            $translationSchema,
            $translationTable,
            $definition->localeKey,
            $errors,
        );
        $this->assertUniqueIndex(
            $resource,
            $translationSchema,
            $translationTable,
            [...$partitionColumns, $foreignKey, $definition->localeKey],
            $errors,
        );
        $foreignKeys = $translationSchema->getForeignKeys($translationTable);
        $foreignColumns = [...$partitionColumns, $foreignKey];
        $ownerColumns = [...$partitionColumns, $definition->ownerKey];
        $matchingForeignKey = collect($foreignKeys)->first(
            static fn (array $key): bool => $key['columns'] === $foreignColumns
                && $key['foreign_table'] === $model->getTable()
                && $key['foreign_columns'] === $ownerColumns,
        );

        if (! is_array($matchingForeignKey)) {
            $errors[] = "Resource [{$resource->key}] table [{$translationTable}] lacks its owner foreign key and requires composite columns (["
                .implode(', ', $foreignColumns)."] -> [{$model->getTable()}."
                .implode(', ', $ownerColumns).']).';
        } elseif (mb_strtolower((string) $matchingForeignKey['on_delete']) !== 'cascade') {
            $errors[] = "Resource [{$resource->key}] owner foreign key must cascade on delete.";
        }
    }

    /**
     * Diagnose the related child registration, inherited parent, connection and adoption contract.
     *
     * @param  list<string>  $errors
     */
    private function inspectRelatedOwnership(
        TranslationResourceDefinition $resource,
        Model&TranslatableModel $owner,
        Model $translation,
        RelatedTranslationDefinition $definition,
        array &$errors,
    ): void {
        if ($definition->ownershipResource === null) {
            return;
        }

        try {
            $registered = $this->tenantResources->forModel($translation);
            $canonical = new $registered->model;
            if ($canonical::class !== $translation::class
                || $canonical->getTable() !== $translation->getTable()
                || $canonical->getConnection() !== $translation->getConnection()) {
                $errors[] = "Resource [{$resource->key}] related translation ownership must register its exact model, table, and actual connection.";
            }
        } catch (Throwable $exception) {
            $translationClass = $translation::class;
            $errors[] = "Resource [{$resource->key}] related translation model [{$translationClass}] is not registered: {$exception->getMessage()}";

            return;
        }

        if ($registered->kind !== TenantResourceKind::Inherited
            || $registered->parentResource !== $definition->ownershipResource
            || $registered->parentRelation === null) {
            $errors[] = "Resource [{$resource->key}] related translation ownership must inherit from [{$definition->ownershipResource}].";
        } else {
            try {
                $relation = Relation::noConstraints(fn () => $canonical->{$registered->parentRelation}());
                $parent = $relation instanceof BelongsTo ? $relation->getRelated() : null;
                if (! $parent instanceof Model
                    || $parent::class !== $owner::class
                    || $parent->getTable() !== $owner->getTable()
                    || $parent->getConnection() !== $owner->getConnection()) {
                    $errors[] = "Resource [{$resource->key}] related translation ownership must resolve its exact inherited parent.";
                }
            } catch (Throwable $exception) {
                $errors[] = "Resource [{$resource->key}] related translation inherited parent is invalid: {$exception->getMessage()}";
            }
        }

        try {
            $this->installation->assertUsable($registered->key);
        } catch (Throwable $exception) {
            $errors[] = "Resource [{$resource->key}] related translation ownership adoption is incompatible: {$exception->getMessage()}";
        }
    }

    /**
     * Inspect a self-row resource schema and logical-group uniqueness.
     *
     * @param  list<string>  $partitionColumns
     * @param  list<string>  $errors
     */
    private function inspectSelfResource(
        TranslationResourceDefinition $resource,
        Model&TranslatableResourceModel $model,
        SelfTranslationDefinition $definition,
        Builder $schema,
        array $partitionColumns,
        array &$errors,
    ): void {
        $this->assertColumns(
            $resource,
            $schema,
            $model->getTable(),
            [
                $definition->groupKey,
                $definition->localeKey,
                ...$this->ownershipSchemaColumns($definition, $partitionColumns),
                ...$definition->fields,
                ...$definition->sharedFields,
            ],
            $errors,
        );
        $this->assertUniqueIndex(
            $resource,
            $schema,
            $model->getTable(),
            [...$partitionColumns, $definition->groupKey, $definition->localeKey],
            $errors,
        );
        $this->assertNonNullableColumns(
            $resource,
            $schema,
            $model->getTable(),
            [...$partitionColumns, $definition->groupKey, $definition->localeKey],
            $errors,
        );
        $this->assertLocaleColumn(
            $resource,
            $schema,
            $model->getTable(),
            $definition->localeKey,
            $errors,
        );
    }

    /**
     * Resolve declared ownership metadata and diagnose registration/adoption compatibility.
     *
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function ownershipColumns(
        TranslationResourceDefinition $resource,
        Model&TranslatableResourceModel $model,
        TranslationDefinition $definition,
        array &$errors,
    ): array {
        if ($definition->ownershipResource === null) {
            if ($this->config->get('tenancy.enabled') === true) {
                $errors[] = "Resource [{$resource->key}] must declare an ownership resource key before tenancy is enabled.";
            }

            try {
                $this->installation->assertUnadopted($model->getConnection());
            } catch (Throwable $exception) {
                $errors[] = "Resource [{$resource->key}] legacy storage is incompatible with persisted adoption: {$exception->getMessage()}";
            }

            return [];
        }

        try {
            $registered = $this->tenantResources->get($definition->ownershipResource);
            $canonical = new $registered->model;
            if ($canonical::class !== $model::class
                || $canonical->getTable() !== $model->getTable()
                || $canonical->getConnection() !== $model->getConnection()) {
                $errors[] = "Resource [{$resource->key}] ownership [{$definition->ownershipResource}] must register its exact model, table, and connection.";
            }
        } catch (Throwable $exception) {
            $errors[] = "Resource [{$resource->key}] ownership [{$definition->ownershipResource}] is not registered: {$exception->getMessage()}";

            return [];
        }

        try {
            return $this->ownership->partitionColumns($definition);
        } catch (Throwable $exception) {
            $errors[] = "Resource [{$resource->key}] ownership adoption is incompatible: {$exception->getMessage()}";

            return [];
        }
    }

    /**
     * Return physical ownership columns required by declared tenant storage.
     *
     * @param  list<string>  $partitionColumns
     * @return list<string>
     */
    private function ownershipSchemaColumns(
        TranslationDefinition $definition,
        array $partitionColumns,
    ): array {
        if ($definition->ownershipResource === null || $partitionColumns === []) {
            return [];
        }

        return array_values(array_unique(['tenant_id', ...$partitionColumns]));
    }

    /**
     * Diagnose structural identity mistakenly exposed as mutable translation payload.
     *
     * @param  list<string>  $errors
     */
    private function assertImmutableExclusions(
        TranslationResourceDefinition $resource,
        TranslationDefinition $definition,
        array &$errors,
    ): void {
        $mutable = $definition->fields;
        if ($definition instanceof SelfTranslationDefinition) {
            $mutable = [...$mutable, ...$definition->sharedFields];
        }
        $structural = ['tenant_id', 'ownership_key', $definition->localeKey];
        if ($definition instanceof SelfTranslationDefinition) {
            $structural[] = $definition->groupKey;
        }
        $invalid = array_values(array_intersect(
            array_map(mb_strtolower(...), $mutable),
            array_map(mb_strtolower(...), $structural),
        ));

        if ($invalid !== []) {
            $errors[] = "Resource [{$resource->key}] must exclude immutable translation identity columns: "
                .implode(', ', $invalid).'.';
        }
    }

    /**
     * Assert that a table contains every declared column.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $errors
     */
    private function assertColumns(
        TranslationResourceDefinition $resource,
        Builder $schema,
        string $table,
        array $columns,
        array &$errors,
    ): void {
        $existing = $schema->getColumnListing($table);

        foreach ($columns as $column) {
            if (! in_array($column, $existing, true)) {
                $errors[] = "Resource [{$resource->key}] table [{$table}] lacks column [{$column}].";
            }
        }
    }

    /**
     * Assert that a table contains a unique index over the exact declared columns.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $errors
     */
    private function assertUniqueIndex(
        TranslationResourceDefinition $resource,
        Builder $schema,
        string $table,
        array $columns,
        array &$errors,
    ): void {
        $hasUnique = collect($schema->getIndexes($table))->contains(
            static fn (array $index): bool => $index['unique']
                && $index['columns'] === $columns,
        );

        if (! $hasUnique) {
            $index = $table.'_'.implode('_', $columns).'_unique';
            $errors[] = "Resource [{$resource->key}] table [{$table}] requires a unique index [{$index}] on ["
                .implode(', ', $columns).'].';
        }
    }

    /**
     * Assert that structural translation columns cannot contain null.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $errors
     */
    private function assertNonNullableColumns(
        TranslationResourceDefinition $resource,
        Builder $schema,
        string $table,
        array $columns,
        array &$errors,
    ): void {
        $metadata = collect($schema->getColumns($table))->keyBy('name');

        foreach ($columns as $column) {
            $definition = $metadata->get($column);

            if (is_array($definition) && $definition['nullable']) {
                $errors[] = "Resource [{$resource->key}] structural column [{$table}.{$column}] cannot be nullable.";
            }
        }
    }

    /**
     * Assert that a locale column uses a string type with sufficient declared capacity.
     *
     * @param  list<string>  $errors
     */
    private function assertLocaleColumn(
        TranslationResourceDefinition $resource,
        Builder $schema,
        string $table,
        string $column,
        array &$errors,
    ): void {
        $metadata = collect($schema->getColumns($table))->firstWhere('name', $column);

        if (! is_array($metadata)) {
            return;
        }

        $typeName = mb_strtolower($metadata['type_name']);
        $declaredType = mb_strtolower($metadata['type']);
        $isString = str_contains($typeName, 'char')
            || str_contains($typeName, 'text')
            || in_array($typeName, ['citext', 'string'], true);

        if (! $isString) {
            $errors[] = "Resource [{$resource->key}] locale column [{$table}.{$column}] must use a string type.";

            return;
        }

        if (preg_match('/(?:char|varchar|character varying)\s*\((\d+)\)/', $declaredType, $matches) === 1
            && (int) $matches[1] < 35) {
            $errors[] = "Resource [{$resource->key}] locale column [{$table}.{$column}] must support at least 35 characters.";
        }
    }

    /**
     * Assert that model-level locale overrides only narrow the global catalog.
     *
     * @param  list<string>  $errors
     */
    private function assertModelLocales(
        TranslationResourceDefinition $resource,
        TranslationDefinition $definition,
        array &$errors,
    ): void {
        $configured = $this->config->get('translatable.locales', []);

        if (! is_array($configured)) {
            return;
        }

        $globalLocales = [];

        foreach ($configured as $locale) {
            if (! is_string($locale)) {
                continue;
            }

            try {
                $globalLocales[] = (new LocaleCode($locale))->value;
            } catch (Throwable) {
                continue;
            }
        }

        $unsupported = array_values(array_diff(
            $definition->supportedLocales(),
            array_values(array_unique($globalLocales)),
        ));

        if ($unsupported !== []) {
            $errors[] = "Resource [{$resource->key}] model locales must be a subset of translatable.locales: "
                .implode(', ', $unsupported).'.';
        }
    }

    /**
     * Determine whether a normalized locale belongs to a locale list.
     *
     * @param  list<string>  $locales
     */
    private function containsLocale(array $locales, string $candidate): bool
    {
        try {
            return in_array((new LocaleCode($candidate))->value, $locales, true);
        } catch (Throwable) {
            return false;
        }
    }
}
