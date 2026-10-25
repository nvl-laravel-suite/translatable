# NVL Translatable

- Declare typed related-table or grouped self-row translation definitions on concrete Eloquent models.
- Register every translation resource under an exact key and bind TranslationResourceAuthorizer. The default denies.
- Read the current revision through TranslationResourceVersioner; pass it with a persisted actor to TranslationWriter::sync() or deleteLocale(). The writer owns the transaction and checks canonical scope.
- Patch preserves omitted locales; Replace removes them. Explicit null is a value.
- Use TranslationMutationPolicy::OwningServiceOnly when the owning domain service controls the mutation.
- Native Ownership is optional. Protected resources need exact model declarations, adoption, and admitted owner or central context. Do not infer scope from a tenant-named column.
- Consumers own HTTP, Actions, Data objects, and frontend types.
