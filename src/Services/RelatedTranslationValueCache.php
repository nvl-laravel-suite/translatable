<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\RelatedTranslationDefinition;
use Nvl\Translatable\TranslationResolution;
use WeakMap;

/** Request-scoped scalar results. Owned or transactional reads always use the source. @internal */
final class RelatedTranslationValueCache
{
    /** @var WeakMap<Connection, array<string,array<string,TranslationResolution>>> */
    private WeakMap $entries;

    public function __construct(private readonly TranslationOwnership $ownership)
    {
        $this->entries = new WeakMap;
    }

    /** @return array<string,TranslationResolution> */
    public function get(Model $owner, RelatedTranslationDefinition $definition, string $locale): array
    {
        $key = $this->readKey($owner, $definition);
        if ($key === null) {
            return [];
        }
        $this->ownership->assertOwner($owner, $definition);
        $prefix = $this->prefix($owner, $definition, $locale);
        $results = [];
        foreach ($this->entries[$owner->getConnection()][$key] ?? [] as $entryKey => $resolution) {
            if (str_starts_with($entryKey, $prefix)) {
                $results[$resolution->field] = $resolution;
            }
        }

        return $results;
    }

    /** @param array<string,TranslationResolution> $resolutions */
    public function put(Model $owner, RelatedTranslationDefinition $definition, string $locale, array $resolutions): void
    {
        $key = $this->readKey($owner, $definition);
        if ($key === null) {
            return;
        }
        $connection = $owner->getConnection();
        $byOwner = $this->entries[$connection] ?? [];
        if (count($byOwner) >= 1000 && ! isset($byOwner[$key])) {
            return;
        }
        $prefix = $this->prefix($owner, $definition, $locale);
        foreach ($resolutions as $field => $resolution) {
            if (is_scalar($resolution->value) || $resolution->value === null) {
                $byOwner[$key][$prefix.$field] = $resolution;
            }
        }
        $this->entries[$connection] = $byOwner;
    }

    public function forget(Model $owner, RelatedTranslationDefinition $definition): void
    {
        $connection = $owner->getConnection();
        if (! isset($this->entries[$connection])) {
            return;
        }
        $key = $this->ownerKey($owner, $definition);
        if ($key === null || ! isset($this->entries[$connection][$key])) {
            return;
        }
        $byOwner = $this->entries[$connection];
        unset($byOwner[$key]);
        $this->entries[$connection] = $byOwner;
    }

    private function readKey(Model $owner, RelatedTranslationDefinition $definition): ?string
    {
        if ($definition->ownershipResource !== null || $owner->getConnection()->transactionLevel() !== 0
            || ! $owner->exists || $owner->relationLoaded('translations') || $owner->isDirty($definition->ownerKey)) {
            return null;
        }

        return $this->ownerKey($owner, $definition);
    }

    private function ownerKey(Model $owner, RelatedTranslationDefinition $definition): ?string
    {
        $id = $owner->getRawOriginal($definition->ownerKey);
        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        return json_encode([$owner->getTable(), $definition->ownerKey, (string) $id], JSON_THROW_ON_ERROR);
    }

    private function prefix(Model $owner, RelatedTranslationDefinition $definition, string $locale): string
    {
        return hash('sha256', json_encode([
            $owner::class, $definition->translationModel, $definition->foreignKey($owner->getTable()), $definition->localeKey, $locale, $definition->supportedLocales(),
            $definition->resolvedFallbackPolicy()->value, $definition->shouldFallbackOnNull(), $definition->configuredFallbackLocales(),
        ], JSON_THROW_ON_ERROR)).':';
    }
}
