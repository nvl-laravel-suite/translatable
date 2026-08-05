# Security Policy

Security fixes are provided for the current `1.x` release line on the
Composer-declared PHP `^8.3` and Laravel `^12.0 || ^13.0` ranges.

Report vulnerabilities privately through the repository host's
security-advisory feature. Include resource key, actor, scope, locale, version
hash, operation, and impact without personal translated content.

Registry definitions must whitelist fields, search/display columns, and
visibility scopes. Authorize every gather and mutation, bound bulk operations,
reject stale writes, and clear locale state between requests and jobs. Keep
tenant and ownership restrictions inside the registered query scope so reads
and locked mutations resolve the same visible resource.

Related owner and translation models must use the same database connection.
Self-translated resources must use a stable group key. Enforce a database
unique constraint on `(owner_key, locale)` or `(group_key, locale)` so
concurrent locale creation cannot duplicate rows.

Use `SyncTranslationResourceAction` and
`DeleteTranslationResourceLocaleAction` for centralized writes. They lock the
logical resource, require an expected version, use the declared connection,
retry deadlocks, authorize before exposing mutation policy, and dispatch
events after commit. Direct `TranslationWriter` callers must provide their own
transaction on the model's connection.

Never hydrate `TranslationActorData` from client-controlled input and trust
its `system` flag. Applications must derive actor identity and trust on the
server before calling registry services.

Use `TranslationMutationPolicy::DomainActionOnly` when generic persistence
would bypass domain validation, related-record synchronization, activity, or
domain events. A stable resource key is an identifier, not an authorization
boundary.

Self-row group and locale columns are immutable through Eloquent model saves,
including saves with muted events or later event-listener mutations. Avoid
mass updates that bypass model instances; use the package writer, convenience
mutation methods, or registered actions. Keep Eloquent-managed primary keys,
timestamps, and soft-delete columns outside translated and shared fields.

Persist only canonical normalized locale values. Validate locale-keyed HTTP
input with `SupportedLocaleMapRule`; the writer then enforces declared fields
and configured payload count, depth, and byte limits. Do not log translated
payloads when they may contain personal or confidential content.

Run `php artisan nvl:translatable:doctor` during deployment checks. Treat
cross-connection definitions, missing columns or unique indexes, unsupported
locales, and invalid mutation limits as deployment blockers.
