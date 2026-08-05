<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Builder;
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
            array_values(array_unique($resourceColumns)),
            $errors,
        );

        if ($definition instanceof SelfTranslationDefinition) {
            $this->inspectSelfResource($resource, $model, $definition, $schema, $errors);

            return;
        }

        if ($definition instanceof RelatedTranslationDefinition
            && $model instanceof TranslatableModel) {
            $this->inspectRelatedResource(
                $resource,
                $model,
                $definition,
                $schema,
                $errors,
            );
        }
    }

    /**
     * Inspect a related-row resource schema and connection invariants.
     *
     * @param  list<string>  $errors
     */
    private function inspectRelatedResource(
        TranslationResourceDefinition $resource,
        Model&TranslatableModel $model,
        RelatedTranslationDefinition $definition,
        Builder $ownerSchema,
        array &$errors,
    ): void {
        $translationModel = $model->translations()->getRelated();
        $translationSchema = $translationModel->getConnection()->getSchemaBuilder();
        $translationTable = $translationModel->getTable();
        $foreignKey = $definition->foreignKey($model->getTable());

        if ($model->getConnection()->getName() !== $translationModel->getConnection()->getName()) {
            $errors[] = "Resource [{$resource->key}] owner and translation models use different connections.";
        }

        $this->assertColumns(
            $resource,
            $ownerSchema,
            $model->getTable(),
            [$definition->ownerKey],
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
            [$foreignKey, $definition->localeKey, ...$definition->fields],
            $errors,
        );
        $this->assertNonNullableColumns(
            $resource,
            $translationSchema,
            $translationTable,
            [$foreignKey, $definition->localeKey],
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
            [$foreignKey, $definition->localeKey],
            $errors,
        );
        $foreignKeys = $translationSchema->getForeignKeys($translationTable);
        $matchingForeignKey = collect($foreignKeys)->first(
            static fn (array $key): bool => $key['columns'] === [$foreignKey]
                && $key['foreign_table'] === $model->getTable()
                && $key['foreign_columns'] === [$definition->ownerKey],
        );

        if (! is_array($matchingForeignKey)) {
            $errors[] = "Resource [{$resource->key}] lacks its owner foreign key.";
        } elseif (mb_strtolower((string) $matchingForeignKey['on_delete']) !== 'cascade') {
            $errors[] = "Resource [{$resource->key}] owner foreign key must cascade on delete.";
        }
    }

    /**
     * Inspect a self-row resource schema and logical-group uniqueness.
     *
     * @param  list<string>  $errors
     */
    private function inspectSelfResource(
        TranslationResourceDefinition $resource,
        Model&TranslatableResourceModel $model,
        SelfTranslationDefinition $definition,
        Builder $schema,
        array &$errors,
    ): void {
        $this->assertColumns(
            $resource,
            $schema,
            $model->getTable(),
            [
                $definition->groupKey,
                $definition->localeKey,
                ...$definition->fields,
                ...$definition->sharedFields,
            ],
            $errors,
        );
        $this->assertUniqueIndex(
            $resource,
            $schema,
            $model->getTable(),
            [$definition->groupKey, $definition->localeKey],
            $errors,
        );
        $this->assertNonNullableColumns(
            $resource,
            $schema,
            $model->getTable(),
            [$definition->groupKey, $definition->localeKey],
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
            $errors[] = "Resource [{$resource->key}] table [{$table}] requires a unique index on ["
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
