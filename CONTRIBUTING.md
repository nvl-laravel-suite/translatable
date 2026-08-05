# Contributing to NVL Translatable

Changes must preserve explicit typed declarations, both storage strategies,
deterministic field-level fallback, locale isolation, and transport-agnostic
core services. HTTP integration must remain confined to middleware adapters.

Test normalization, every fallback policy, empty values, related eager-loading
query counts, self-group query composition, registry validation,
authorization, non-default connections, optimistic concurrency, payload
limits, after-commit events, diagnostics, and worker isolation.

New resource integrations must declare their storage strategy, fields,
structural keys, scope, authorization, pagination, and database constraints.
Keep `README.md`, `SECURITY.md`, `UPGRADING.md`, the bundled Boost guideline,
the bundled `nvl-translatable` skill, and the project workspace's translatable
skill consistent whenever a public invariant changes.

Run Pest, Pint, PHPStan at maximum strictness, Composer validation,
`composer audit`, dependency analysis, TypeScript declaration checks,
`nvl:translatable:doctor --json`, skill validation, and distribution
validation. Documentation examples must use the same key strategy, table
names, connections, fillable fields, casts, and constraints as the schema they
describe.
