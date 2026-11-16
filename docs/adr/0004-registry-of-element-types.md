# Element types come from a registry, and the Payload names them by short name

Portage used to accept exactly five element types as References: entries, categories, tags, users and assets. `ElementTypes`, `NaturalKey` and `Stubs` each hardcoded them, and a Payload naming any other type was refused. Our sites relate to third-party elements too: Formie forms, Calendar events and others, through relation fields Portage had no way to move.

So the five types are now the first entries in a registry of element type definitions (`ElementTypeRegistry`). Each definition gives the element class and the query Portage loads its elements with, how its Natural key is built and matched, the element source a Reference of the type is limited to, and whether and how it's Stubbed. Portage registers the core types; any plugin (or Portage itself, for plugins it supports) can register more through `ElementTypeRegistry::EVENT_REGISTER_ELEMENT_TYPES`. A relation field with no Field adapter of its own gets the relation adapter when the type it relates to is registered, and the Link and Hyper adapters move links to any registered type.

## "The Payload never names a class" still holds

A Payload names an element type only by its registered short name (`entry`, `asset`, or a name like `formieForm`), never by a class. On Import, the short name is looked up in the Target environment's registry, which was built from code installed there. A short name that isn't registered refuses the whole Payload, as before. The class always comes from the Target environment's own definition, so a Payload still can't make Portage instantiate, autoload or call a class it chooses: at most it can choose among the types the Target environment registered on purpose.

Short names are letters and digits, starting with a letter, so they also work as the element type of a translated reference tag. On Import, a reference tag gets the element type's own reference handle in the Target environment.

## Considered options

- **Put the element class in the Payload and check it against an allow-list**: rejected. The Payload would name classes, and the allow-list would be a registry anyway.
- **Keep the fixed list and add each plugin's type to it by hand**: rejected. Every plugin's Natural key and source logic would be spread across `ElementTypes`, `NaturalKey` and `Stubs` again, and a site's own module couldn't add a type.

## Consequences

- The Source and Target environments must register a type under the same short name. A type registered only in the Source environment refuses the Payload in the Target environment.
- A registered type's Natural key is only as good as its definition. Portage still treats a key matching several elements as matching none.
- `ElementTypes::classFor()`, `nameOf()` and `supports()` keep their signatures; they read the registry.
