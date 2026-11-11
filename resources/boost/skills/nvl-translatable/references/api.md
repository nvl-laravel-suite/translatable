# Public API: nvl/translatable

Generated from the package public contract. Use this reference to confirm fully qualified names, parameter order, defaults, native array shapes and return types. Read [usage](usage.md) for policy and operation semantics; declarations alone never grant authority.

Search for the relevant fully qualified service or contract and read only its matching symbol sections; this is a lookup catalog, not an instruction to load every declaration. `resolved` annotations expand imported aliases and domain type shapes. Framework inheritance and host-authored extensions remain outside this package contract.

## Symbols

- `Nvl\Translatable\Contracts\ContentLocalePreferenceResolver`
- `Nvl\Translatable\Contracts\SelfTranslatableModel`
- `Nvl\Translatable\Contracts\TranslatableModel`
- `Nvl\Translatable\Contracts\TranslatableResourceModel`
- `Nvl\Translatable\Contracts\TranslationResourceAuthorizer`
- `Nvl\Translatable\Enums\Locale`
- `Nvl\Translatable\Enums\TranslatableResponseCode`
- `Nvl\Translatable\Enums\TranslationFallbackPolicy`
- `Nvl\Translatable\Enums\TranslationMutationPolicy`
- `Nvl\Translatable\Enums\TranslationResourceAbility`
- `Nvl\Translatable\Enums\TranslationStorageStrategy`
- `Nvl\Translatable\Enums\TranslationSyncMode`
- `Nvl\Translatable\Events\TranslationResourceLocaleDeleted`
- `Nvl\Translatable\Events\TranslationResourceSynced`
- `Nvl\Translatable\Events\TranslationResourcesGathered`
- `Nvl\Translatable\Exceptions\InvalidLocaleException`
- `Nvl\Translatable\Exceptions\InvalidTranslatableFieldException`
- `Nvl\Translatable\Exceptions\TranslatableException`
- `Nvl\Translatable\Exceptions\TranslationResourceException`
- `Nvl\Translatable\RelatedTranslationDefinition`
- `Nvl\Translatable\Rules\SupportedLocaleMapRule`
- `Nvl\Translatable\SelfTranslatable`
- `Nvl\Translatable\SelfTranslationDefinition`
- `Nvl\Translatable\Services\ContentLocale`
- `Nvl\Translatable\Services\LocaleRegistry`
- `Nvl\Translatable\Services\RelatedTranslationStore`
- `Nvl\Translatable\Services\SelfTranslationStore`
- `Nvl\Translatable\Services\TranslationPayloadValidator`
- `Nvl\Translatable\Services\TranslationResolver`
- `Nvl\Translatable\Services\TranslationResourceGatherer`
- `Nvl\Translatable\Services\TranslationResourceRegistry`
- `Nvl\Translatable\Services\TranslationResourceVersioner`
- `Nvl\Translatable\Services\TranslationWriter`
- `Nvl\Translatable\Translatable`
- `Nvl\Translatable\TranslationDefinition`
- `Nvl\Translatable\TranslationResolution`
- `Nvl\Translatable\TranslationResourceDefinition`

## Nvl\Translatable\Contracts\ContentLocalePreferenceResolver

```text
interface Nvl\Translatable\Contracts\ContentLocalePreferenceResolver
public abstract function resolve(): ?string
```

## Nvl\Translatable\Contracts\SelfTranslatableModel

```text
interface Nvl\Translatable\Contracts\SelfTranslatableModel extends Nvl\Translatable\Contracts\TranslatableResourceModel
public abstract function getAllTranslations(): Illuminate\Database\Eloquent\Collection
@return Collection<int, covariant Model>
resolved @return Illuminate\Database\Eloquent\Collection<int, covariant Illuminate\Database\Eloquent\Model>
public abstract function getAvailableLocales(): array
@return list<string>
public abstract function resolveTranslation(string $field, ?string $locale=null): Nvl\Translatable\TranslationResolution
public abstract function translated(string $field, ?string $locale=null): mixed
public abstract function translationDefinition(): Nvl\Translatable\SelfTranslationDefinition
public abstract function translationResourceKey(): int|string
```

## Nvl\Translatable\Contracts\TranslatableModel

```text
interface Nvl\Translatable\Contracts\TranslatableModel extends Nvl\Translatable\Contracts\TranslatableResourceModel
public abstract function getAllTranslations(): Illuminate\Database\Eloquent\Collection
@return Collection<int, covariant Model>
resolved @return Illuminate\Database\Eloquent\Collection<int, covariant Illuminate\Database\Eloquent\Model>
public abstract function getAvailableLocales(): array
@return list<string>
public abstract function resolveTranslation(string $field, ?string $locale=null): Nvl\Translatable\TranslationResolution
public abstract function translated(string $field, ?string $locale=null): mixed
public abstract function translationDefinition(): Nvl\Translatable\RelatedTranslationDefinition
public abstract function translationResourceKey(): int|string
public abstract function translations(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<Model, *>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Illuminate\Database\Eloquent\Model, *>
```

## Nvl\Translatable\Contracts\TranslatableResourceModel

```text
interface Nvl\Translatable\Contracts\TranslatableResourceModel
public abstract function getAllTranslations(): Illuminate\Database\Eloquent\Collection
@return Collection<int, covariant Model>
resolved @return Illuminate\Database\Eloquent\Collection<int, covariant Illuminate\Database\Eloquent\Model>
public abstract function getAvailableLocales(): array
@return list<string>
public abstract function resolveTranslation(string $field, ?string $locale=null): Nvl\Translatable\TranslationResolution
public abstract function translated(string $field, ?string $locale=null): mixed
public abstract function translationDefinition(): Nvl\Translatable\TranslationDefinition
public abstract function translationResourceKey(): int|string
```

## Nvl\Translatable\Contracts\TranslationResourceAuthorizer

