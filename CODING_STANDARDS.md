# Coding standards

Judgement calls for review. phpstan and ECS (`composer check`) cover everything mechanical.

## Vocabulary

- Name domain concepts with the terms in `GLOSSARY.md`, in code, messages, tests and docs. A term from a glossary entry's _Avoid_ list is a finding, unless the glossary notes an exception.
- A change that contradicts an ADR in `docs/adr/` says so explicitly and why.

## Import

- **Planning only reads.** `ImportPlanner`, `ImportContext` and every Field adapter's `import()` run during a Preview, outside any transaction, and must leave the Target environment untouched. Anything that writes (Stubs, assets, placement) is recorded in the plan and done by the `Importer`, inside the Root element's transaction.
- **The Preview is the Import.** A decision about what will be imported, skipped or blocked lives in the planner, so `preview()` and `import()` can't disagree. The `Importer` executes the plan and decides nothing.
- **The Payload never names a class.** Element types come from `ElementTypes`; field and type classes come from the Target environment's config, matched by UID. A Payload value is never instantiated, autoloaded or called as a class.
- **Messages name the thing and the outcome.** A warning or refusal names the field, entry type, section or site, using its name, and says what will happen to it: "The field "Summary" doesn't exist in this environment. Its values will be skipped."

## Tests

- **Test through the seam.** Tests call the core service's `export` / `preview` / `import` (`Plugin::getInstance()->getDolly()`) and assert on the Target environment, the returned ImportPlan / ImportResult, and the Import log. They never call PayloadCodec, SchemaManifest, ReferenceResolver, ImportPlanner, Importer or Field adapters directly. Console controllers get light coverage only.
- **Simulate the Target environment on one install.** Change the install between Export and Import through `tests/_support/Schema.php`, adding a `Schema` method for any new kind of change rather than calling Craft's services inline.
- **Expected values are literals.** Assert against known strings, values and messages written out in the test, never against values recomputed the way the code computes them.
