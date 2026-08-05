# Upgrading NVL Translatable

## Adopting typed translation definitions

Existing `TranslatableOptions` and `SelfTranslatableOptions` declarations
continue to work through compatibility adapters. New and updated models should
use `defineTranslations()`.

For related rows:

```php
protected function defineTranslations(): RelatedTranslationDefinition
{
    return new RelatedTranslationDefinition(
        translationModel: ArticleTranslation::class,
        foreignKey: 'article_id',
        fields: ['title', 'summary'],
    );
}
```

Rename:

- `translatableOptions()` to `defineTranslations()`
- `TranslatableOptions` to `RelatedTranslationDefinition`
- `translatableFields` to `fields`
- `availableLocales` to `locales`

For grouped same-table rows, implement `SelfTranslatableModel`, use
`SelfTranslatable`, and return `SelfTranslationDefinition`. The group key is
required and the database must enforce a unique `(group_key, locale)` index.

The default fallback policy is now `configured`. Applications that require a
deterministic fallback to any persisted locale must explicitly select
`TranslationFallbackPolicy::AnyAvailable`. Exact reads should use
`withFallback: false` or `TranslationFallbackPolicy::ExactOnly`.

Central registered mutations now require owner and translation models to use
the same connection. Run the following before deployment:

```bash
php artisan nvl:translatable:doctor
php artisan nvl:data:types:generate
php artisan nvl:data:types:check
```

Self-row group and locale columns are now immutable after creation. Replace
direct identity updates with `setTranslation()`, `cloneTranslation()`, and
`deleteTranslation()`, or use the registered mutation actions.

Translated and shared declarations now reject Eloquent-managed primary-key,
timestamp, and soft-delete columns. Remove those fields from definitions and
leave their lifecycle to the model.

Persisted locale inventories and central payloads now include only supported,
canonically normalized locale rows. Before upgrading, backfill legacy values
such as `EN` to their canonical form such as `en`, and resolve any uniqueness
conflicts created by normalization.

Invalid configured resource metadata and malformed locale, fallback,
middleware, limit, or transaction-attempt settings now fail explicitly.
Correct these values before upgrading rather than relying on previous silent
filtering or defaults.

Model-specific `locales` may now only narrow `translatable.locales`. Add every
locale to the global catalog before selecting it in a model definition.

## Upgrading to 1.0

Version 1.0 removes magic translated properties, deprecated aliases, JOIN
hydration compatibility, legacy constructor forms, and no-op cache APIs.

1. Implement the contract and trait matching the resource's storage strategy.
2. Declare translated fields through a typed translation definition.
3. Replace magic access with explicit translation methods.
4. Register central resources through `TranslationResourceRegistry`.
5. Supply expected version hashes to central writes.
6. Establish and clear content locale at each request or job boundary.
7. Use `nvl:translations` for Laravel language files.

Backfill consumer data before removing old reads; the package does not perform
domain-specific conversion or generate migrations.

Do not build schema generation around translation declarations. They do not
contain SQL types, nullability, defaults, connection ownership, casts, or
migration history. Keep schema changes explicit and use the doctor to verify
the final database against each declaration.