```text
interface Nvl\Translatable\Contracts\TranslationResourceAuthorizer
public abstract function allows(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Translatable\Enums\TranslationResourceAbility $ability, Nvl\Translatable\TranslationResourceDefinition $resource, ?Illuminate\Database\Eloquent\Model $record=null): bool
```

## Nvl\Translatable\Enums\Locale

```text
enum Nvl\Translatable\Enums\Locale implements BackedEnum, UnitEnum: string
public const Nvl\Translatable\Enums\Locale BG=Nvl\Translatable\Enums\Locale::BG="bg"
public const Nvl\Translatable\Enums\Locale EN=Nvl\Translatable\Enums\Locale::EN="en"
public static function fromValue(?string $value): ?self
public function internationalLabel(): string
public function nativeLabel(): string
public static function normalizeLanguageCode(string $code, string $fallback="en"): string
public static function options(self $activeLocale=self::EN): array
@return list<array{value: string, internationalLabel: string, nativeLabel: string, active: bool}>
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Translatable\Enums\TranslatableResponseCode

```text
enum Nvl\Translatable\Enums\TranslatableResponseCode implements BackedEnum, Nvl\Support\Contracts\ResponseCode, UnitEnum: string
public const Nvl\Translatable\Enums\TranslatableResponseCode InvalidLocale=Nvl\Translatable\Enums\TranslatableResponseCode::InvalidLocale="invalid_locale"
public const Nvl\Translatable\Enums\TranslatableResponseCode InvalidTranslatableField=Nvl\Translatable\Enums\TranslatableResponseCode::InvalidTranslatableField="invalid_translatable_field"
public const Nvl\Translatable\Enums\TranslatableResponseCode InvalidTranslationResource=Nvl\Translatable\Enums\TranslatableResponseCode::InvalidTranslationResource="invalid_translation_resource"
public const Nvl\Translatable\Enums\TranslatableResponseCode OperationFailed=Nvl\Translatable\Enums\TranslatableResponseCode::OperationFailed="operation_failed"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Translatable\Enums\TranslationFallbackPolicy

```text
enum Nvl\Translatable\Enums\TranslationFallbackPolicy implements BackedEnum, UnitEnum: string
public const Nvl\Translatable\Enums\TranslationFallbackPolicy AnyAvailable=Nvl\Translatable\Enums\TranslationFallbackPolicy::AnyAvailable="any-available"
public const Nvl\Translatable\Enums\TranslationFallbackPolicy Configured=Nvl\Translatable\Enums\TranslationFallbackPolicy::Configured="configured"
public const Nvl\Translatable\Enums\TranslationFallbackPolicy ExactOnly=Nvl\Translatable\Enums\TranslationFallbackPolicy::ExactOnly="exact-only"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Translatable\Enums\TranslationMutationPolicy

```text
enum Nvl\Translatable\Enums\TranslationMutationPolicy implements BackedEnum, UnitEnum: string
public const Nvl\Translatable\Enums\TranslationMutationPolicy Direct=Nvl\Translatable\Enums\TranslationMutationPolicy::Direct="direct"
public const Nvl\Translatable\Enums\TranslationMutationPolicy OwningServiceOnly=Nvl\Translatable\Enums\TranslationMutationPolicy::OwningServiceOnly="owning-service-only"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Translatable\Enums\TranslationResourceAbility

```text
enum Nvl\Translatable\Enums\TranslationResourceAbility implements BackedEnum, UnitEnum: string
public const Nvl\Translatable\Enums\TranslationResourceAbility Delete=Nvl\Translatable\Enums\TranslationResourceAbility::Delete="delete"
public const Nvl\Translatable\Enums\TranslationResourceAbility List=Nvl\Translatable\Enums\TranslationResourceAbility::List="list"
public const Nvl\Translatable\Enums\TranslationResourceAbility Report=Nvl\Translatable\Enums\TranslationResourceAbility::Report="report"
public const Nvl\Translatable\Enums\TranslationResourceAbility Synchronize=Nvl\Translatable\Enums\TranslationResourceAbility::Synchronize="synchronize"
public const Nvl\Translatable\Enums\TranslationResourceAbility View=Nvl\Translatable\Enums\TranslationResourceAbility::View="view"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Translatable\Enums\TranslationStorageStrategy

```text
enum Nvl\Translatable\Enums\TranslationStorageStrategy implements BackedEnum, UnitEnum: string
public const Nvl\Translatable\Enums\TranslationStorageStrategy Related=Nvl\Translatable\Enums\TranslationStorageStrategy::Related="related"
public const Nvl\Translatable\Enums\TranslationStorageStrategy Self=Nvl\Translatable\Enums\TranslationStorageStrategy::Self="self"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Translatable\Enums\TranslationSyncMode

```text
enum Nvl\Translatable\Enums\TranslationSyncMode implements BackedEnum, UnitEnum: string
public const Nvl\Translatable\Enums\TranslationSyncMode Patch=Nvl\Translatable\Enums\TranslationSyncMode::Patch="patch"
public const Nvl\Translatable\Enums\TranslationSyncMode Replace=Nvl\Translatable\Enums\TranslationSyncMode::Replace="replace"
public readonly string $name
public static function cases(): array
public readonly string $value
public static function from(string|int $value): static
public static function tryFrom(string|int $value): ?static
```

## Nvl\Translatable\Events\TranslationResourceLocaleDeleted

```text
final readonly class Nvl\Translatable\Events\TranslationResourceLocaleDeleted implements Nvl\Support\Contracts\DomainEvent, Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public function __construct(string $resource, string $ownerType, int|string $ownerId, string $locale, string $actorType, int|string $actorId, string $previousVersion, string $version, int $schemaVersion=1): mixed
public function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public function schemaVersion(): int
public readonly int|string $actorId
public readonly string $actorType
public readonly string $locale
public readonly int|string $ownerId
public readonly string $ownerType
public readonly string $previousVersion
public readonly string $resource
public readonly int $schemaVersion
public readonly string $version
```

