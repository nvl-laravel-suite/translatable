<?php

declare(strict_types=1);

namespace Nvl\Translatable\Providers;

use Illuminate\Support\ServiceProvider;
use Nvl\Data\Services\TypeScriptSourceRegistry;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Translatable\Actions\DeleteTranslationResourceLocaleAction;
use Nvl\Translatable\Actions\SyncTranslationResourceAction;
use Nvl\Translatable\Console\Commands\GatherTranslationResourcesCommand;
use Nvl\Translatable\Console\Commands\TranslatableDoctorCommand;
use Nvl\Translatable\Contracts\ContentLocalePreferenceResolver;
use Nvl\Translatable\Contracts\TranslationResourceAuthorizer;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\Services\ContentLocale;
use Nvl\Translatable\Services\LocaleRegistry;
use Nvl\Translatable\Services\NullContentLocalePreferenceResolver;
use Nvl\Translatable\Services\RelatedTranslationStore;
use Nvl\Translatable\Services\SelfTranslationStore;
use Nvl\Translatable\Services\SystemTranslationResourceAuthorizer;
use Nvl\Translatable\Services\TranslationDoctor;
use Nvl\Translatable\Services\TranslationPayloadValidator;
use Nvl\Translatable\Services\TranslationResolver;
use Nvl\Translatable\Services\TranslationResourceAuthorization;
use Nvl\Translatable\Services\TranslationResourceGatherer;
use Nvl\Translatable\Services\TranslationResourceLocator;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Services\TranslationResourceVersioner;
use Nvl\Translatable\Services\TranslationWriter;

/**
 * Registers the shared translation runtime and its publishable package resources.
 */
final class TranslatableServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;

    /**
     * Register translation services and default integrations.
     */
    public function register(): void
    {
        $this->mergePackageConfiguration(
            __DIR__.'/../../config/translatable.php',
            'translatable',
        );

        $this->app->singleton(LocaleRegistry::class);
        $this->app->scoped(ContentLocale::class);
        $this->app->singleton(TranslationResolver::class);
        $this->app->singleton(TranslationDoctor::class);
        $this->app->singleton(TranslationPayloadValidator::class);
        $this->app->singleton(RelatedTranslationStore::class);
        $this->app->singleton(SelfTranslationStore::class);
        $this->app->singleton(TranslationWriter::class);
        $this->app->singleton(TranslationResourceRegistry::class);
        $this->app->singleton(TranslationResourceGatherer::class);
        $this->app->singleton(TranslationResourceLocator::class);
        $this->app->singleton(TranslationResourceVersioner::class);
        $this->app->singleton(TranslationResourceAuthorization::class);
        $this->app->bind(SyncTranslationResourceAction::class);
        $this->app->bind(DeleteTranslationResourceLocaleAction::class);
        $this->app->bind(
            ContentLocalePreferenceResolver::class,
            NullContentLocalePreferenceResolver::class,
        );
        $this->app->bind(
            TranslationResourceAuthorizer::class,
            SystemTranslationResourceAuthorizer::class,
        );
    }

    /**
     * Publish translation configuration and agentic skills.
     */
    public function boot(
        TranslationResourceRegistry $resources,
        TypeScriptSourceRegistry $typeScriptSources,
    ): void {
        $typeScriptSources->register(__DIR__.'/..', 'nvl/translatable');

        $this->publishes([
            __DIR__.'/../../config/translatable.php' => config_path('translatable.php'),
        ], 'translatable-config');

        $this->publishes([
            __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
        ], 'translatable-skills');

        $this->registerConfiguredResources($resources);

        if ($this->app->runningInConsole()) {
            $this->commands([
                GatherTranslationResourcesCommand::class,
                TranslatableDoctorCommand::class,
            ]);
        }
    }

    /**
     * Register host resources declared in package configuration.
     */
    private function registerConfiguredResources(TranslationResourceRegistry $resources): void
    {
        $configuredResources = config('translatable.resources', []);

        if (! is_array($configuredResources)) {
            throw TranslationResourceException::invalid(
                'The translatable.resources configuration value must be an array.',
            );
        }

        foreach ($configuredResources as $key => $definition) {
            if (! is_string($key) || ! is_array($definition)) {
                throw TranslationResourceException::invalid(
                    'Every translatable.resources entry must use a string key and array definition.',
                );
            }

            $allowedKeys = [
                'model',
                'label',
                'searchable_columns',
                'display_columns',
                'order_column',
                'maximum_page_size',
            ];
            $unknownKeys = array_values(array_diff(array_keys($definition), $allowedKeys));

            if ($unknownKeys !== []) {
                throw TranslationResourceException::invalid(
                    "Configured translation resource [{$key}] contains unknown options: "
                    .implode(', ', array_map(static fn (int|string $option): string => (string) $option, $unknownKeys))
                    .'.',
                );
            }

            $model = $definition['model'] ?? null;
            $label = $definition['label'] ?? null;

            if (! is_string($model) || ! class_exists($model) || ! is_string($label)) {
                throw TranslationResourceException::invalid(
                    "Configured translation resource [{$key}] requires an existing model class and string label.",
                );
            }

            $orderColumn = $definition['order_column'] ?? null;

            if ($orderColumn !== null && ! is_string($orderColumn)) {
                throw TranslationResourceException::invalid(
                    "Configured translation resource [{$key}] order_column must be a string or null.",
                );
            }

            $maximumPageSize = $definition['maximum_page_size'] ?? 100;

            if (! is_int($maximumPageSize)) {
                throw TranslationResourceException::invalid(
                    "Configured translation resource [{$key}] maximum_page_size must be an integer.",
                );
            }

            $resources->register(
                key: $key,
                modelClass: $model,
                label: $label,
                searchableColumns: $this->stringList(
                    $definition['searchable_columns'] ?? [],
                    "{$key}.searchable_columns",
                ),
                displayColumns: $this->stringList(
                    $definition['display_columns'] ?? [],
                    "{$key}.display_columns",
                ),
                orderColumn: $orderColumn,
                maximumPageSize: $maximumPageSize,
            );
        }
    }

    /**
     * Normalize a configured list to non-empty strings.
     *
     * @return list<string>
     */
    private function stringList(mixed $values, string $path): array
    {
        if (! is_array($values)) {
            throw TranslationResourceException::invalid(
                "Configured translation resource [{$path}] must be an array of strings.",
            );
        }

        $strings = [];

        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                throw TranslationResourceException::invalid(
                    "Configured translation resource [{$path}] must contain only non-empty strings.",
                );
            }

            $strings[] = $value;
        }

        return $strings;
    }
}
