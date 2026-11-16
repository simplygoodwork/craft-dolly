# Release Notes for Portage

## Unreleased
- Portage now requires Craft 5.0 or later, rather than 5.10. Copying and pasting Matrix and Neo blocks needs Craft 5.7 or later. The Link, Range, JSON and Button Group fields are moved on the Craft versions that have them, and CKEditor fields from CKEditor 4.0.
- Plugins can register their own element types, so a Payload can carry References to them, with `ElementTypeRegistry::EVENT_REGISTER_ELEMENT_TYPES`. A relation field to a registered type needs no Field adapter of its own.
- Formie's Forms field moves its relations. A form matches by UID or else by its handle, and is never Stubbed.
- Colour Swatches fields move their selected swatch by label. A label the Target palette doesn't have is dropped with a Preview warning instead of being silently replaced.
- LJ Dynamic Fields' fields declared in the `dynamicFields` setting move their values as the sections, entry types, category groups, volumes, sites or elements they're the IDs of, and become those IDs in the Target environment. Undeclared ones keep the fallback and its warning.
- SimpleMap, Position, Code and TableMaker fields move without the "no adapter" warning. SimpleMap's location travels without its source IDs, and a TableMaker table whose rich text cells link to elements by ID is imported with a warning.
- Preparse fields are left out of the Payload, and the Target environment recalculates them from the field's template.
- Hyper 3 is supported. Fields on a shared link type config move their links, and links to Stubs and fetched assets keep their element. A link now matches its link type by handle, and the Preview reports Drift for each field's link types. Hyper links in a Payload exported by an earlier version of Portage are skipped with a warning that their link type doesn't exist.
- Hyper 2.0 links to assets whose fetch failed are removed, as on later Hyper versions, rather than kept with no asset.
- Imports from the utility of more than 10 Root elements, or with an asset Fetch, run in the queue, with their progress in the Import log.

## 1.0.0
- Initial release