## Nvl\Translatable\Events\TranslationResourceSynced

```text
final readonly class Nvl\Translatable\Events\TranslationResourceSynced implements Nvl\Support\Contracts\DomainEvent, Nvl\Support\Ownership\Contracts\OwnershipQueuedJob
public function __construct(string $resource, string $ownerType, int|string $ownerId, array $locales, Nvl\Translatable\Enums\TranslationSyncMode $mode, string $actorType, int|string $actorId, string $previousVersion, string $version, int $schemaVersion=1): mixed
@param list<string> $locales
public function ownershipJobEnvelope(): Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope
public function schemaVersion(): int
public readonly int|string $actorId
public readonly string $actorType
public readonly array $locales
@param list<string> $locales
public readonly Nvl\Translatable\Enums\TranslationSyncMode $mode
public readonly int|string $ownerId
public readonly string $ownerType
public readonly string $previousVersion
public readonly string $resource
public readonly int $schemaVersion
public readonly string $version
```

## Nvl\Translatable\Events\TranslationResourcesGathered

```text
final readonly class Nvl\Translatable\Events\TranslationResourcesGathered implements Nvl\Support\Contracts\DomainEvent
public function __construct(string $resource, int $page, int $count, string $actorType, int|string $actorId, int $schemaVersion=1): mixed
public function schemaVersion(): int
public readonly int|string $actorId
public readonly string $actorType
public readonly int $count
public readonly int $page
public readonly string $resource
public readonly int $schemaVersion
```

## Nvl\Translatable\Exceptions\InvalidLocaleException

```text
final class Nvl\Translatable\Exceptions\InvalidLocaleException extends Nvl\Translatable\Exceptions\TranslatableException
public static function malformed(string $locale): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
public static function unsupported(string $locale, array $supportedLocales): self
@param list<string> $supportedLocales
```

## Nvl\Translatable\Exceptions\InvalidTranslatableFieldException

