## NVL Translatable

- Use `nvl/translatable` for model content and `nvl/translations` for Laravel
  language-file strings.
- Declare translations on the model with `defineTranslations()`.
- Use `RelatedTranslationDefinition` for canonical owners with dedicated
  locale rows.
- Use `SelfTranslationDefinition` for logical resources stored as grouped
  locale rows without an owner table.
- Declare every translated field explicitly; never infer columns.
- Keep domain models and migrations explicit. Do not generate schema or scan
  model directories from translation declarations; verify them with
  `nvl:translatable:doctor`.
- Require owner/locale or group/locale uniqueness and keep related rows on the
  owner's connection.
- Match UUID schemas with `HasUuids` and keep translation-model tables,
  fillable fields, casts, relationships, and connections aligned.
- Keep structural identity and Eloquent-managed primary-key, timestamp, and
  soft-delete columns out of translated and shared fields.
- Default to configured deterministic fallback. Use exact-only or
  any-available behavior only when explicitly required.
- Use `TranslationWriter` inside a transaction on the model connection, or
  use the registered resource mutation actions.
- Use `TranslationMutationPolicy::DomainActionOnly` when an owning package
  must enforce domain validation, synchronization, activity, or events.
- Use self-row convenience mutations instead of changing group or locale
  identity directly.
- Never change self-row group or locale through a bulk query update, which
  bypasses Eloquent's instance-level invariants.
- Run `php artisan nvl:translatable:doctor` after configuration, declaration,
  connection, or schema changes.
- Register stable resource metadata explicitly and keep authorization,
  tenant visibility, and trusted system actors server-owned.
