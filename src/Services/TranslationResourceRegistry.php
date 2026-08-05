<?php

declare(strict_types=1);

namespace Nvl\Translatable\Services;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Translatable\Data\TranslationActorData;
use Nvl\Translatable\Enums\TranslationResourceAbility;
use Nvl\Translatable\Exceptions\TranslationResourceException;
use Nvl\Translatable\TranslationResourceDefinition;

/**
 * Stores every translatable model resource available to centralized tooling.
 */
final class TranslationResourceRegistry
{
    /**
     * @var array<string, TranslationResourceDefinition>
     */
    private array $resources = [];

    /**
     * Register one definition, allowing only identical idempotent registration.
     *
     * @param  class-string  $modelClass
     * @param  list<string>  $searchableColumns
     * @param  list<string>  $displayColumns
     * @param  (Closure(TranslationActorData, TranslationResourceAbility, ?Model): bool)|null  $authorization
     * @param  (Closure(Builder<Model>): Builder<Model>)|null  $queryScope
     */
    public function register(
        string $key,
        string $modelClass,
        string $label,
        array $searchableColumns = [],
        array $displayColumns = [],
        ?string $orderColumn = null,
        int $maximumPageSize = 100,
        ?Closure $authorization = null,
        ?Closure $queryScope = null,
    ): TranslationResourceDefinition {
        return $this->add(new TranslationResourceDefinition(
            key: $key,
            label: $label,
            modelClass: $modelClass,
            searchableColumns: $searchableColumns,
            displayColumns: $displayColumns,
            orderColumn: $orderColumn,
            maximumPageSize: $maximumPageSize,
            authorization: $authorization,
            queryScope: $queryScope,
        ));
    }

    /**
     * Register a pre-built translation resource definition.
     */
    public function add(TranslationResourceDefinition $resource): TranslationResourceDefinition
    {
        $existing = $this->resources[$resource->key] ?? null;

        if ($existing !== null && $existing != $resource) {
            throw TranslationResourceException::duplicate($resource->key);
        }

        return $this->resources[$resource->key] = $resource;
    }

    /**
     * Determine whether a resource key is registered.
     */
    public function has(string $key): bool
    {
        return isset($this->resources[$key]);
    }

    /**
     * Resolve a registered resource definition.
     *
     * @throws TranslationResourceException
     */
    public function get(string $key): TranslationResourceDefinition
    {
        return $this->resources[$key]
            ?? throw TranslationResourceException::unknown($key, $this->keys());
    }

    /**
     * Return registered definitions sorted by stable key.
     *
     * @return list<TranslationResourceDefinition>
     */
    public function all(): array
    {
        $resources = $this->resources;
        ksort($resources);

        return array_values($resources);
    }

    /**
     * Return every registered resource key.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->resources);
        sort($keys);

        return $keys;
    }
}