```text
final class Nvl\Translatable\Exceptions\InvalidTranslatableFieldException extends Nvl\Translatable\Exceptions\TranslatableException
public static function forField(string $field, array $allowedFields): self
@param list<string> $allowedFields
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Translatable\Exceptions\TranslatableException

```text
class Nvl\Translatable\Exceptions\TranslatableException extends Exception implements Nvl\Support\Contracts\RespondableException
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
```

## Nvl\Translatable\Exceptions\TranslationResourceException

```text
final class Nvl\Translatable\Exceptions\TranslationResourceException extends InvalidArgumentException implements Nvl\Support\Contracts\RespondableException
public static function duplicate(string $key): self
public static function duplicateLocale(string $resource, string $locale): self
public static function invalid(string $message): self
public function package(): string
public function publicContext(): array
@return array<string, mixed>
public static function requiresOwningService(string $resource): self
public function responseCode(): ?string
public function responseHeaders(): array
@return array<string, string>
public static function stale(string $resource): self
public function suggestedStatus(): int
public function translationKey(): ?string
public function translationParameters(): array
@return array<string, (scalar | null)>
public static function unauthorized(string $resource, string $ability): self
public static function unavailable(string $key, string $ownerTable, string $translationTable): self
public static function undeclaredFields(string $resource, string $locale, array $fields): self
@param list<string> $fields
public static function unknown(string $key, array $registered): self
@param list<string> $registered
```

## Nvl\Translatable\RelatedTranslationDefinition

```text
final readonly class Nvl\Translatable\RelatedTranslationDefinition extends Nvl\Translatable\TranslationDefinition
public function __construct(string $translationModel, array $fields, ?string $foreignKey=null, string $ownerKey="id", string $localeKey="locale", ?array $locales=null, ?Nvl\Translatable\Enums\TranslationFallbackPolicy $fallbackPolicy=null, array $fallbackLocales=[], ?bool $fallbackOnNull=null, Nvl\Translatable\Enums\TranslationMutationPolicy $mutationPolicy=\Nvl\Translatable\Enums\TranslationMutationPolicy::Direct, ?string $ownershipResource=null): mixed
@param class-string<Model> $translationModel
resolved @param class-string<Illuminate\Database\Eloquent\Model> $translationModel
@param list<string> $fields
@param (list<string> | null) $locales
@param list<string> $fallbackLocales
public function assertLocale(string $locale): string
@throws InvalidLocaleException
resolved @throws Nvl\Translatable\Exceptions\InvalidLocaleException
public function assertModel(Illuminate\Database\Eloquent\Model $owner): void
public function assertTranslatableField(string $field): void
@throws InvalidTranslatableFieldException
resolved @throws Nvl\Translatable\Exceptions\InvalidTranslatableFieldException
public function configuredFallbackLocales(): array
@return list<string>
public function foreignKey(string $ownerTable): string
public function localeChain(string $requestedLocale, array $persistedLocales=[]): array
@param list<string> $persistedLocales
@return list<string>
public function resolvedFallbackPolicy(): Nvl\Translatable\Enums\TranslationFallbackPolicy
public function shouldFallbackOnNull(): bool
public function storage(): Nvl\Translatable\Enums\TranslationStorageStrategy
public function supportedLocales(): array
@return list<string>
public readonly array $fallbackLocales
@param list<string> $fallbackLocales
public readonly ?bool $fallbackOnNull
public readonly ?Nvl\Translatable\Enums\TranslationFallbackPolicy $fallbackPolicy
public readonly array $fields
@param list<string> $fields
public readonly ?string $foreignKey
public readonly string $localeKey
public readonly ?array $locales
@param (list<string> | null) $locales
public readonly Nvl\Translatable\Enums\TranslationMutationPolicy $mutationPolicy
public readonly string $ownerKey
public readonly ?string $ownershipResource
public readonly string $translationModel
@param class-string<Model> $translationModel
resolved @param class-string<Illuminate\Database\Eloquent\Model> $translationModel
```

## Nvl\Translatable\Rules\SupportedLocaleMapRule

```text
final readonly class Nvl\Translatable\Rules\SupportedLocaleMapRule implements Illuminate\Contracts\Validation\ValidationRule
public function __construct(?array $supportedLocales=null): mixed
@param (list<string> | null) $supportedLocales
public function validate(string $attribute, mixed $value, Closure $fail): void
@param Closure(string): PotentiallyTranslatedString $fail
resolved @param Closure(string): Illuminate\Translation\PotentiallyTranslatedString $fail
```

## Nvl\Translatable\SelfTranslatable

```text
trait Nvl\Translatable\SelfTranslatable
@mixin Model
resolved @mixin Illuminate\Database\Eloquent\Model
public function getAllTranslations(): Illuminate\Database\Eloquent\Collection
@return Collection<int, covariant Model>
resolved @return Illuminate\Database\Eloquent\Collection<int, covariant Illuminate\Database\Eloquent\Model>
public function getAvailableLocales(): array
@return list<string>
public function getCurrentLocale(): string
public function getLocaleKey(): string
public function getRelationValue(mixed $key): mixed
@param string $key
public function getTranslatedAttributes(?string $locale=null): array
@return array<string, mixed>
public function getTranslation(?string $locale=null, bool $withFallback=true): ?Illuminate\Database\Eloquent\Model
public function hasTranslation(?string $locale=null): bool
public function isTranslatableAttribute(string $field): bool
public function resolveTranslation(string $field, ?string $locale=null): Nvl\Translatable\TranslationResolution
public function scopeLocale(Illuminate\Database\Eloquent\Builder $query, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable>
public function scopeOrderByTranslated(Illuminate\Database\Eloquent\Builder $query, string $field, string $direction="asc", ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable>
public function scopeTranslationGroup(Illuminate\Database\Eloquent\Builder $query, int|string $groupValue): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable>
public function scopeWhereTranslated(Illuminate\Database\Eloquent\Builder $query, string $field, mixed $operator, mixed $value=\Nvl\Translatable\Enums\TranslationComparisonValue::Omitted, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable>
public function scopeWhereTranslationNotNull(Illuminate\Database\Eloquent\Builder $query, string $field, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable>
public function scopeWhereTranslationNull(Illuminate\Database\Eloquent\Builder $query, string $field, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable>
public function scopeWithAllTranslations(Illuminate\Database\Eloquent\Builder $query, int|string|null $groupValue=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable>
public function scopeWithResolvedTranslations(Illuminate\Database\Eloquent\Builder $query, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable>
public function setLocale(string $locale): self
public function translated(string $field, ?string $locale=null): mixed
public function translationDefinition(): Nvl\Translatable\SelfTranslationDefinition
public function translationResourceKey(): int|string
@method static Builder<static> locale(?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable> locale(?string $locale = null)
@method static Builder<static> orderByTranslated(string $field, string $direction = 'asc', ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable> orderByTranslated(string $field, string $direction = 'asc', ?string $locale = null)
@method static Builder<static> whereTranslated(string $field, mixed $operator, mixed $value = TranslationComparisonValue::Omitted, ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable> whereTranslated(string $field, mixed $operator, mixed $value = Nvl\Translatable\Enums\TranslationComparisonValue::Omitted, ?string $locale = null)
@method static Builder<static> whereTranslationNotNull(string $field, ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable> whereTranslationNotNull(string $field, ?string $locale = null)
@method static Builder<static> whereTranslationNull(string $field, ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable> whereTranslationNull(string $field, ?string $locale = null)
@method static Builder<static> withAllTranslations(?string $groupValue = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable> withAllTranslations(?string $groupValue = null)
@method static Builder<static> withResolvedTranslations(?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Translatable\SelfTranslatable> withResolvedTranslations(?string $locale = null)
```

## Nvl\Translatable\SelfTranslationDefinition

```text
final readonly class Nvl\Translatable\SelfTranslationDefinition extends Nvl\Translatable\TranslationDefinition
public function __construct(string $groupKey, array $fields, array $sharedFields=[], string $localeKey="locale", ?array $locales=null, ?Nvl\Translatable\Enums\TranslationFallbackPolicy $fallbackPolicy=null, array $fallbackLocales=[], ?bool $fallbackOnNull=null, bool $allowDeletingLastTranslation=false, Nvl\Translatable\Enums\TranslationMutationPolicy $mutationPolicy=\Nvl\Translatable\Enums\TranslationMutationPolicy::Direct, ?string $ownershipResource=null): mixed
@param list<string> $fields
@param list<string> $sharedFields
@param (list<string> | null) $locales
@param list<string> $fallbackLocales
public function assertLocale(string $locale): string
@throws InvalidLocaleException
resolved @throws Nvl\Translatable\Exceptions\InvalidLocaleException
public function assertModel(Illuminate\Database\Eloquent\Model $model): void
public function assertTranslatableField(string $field): void
@throws InvalidTranslatableFieldException
resolved @throws Nvl\Translatable\Exceptions\InvalidTranslatableFieldException
public function configuredFallbackLocales(): array
@return list<string>
public function localeChain(string $requestedLocale, array $persistedLocales=[]): array
@param list<string> $persistedLocales
@return list<string>
public function resolvedFallbackPolicy(): Nvl\Translatable\Enums\TranslationFallbackPolicy
public function shouldFallbackOnNull(): bool
public function storage(): Nvl\Translatable\Enums\TranslationStorageStrategy
public function supportedLocales(): array
@return list<string>
public readonly bool $allowDeletingLastTranslation
public readonly array $fallbackLocales
@param list<string> $fallbackLocales
public readonly ?bool $fallbackOnNull
public readonly ?Nvl\Translatable\Enums\TranslationFallbackPolicy $fallbackPolicy
public readonly array $fields
@param list<string> $fields
public readonly string $groupKey
public readonly string $localeKey
public readonly ?array $locales
@param (list<string> | null) $locales
public readonly Nvl\Translatable\Enums\TranslationMutationPolicy $mutationPolicy
public readonly ?string $ownershipResource
public readonly array $sharedFields
@param list<string> $sharedFields
```

## Nvl\Translatable\Services\ContentLocale

```text
final readonly class Nvl\Translatable\Services\ContentLocale
public const string CONTEXT_KEY="nvl.content_locale"
public const string EXPLICIT_CONTEXT_KEY="nvl.content_locale.explicit"
public function __construct(Nvl\Support\Contracts\LocaleCatalog $locales, Illuminate\Contracts\Config\Repository $config, Nvl\Translatable\Contracts\ContentLocalePreferenceResolver $preferences=new \Nvl\Translatable\Services\NullContentLocalePreferenceResolver()): mixed
public function available(): array
@return list<string>
public function get(): string
public function getLabel(bool $native=false): string
public function is(Nvl\Translatable\Enums\Locale|string $locale): bool
public function isBulgarian(): bool
public function isEnglish(): bool
public function isSet(): bool
public function options(): array
@return list<array{value: string, internationalLabel: string, nativeLabel: string, active: bool}>
public function preferred(): ?string
public function reset(): self
public function set(Nvl\Translatable\Enums\Locale|string $locale): self
public function setFromString(string $locale): bool
public function withLocale(Nvl\Translatable\Enums\Locale|string $locale, callable $callback): mixed
```

## Nvl\Translatable\Services\LocaleRegistry

```text
final class Nvl\Translatable\Services\LocaleRegistry
public function __construct(Illuminate\Contracts\Config\Repository $config): mixed
public function assertSupported(string $locale): string
@throws InvalidLocaleException
resolved @throws Nvl\Translatable\Exceptions\InvalidLocaleException
public function chain(string $requestedLocale, array $additionalFallbacks=[]): array
@param list<mixed> $additionalFallbacks
@return list<string>
public function default(): string
public function fallbacks(): array
@return list<string>
public function supported(): array
@return list<string>
public function supports(string $locale): bool
```

## Nvl\Translatable\Services\RelatedTranslationStore

```text
final readonly class Nvl\Translatable\Services\RelatedTranslationStore
public function __construct(Nvl\Translatable\Services\TranslationOwnership $ownership, Nvl\Support\Ownership\Contracts\OwnershipBoundary $boundary, Nvl\Support\Ownership\Services\OwnershipResourceRegistry $resources): mixed
public function assertRows(Illuminate\Database\Eloquent\Model $owner, Nvl\Translatable\RelatedTranslationDefinition $definition, Illuminate\Database\Eloquent\Collection $rows): void
@param Collection<int, Model> $rows
resolved @param Illuminate\Database\Eloquent\Collection<int, Illuminate\Database\Eloquent\Model> $rows
public function delete(Illuminate\Database\Eloquent\Model&Nvl\Translatable\Contracts\TranslatableModel $owner, Nvl\Translatable\RelatedTranslationDefinition $definition, string $locale): bool
public function deleteExcept(Illuminate\Database\Eloquent\Model&Nvl\Translatable\Contracts\TranslatableModel $owner, Nvl\Translatable\RelatedTranslationDefinition $definition, array $locales): void
@param list<string> $locales
public function eagerOwnerIdentities(array $owners, Nvl\Translatable\RelatedTranslationDefinition $definition): array
@param array<int, Model> $owners
resolved @param array<int, Illuminate\Database\Eloquent\Model> $owners
@return list<array<string, mixed>>
public function ownerIdentity(Illuminate\Database\Eloquent\Model $owner, Nvl\Translatable\RelatedTranslationDefinition $definition): array
@return array<string, mixed>
public function rows(Illuminate\Database\Eloquent\Model&Nvl\Translatable\Contracts\TranslatableModel $owner): Illuminate\Database\Eloquent\Collection
@return Collection<int, Model>
resolved @return Illuminate\Database\Eloquent\Collection<int, Illuminate\Database\Eloquent\Model>
public function rowsForOwners(array $owners): array
@param array<array-key, mixed> $owners
@return list<Collection<int, Model>>
resolved @return list<Illuminate\Database\Eloquent\Collection<int, Illuminate\Database\Eloquent\Model>>
public function scopeQuery(Illuminate\Database\Eloquent\Builder $query, Illuminate\Database\Eloquent\Model $owner, Nvl\Translatable\RelatedTranslationDefinition $definition): Illuminate\Database\Eloquent\Builder
@template T of Model
resolved @template T of Illuminate\Database\Eloquent\Model
@param Builder<T> $query
resolved @param Illuminate\Database\Eloquent\Builder<T> $query
@return Builder<T>
resolved @return Illuminate\Database\Eloquent\Builder<T>
template Nvl\Translatable\Services\RelatedTranslationStore::T of Illuminate\Database\Eloquent\Model
public function upsert(Illuminate\Database\Eloquent\Model&Nvl\Translatable\Contracts\TranslatableModel $owner, Nvl\Translatable\RelatedTranslationDefinition $definition, string $locale, array $attributes): Illuminate\Database\Eloquent\Model
@param array<string, mixed> $attributes
```

## Nvl\Translatable\Services\SelfTranslationStore

```text
final readonly class Nvl\Translatable\Services\SelfTranslationStore
public function __construct(Nvl\Translatable\Services\TranslationOwnership $ownership): mixed
public function delete(Illuminate\Database\Eloquent\Model&Nvl\Translatable\Contracts\SelfTranslatableModel $owner, Nvl\Translatable\SelfTranslationDefinition $definition, string $locale): bool
public function deleteExcept(Illuminate\Database\Eloquent\Model&Nvl\Translatable\Contracts\SelfTranslatableModel $owner, Nvl\Translatable\SelfTranslationDefinition $definition, array $locales): void
@param list<string> $locales
public function rows(Illuminate\Database\Eloquent\Model&Nvl\Translatable\Contracts\SelfTranslatableModel $owner): Illuminate\Database\Eloquent\Collection
@return Collection<int, covariant Model>
resolved @return Illuminate\Database\Eloquent\Collection<int, covariant Illuminate\Database\Eloquent\Model>
public function upsert(Illuminate\Database\Eloquent\Model&Nvl\Translatable\Contracts\SelfTranslatableModel $owner, Nvl\Translatable\SelfTranslationDefinition $definition, string $locale, array $attributes): Illuminate\Database\Eloquent\Model
@param array<string, mixed> $attributes
```

## Nvl\Translatable\Services\TranslationPayloadValidator

```text
final readonly class Nvl\Translatable\Services\TranslationPayloadValidator
public function __construct(Illuminate\Contracts\Config\Repository $config): mixed
public function validate(Nvl\Translatable\TranslationDefinition $definition, array $translations): void
@param array<array-key, mixed> $translations
```

## Nvl\Translatable\Services\TranslationResolver

```text
final class Nvl\Translatable\Services\TranslationResolver
public function resolve(Illuminate\Support\Collection $translations, Nvl\Translatable\TranslationDefinition $options, string $field, string $requestedLocale, array $localeChain): Nvl\Translatable\TranslationResolution
@param Collection<int, Model> $translations
resolved @param Illuminate\Support\Collection<int, Illuminate\Database\Eloquent\Model> $translations
@param list<string> $localeChain
```

## Nvl\Translatable\Services\TranslationResourceGatherer

```text
final readonly class Nvl\Translatable\Services\TranslationResourceGatherer
public function __construct(Nvl\Translatable\Services\TranslationResourceRegistry $resources, Nvl\Support\Contracts\LocaleCatalog $locales, Nvl\Translatable\Services\TranslationResourceAuthorization $authorization, Nvl\Translatable\Services\TranslationResourceVersioner $versioner, Nvl\Translatable\Services\TranslationResourceLocator $locator, Nvl\Translatable\Services\TranslationOwnership $ownership, Nvl\Support\Ownership\Services\OwnershipResourceRegistry $ownershipResources, Nvl\Support\Ownership\Contracts\OwnershipContext $ownershipContext, Nvl\Support\Events\DomainEventDispatcher $domainEvents): mixed
public function find(string $resourceKey, int|string $id, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): array
@return ResourceRecord
resolved @return array{resource: string, id: (int | string), label: string, attributes: array<string, mixed>, translations: array<string, array<string, mixed>>, translatedLocales: list<string>, missingLocales: list<string>, version: string}
public function gather(string $resourceKey, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, array $query=[]): Illuminate\Contracts\Pagination\LengthAwarePaginator
@param array{search?: ?string, missing_locale?: ?string, page?: int, per_page?: int} $query
@return LengthAwarePaginator<int, ResourceRecord>
resolved @return Illuminate\Contracts\Pagination\LengthAwarePaginator<int, array{resource: string, id: (int | string), label: string, attributes: array<string, mixed>, translations: array<string, array<string, mixed>>, translatedLocales: list<string>, missingLocales: list<string>, version: string}>
public function summaries(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor): array
@return list<ResourceSummary>
resolved @return list<array{key: string, label: string, model: class-string<Illuminate\Database\Eloquent\Model>, fields: list<string>, total: int}>
```

## Nvl\Translatable\Services\TranslationResourceRegistry

```text
final class Nvl\Translatable\Services\TranslationResourceRegistry
public function __construct(Nvl\Support\OwnerRegistry $identities): mixed
public function add(Nvl\Translatable\TranslationResourceDefinition $resource): Nvl\Translatable\TranslationResourceDefinition
public function all(): array
@return list<TranslationResourceDefinition>
resolved @return list<Nvl\Translatable\TranslationResourceDefinition>
public function forModel(Illuminate\Database\Eloquent\Model $model): Nvl\Translatable\TranslationResourceDefinition
public function get(string $key): Nvl\Translatable\TranslationResourceDefinition
@throws TranslationResourceException
resolved @throws Nvl\Translatable\Exceptions\TranslationResourceException
public function has(string $key): bool
public function keys(): array
@return list<string>
public function register(string $key, string $modelClass, string $label, array $searchableColumns=[], array $displayColumns=[], ?string $orderColumn=null, int $maximumPageSize=100, ?Closure $authorization=null, ?Closure $queryScope=null): Nvl\Translatable\TranslationResourceDefinition
@param string $modelClass
@param list<string> $searchableColumns
@param list<string> $displayColumns
@param (Closure((Authenticatable | SystemActor), TranslationResourceAbility, ?Model): bool | null) $authorization
resolved @param (Closure((Illuminate\Contracts\Auth\Authenticatable | Nvl\Support\Enums\SystemActor), Nvl\Translatable\Enums\TranslationResourceAbility, ?Illuminate\Database\Eloquent\Model): bool | null) $authorization
@param (Closure(Builder<Model>): Builder<Model> | null) $queryScope
resolved @param (Closure(Illuminate\Database\Eloquent\Builder<Illuminate\Database\Eloquent\Model>): Illuminate\Database\Eloquent\Builder<Illuminate\Database\Eloquent\Model> | null) $queryScope
```

## Nvl\Translatable\Services\TranslationResourceVersioner

```text
final class Nvl\Translatable\Services\TranslationResourceVersioner
public function __construct(Nvl\Translatable\Services\TranslationOwnership $ownership, Nvl\Translatable\Services\TranslationResourceLocator $locator): mixed
public function emptySelfGroupVersion(Illuminate\Database\Eloquent\Model&Nvl\Translatable\Contracts\SelfTranslatableModel $owner): string
public function version(Illuminate\Database\Eloquent\Model&Nvl\Translatable\Contracts\TranslatableResourceModel $owner): string
```

## Nvl\Translatable\Services\TranslationWriter

```text
final readonly class Nvl\Translatable\Services\TranslationWriter
public function __construct(Nvl\Support\Contracts\LocaleCatalog $locales, Nvl\Translatable\Services\RelatedTranslationStore $relatedStore, Nvl\Translatable\Services\SelfTranslationStore $selfStore, Nvl\Translatable\Services\TranslationPayloadValidator $payloadValidator, Nvl\Translatable\Services\TranslationOwnership $ownership, Nvl\Translatable\Services\TranslationResourceRegistry $resources, Nvl\Translatable\Services\TranslationResourceAuthorization $authorization, Nvl\Translatable\Services\TranslationResourceVersioner $versioner, Nvl\Translatable\Services\TranslationResourceLocator $locator, Illuminate\Contracts\Config\Repository $config, Nvl\Support\Events\DomainEventDispatcher $domainEvents): mixed
public function deleteLocale(Illuminate\Database\Eloquent\Model&Nvl\Translatable\Contracts\TranslatableResourceModel $owner, string $locale, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $expectedVersion, ?string $resourceKey=null): bool
public function sync(Illuminate\Database\Eloquent\Model&Nvl\Translatable\Contracts\TranslatableResourceModel $owner, array $translations, Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, string $expectedVersion, Nvl\Translatable\Enums\TranslationSyncMode $mode=\Nvl\Translatable\Enums\TranslationSyncMode::Patch, ?string $resourceKey=null): Illuminate\Database\Eloquent\Model
@param array<string, array<string, mixed>> $translations
@return (Model & TranslatableResourceModel)
resolved @return (Illuminate\Database\Eloquent\Model & Nvl\Translatable\Contracts\TranslatableResourceModel)
```

## Nvl\Translatable\Translatable

```text
trait Nvl\Translatable\Translatable
@mixin Model
resolved @mixin Illuminate\Database\Eloquent\Model
public function getAllTranslations(): Illuminate\Database\Eloquent\Collection
@return Collection<int, Model>
resolved @return Illuminate\Database\Eloquent\Collection<int, Illuminate\Database\Eloquent\Model>
public function getAvailableLocales(): array
@return list<string>
public function getCurrentLocale(): string
public function getRelationValue(mixed $key): mixed
@param string $key
public function getTranslatedAttributes(?string $locale=null): array
@return array<string, mixed>
public function getTranslation(?string $locale=null, bool $withFallback=true): ?Illuminate\Database\Eloquent\Model
public function hasTranslation(?string $locale=null): bool
public function isTranslatableAttribute(string $key): bool
public function resolveTranslation(string $field, ?string $locale=null): Nvl\Translatable\TranslationResolution
public function scopeOrderByTranslated(Illuminate\Database\Eloquent\Builder $query, string $field, string $direction="asc", ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Translatable\Translatable> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Translatable\Translatable>
public function scopeWhereTranslated(Illuminate\Database\Eloquent\Builder $query, string $field, mixed $operator, mixed $value=\Nvl\Translatable\Enums\TranslationComparisonValue::Omitted, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Translatable\Translatable> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Translatable\Translatable>
public function scopeWhereTranslationNotNull(Illuminate\Database\Eloquent\Builder $query, string $field, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Translatable\Translatable> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Translatable\Translatable>
public function scopeWhereTranslationNull(Illuminate\Database\Eloquent\Builder $query, string $field, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Translatable\Translatable> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Translatable\Translatable>
public function scopeWithAllTranslations(Illuminate\Database\Eloquent\Builder $query): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Translatable\Translatable> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Translatable\Translatable>
public function scopeWithResolvedTranslations(Illuminate\Database\Eloquent\Builder $query, ?string $locale=null): Illuminate\Database\Eloquent\Builder
@param Builder<static> $query
resolved @param Illuminate\Database\Eloquent\Builder<Nvl\Translatable\Translatable> $query
@return Builder<static>
resolved @return Illuminate\Database\Eloquent\Builder<Nvl\Translatable\Translatable>
public function setLocale(string $locale): self
public function translated(string $field, ?string $locale=null): mixed
public function translation(?string $locale=null): Illuminate\Database\Eloquent\Relations\HasOne
@return HasOne<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasOne<Illuminate\Database\Eloquent\Model, $this>
public function translationDefinition(): Nvl\Translatable\RelatedTranslationDefinition
public function translationResourceKey(): int|string
public function translations(): Illuminate\Database\Eloquent\Relations\HasMany
@return HasMany<Model, $this>
resolved @return Illuminate\Database\Eloquent\Relations\HasMany<Illuminate\Database\Eloquent\Model, $this>
@method static Builder<static> orderByTranslated(string $field, string $direction = 'asc', ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Translatable\Translatable> orderByTranslated(string $field, string $direction = 'asc', ?string $locale = null)
@method static Builder<static> whereTranslated(string $field, mixed $operator, mixed $value = TranslationComparisonValue::Omitted, ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Translatable\Translatable> whereTranslated(string $field, mixed $operator, mixed $value = Nvl\Translatable\Enums\TranslationComparisonValue::Omitted, ?string $locale = null)
@method static Builder<static> whereTranslationNotNull(string $field, ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Translatable\Translatable> whereTranslationNotNull(string $field, ?string $locale = null)
@method static Builder<static> whereTranslationNull(string $field, ?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Translatable\Translatable> whereTranslationNull(string $field, ?string $locale = null)
@method static Builder<static> withAllTranslations()
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Translatable\Translatable> withAllTranslations()
@method static Builder<static> withResolvedTranslations(?string $locale = null)
resolved @method static Illuminate\Database\Eloquent\Builder<Nvl\Translatable\Translatable> withResolvedTranslations(?string $locale = null)
@property-read Collection<int, Model> $translations
resolved @property-read Illuminate\Database\Eloquent\Collection<int, Illuminate\Database\Eloquent\Model> $translations
```

## Nvl\Translatable\TranslationDefinition

```text
abstract readonly class Nvl\Translatable\TranslationDefinition
public function __construct(array $fields, string $localeKey="locale", ?array $locales=null, ?Nvl\Translatable\Enums\TranslationFallbackPolicy $fallbackPolicy=null, array $fallbackLocales=[], ?bool $fallbackOnNull=null, Nvl\Translatable\Enums\TranslationMutationPolicy $mutationPolicy=\Nvl\Translatable\Enums\TranslationMutationPolicy::Direct, ?string $ownershipResource=null): mixed
@param list<string> $fields
@param (list<string> | null) $locales
@param list<string> $fallbackLocales
public function assertLocale(string $locale): string
@throws InvalidLocaleException
resolved @throws Nvl\Translatable\Exceptions\InvalidLocaleException
public function assertTranslatableField(string $field): void
@throws InvalidTranslatableFieldException
resolved @throws Nvl\Translatable\Exceptions\InvalidTranslatableFieldException
public function configuredFallbackLocales(): array
@return list<string>
public function localeChain(string $requestedLocale, array $persistedLocales=[]): array
@param list<string> $persistedLocales
@return list<string>
public function resolvedFallbackPolicy(): Nvl\Translatable\Enums\TranslationFallbackPolicy
public function shouldFallbackOnNull(): bool
public abstract function storage(): Nvl\Translatable\Enums\TranslationStorageStrategy
public function supportedLocales(): array
@return list<string>
public readonly array $fallbackLocales
@param list<string> $fallbackLocales
public readonly ?bool $fallbackOnNull
public readonly ?Nvl\Translatable\Enums\TranslationFallbackPolicy $fallbackPolicy
public readonly array $fields
@param list<string> $fields
public readonly string $localeKey
public readonly ?array $locales
@param (list<string> | null) $locales
public readonly Nvl\Translatable\Enums\TranslationMutationPolicy $mutationPolicy
public readonly ?string $ownershipResource
```

## Nvl\Translatable\TranslationResolution

```text
final readonly class Nvl\Translatable\TranslationResolution
public function __construct(string $field, string $requestedLocale, ?string $resolvedLocale, mixed $value, array $localeChain, bool $requestedTranslationExists): mixed
@param list<string> $localeChain
public function isMissing(): bool
public function usedFallback(): bool
public readonly string $field
public readonly array $localeChain
@param list<string> $localeChain
public readonly string $requestedLocale
public readonly bool $requestedTranslationExists
public readonly ?string $resolvedLocale
public readonly mixed $value
```

## Nvl\Translatable\TranslationResourceDefinition

```text
final readonly class Nvl\Translatable\TranslationResourceDefinition
public function __construct(string $key, string $label, string $modelClass, array $searchableColumns=[], array $displayColumns=[], ?string $orderColumn=null, int $maximumPageSize=100, ?Closure $authorization=null, ?Closure $queryScope=null): mixed
@param class-string $modelClass
@param list<string> $searchableColumns
@param list<string> $displayColumns
@param (Closure((Authenticatable | SystemActor), TranslationResourceAbility, ?Model): bool | null) $authorization
resolved @param (Closure((Illuminate\Contracts\Auth\Authenticatable | Nvl\Support\Enums\SystemActor), Nvl\Translatable\Enums\TranslationResourceAbility, ?Illuminate\Database\Eloquent\Model): bool | null) $authorization
@param (Closure(Builder<Model>): Builder<Model> | null) $queryScope
resolved @param (Closure(Illuminate\Database\Eloquent\Builder<Illuminate\Database\Eloquent\Model>): Illuminate\Database\Eloquent\Builder<Illuminate\Database\Eloquent\Model> | null) $queryScope
public function authorize(Illuminate\Contracts\Auth\Authenticatable|Nvl\Support\Enums\SystemActor $actor, Nvl\Translatable\Enums\TranslationResourceAbility $ability, ?Illuminate\Database\Eloquent\Model $record): ?bool
public function metadata(array $defaultLocales): array
@param list<string> $defaultLocales
@return array{key: string, label: string, model: class-string<Model>, table: string, translationTable: string, storage: string, mutationPolicy: string, keyName: string, fields: list<string>, locales: list<string>, searchableColumns: list<string>, displayColumns: list<string>}
resolved @return array{key: string, label: string, model: class-string<Illuminate\Database\Eloquent\Model>, table: string, translationTable: string, storage: string, mutationPolicy: string, keyName: string, fields: list<string>, locales: list<string>, searchableColumns: list<string>, displayColumns: list<string>}
public function newModel(): Illuminate\Database\Eloquent\Model&Nvl\Translatable\Contracts\TranslatableResourceModel
public readonly ?Closure $authorization
@param (Closure((Authenticatable | SystemActor), TranslationResourceAbility, ?Model): bool | null) $authorization
resolved @param (Closure((Illuminate\Contracts\Auth\Authenticatable | Nvl\Support\Enums\SystemActor), Nvl\Translatable\Enums\TranslationResourceAbility, ?Illuminate\Database\Eloquent\Model): bool | null) $authorization
public readonly array $displayColumns
@param list<string> $displayColumns
public readonly string $key
public readonly string $label
public readonly int $maximumPageSize
public readonly string $modelClass
@param class-string $modelClass
public readonly ?string $orderColumn
public readonly ?Closure $queryScope
@param (Closure(Builder<Model>): Builder<Model> | null) $queryScope
resolved @param (Closure(Illuminate\Database\Eloquent\Builder<Illuminate\Database\Eloquent\Model>): Illuminate\Database\Eloquent\Builder<Illuminate\Database\Eloquent\Model> | null) $queryScope
public readonly array $searchableColumns
@param list<string> $searchableColumns
```
