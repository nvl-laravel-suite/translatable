<?php

declare(strict_types=1);

namespace Nvl\Translatable\Actions;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Nvl\Translatable\Contracts\TranslatableResourceModel;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Data\TranslationMutationData;
use Nvl\Translatable\Data\TranslationMutationResultData;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\Events\TranslationResourceSynced;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\Services\LocaleRegistry;
use Nvl\Translatable\Services\TranslationResourceAuthorization;
use Nvl\Translatable\Services\TranslationResourceLocator;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Services\TranslationResourceVersioner;
use Nvl\Translatable\Services\TranslationWriter;

/**
 * Synchronizes locale rows for any owner registered in the central resource catalog.
 */
final readonly class SyncTranslationResourceAction
{
    /**
     * Create the centralized translation synchronization action.
     */
    public function __construct(
        private TranslationResourceRegistry $resources,
        private LocaleRegistry $locales,
        private TranslationWriter $writer,
        private TranslationResourceAuthorization $authorization,
        private TranslationResourceVersioner $versioner,
        private TranslationResourceLocator $locator,
        private Repository $config,
    ) {}

    /**
     * Synchronize a registered owner's translations and return the refreshed model.
     */
    public function execute(
        string $resourceKey,
        int|string $id,
        TranslationMutationData $mutation,
        TranslationActorData $actor,
    ): TranslationMutationResultData {
        $resource = $this->resources->get($resourceKey);
        $connection = $resource->newModel()->getConnection();

        return $connection->transaction(function () use (
            $connection,
            $resource,
            $resourceKey,
            $id,
            $mutation,
            $actor,
        ): TranslationMutationResultData {
            $owner = $this->locator->lock($resource, $id);
            $this->authorization->authorize(
                $actor,
                TranslationResourceAbility::Synchronize,
                $resource,
                $owner,
            );

            if ($owner->translationDefinition()->mutationPolicy
                === TranslationMutationPolicy::DomainActionOnly) {
                throw TranslationResourceException::requiresDomainAction($resourceKey);
            }

            $previousVersion = $this->versioner->version($owner);

            if (! hash_equals($previousVersion, $mutation->expectedVersion)) {
                throw TranslationResourceException::stale($resourceKey);
            }

            $locales = $this->validatePayload($resourceKey, $owner, $mutation->translations);
            $this->writer->sync($owner, $mutation->translations, $mutation->mode);
            $this->locator->loadTranslations(new Collection([$owner]));
            $version = $this->versioner->version($owner);
            $resourceId = $owner->translationResourceKey();

            $connection->afterCommit(static function () use (
                $resourceKey,
                $resourceId,
                $owner,
                $locales,
                $mutation,
                $actor,
                $previousVersion,
                $version,
            ): void {
                Event::dispatch(new TranslationResourceSynced(
                    resource: $resourceKey,
                    ownerType: $owner::class,
                    ownerId: $resourceId,
                    locales: $locales,
                    mode: $mutation->mode,
                    actor: $actor,
                    previousVersion: $previousVersion,
                    version: $version,
                ));
            });

            return new TranslationMutationResultData(
                resource: $resourceKey,
                id: $resourceId,
                locales: $locales,
                version: $version,
            );
        }, $this->transactionAttempts());
    }

    /**
     * Validate supported locales and declared translated fields.
     *
     * @param  array<array-key, mixed>  $translations
     * @return list<string>
     */
    private function validatePayload(
        string $resourceKey,
        Model&TranslatableResourceModel $owner,
        array $translations,
    ): array {
        $definition = $owner->translationDefinition();
        $locales = [];

        foreach ($translations as $locale => $attributes) {
            if (! is_string($locale) || ! is_array($attributes)) {
                throw TranslationResourceException::invalid(
                    "Translation resource [{$resourceKey}] requires string locale keys containing field-keyed arrays.",
                );
            }

            $normalizedLocale = $definition->assertLocale($this->locales->assertSupported($locale));

            if (in_array($normalizedLocale, $locales, true)) {
                throw TranslationResourceException::duplicateLocale($resourceKey, $locale);
            }

            $locales[] = $normalizedLocale;

            foreach (array_keys($attributes) as $field) {
                if (! is_string($field)) {
                    throw TranslationResourceException::invalid(
                        "Translation resource [{$resourceKey}] locale [{$locale}] requires string field keys.",
                    );
                }
            }

            $undeclared = array_values(array_diff(array_keys($attributes), $definition->fields));

            if ($undeclared !== []) {
                throw TranslationResourceException::undeclaredFields($resourceKey, $locale, $undeclared);
            }
        }

        return $locales;
    }

    /**
     * Return the validated deadlock retry count for centralized writes.
     *
     * @return positive-int
     */
    private function transactionAttempts(): int
    {
        $attempts = $this->config->get('translatable.transactions.attempts', 3);

        if (! is_int($attempts) || $attempts < 1) {
            throw TranslationResourceException::invalid(
                'The translatable.transactions.attempts value must be a positive integer.',
            );
        }

        return $attempts;
    }
}
