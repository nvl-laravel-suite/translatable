# Security policy and translation admission

Report suspected cross-owner reads or writes, stale-version acceptance, forged resource selection, worker context leakage, and unsafe locale payloads through the repository's private security reporting channel. Include the resource key, actor, owner scope, expected and observed revision, storage strategy, and a minimal reproduction.

TranslationWriter::sync() and deleteLocale() are the public translation-management mutations. They require a registered concrete model, a persisted ordinary actor or explicitly authorized SystemActor, and a caller-supplied current revision. They lock and re-read canonical storage inside the model connection's transaction, apply the exact resource policy and query scope, reject stale writes, and dispatch events after commit. The default TranslationResourceAuthorizer denies access.

Never treat a client-supplied actor or system marker as authority. Resolve ordinary actors from trusted authentication and require a real persisted identifier. SystemActor is a background capability only when the host policy authorizes that enum value and operation. When a model has multiple registrations, the explicit key must still match the model and every storage and scope guard.

Native Ownership is optional for unowned resources. Protected resources require exact registry declarations, completed adoption, live owner and store generations, and active owner/central admission. A raw owner column, queue envelope, model relation, or query result does not grant scope. Related rows must match their canonical parent; grouped self rows must remain in their logical owner partition. Context and ContentLocale are restored after worker callbacks, including failures.

Only the owning domain service may mutate an OwningServiceOnly resource. Model cast staging and storage hooks remain available within that authorized workflow. Raw SQL and bulk query writes bypass model hooks and require separate host review. Use SupportedLocaleMapRule for early input errors; the writer remains the final validator of locales, declared fields, payload size, omission, and explicit null.
