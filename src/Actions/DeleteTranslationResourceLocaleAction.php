<?php

declare(strict_types=1);

namespace Nvl\Translatable\Actions;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use Nvl\Translatable\Data\DeleteTranslationLocaleData;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Data\TranslationDeleteResultData;
use Nvl\Translatable\Enums\TranslationMutationPolicy;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\Events\TranslationResourceLocaleDeleted;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\Services\LocaleRegistry;
use Nvl\Translatable\Services\TranslationResourceAuthorization;
use Nvl\Translatable\Services\TranslationResourceLocator;
use Nvl\Translatable\Services\TranslationResourceRegistry;
use Nvl\Translatable\Services\TranslationResourceVersioner;
use Nvl\Translatable\Services\TranslationWriter;

/**
 * Deletes one locale row from an owner registered in the central resource catalog.
 */
final readonly class DeleteTranslationResourceLocaleAction
{
    /**
     * Create the centralized locale deletion action.
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
     * Delete one locale row from a registered owner.
     */
    public function execute(
        string $resourceKey,
        int|string $id,
        DeleteTranslationLocaleData $deletion,
        TranslationActorData $actor,
    ): TranslationDeleteResultData {
        $resource = $this->resources->get($resourceKey);
        $connection = $resource->newModel()->getConnection();

        return $connection->transaction(function () use (
            $connection,
            $resource,
            $resourceKey,
            $id,
            $deletion,
            $actor,
        ): TranslationDeleteResultData {
            $owner = $this->locator->lock($resource, $id);
            $this->authorization->authorize(
                $actor,
                TranslationResourceAbility::Delete,
                $resource,
                $owner,
            );

            if ($owner->translationDefinition()->mutationPolicy
                === TranslationMutationPolicy::DomainActionOnly) {
                throw TranslationResourceException::requiresDomainAction($resourceKey);
            }

            $previousVersion = $this->versioner->version($owner);

            if (! hash_equals($previousVersion, $deletion->expectedVersion)) {
                throw TranslationResourceException::stale($resourceKey);
            }

            $normalizedLocale = $owner->translationDefinition()->assertLocale(
                $this->locales->assertSupported($deletion->locale),
            );
            $deleted = $this->writer->delete($owner, $normalizedLocale);
            $this->locator->loadTranslations(new Collection([$owner]));
            $version = $this->versioner->version($owner);
            $resourceId = $owner->translationResourceKey();

            if ($deleted) {
                $connection->afterCommit(static function () use (
                    $resourceKey,
                    $resourceId,
                    $owner,
                    $normalizedLocale,
                    $actor,
                    $previousVersion,
                    $version,
                ): void {
                    Event::dispatch(new TranslationResourceLocaleDeleted(
                        resource: $resourceKey,
                        ownerType: $owner::class,
                        ownerId: $resourceId,
                        locale: $normalizedLocale,
                        actor: $actor,
                        previousVersion: $previousVersion,
                        version: $version,
                    ));
                });
            }

            return new TranslationDeleteResultData(
                resource: $resourceKey,
                id: $resourceId,
                locale: $normalizedLocale,
                deleted: $deleted,
                version: $version,
            );
        }, $this->transactionAttempts());
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
