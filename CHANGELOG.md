# Release Notes for Portage

## Unreleased
- Plugins can register their own element types, so a Payload can carry References to them, with `ElementTypeRegistry::EVENT_REGISTER_ELEMENT_TYPES`. A relation field to a registered type needs no Field adapter of its own.
- Imports from the utility of more than 10 Root elements, or with an asset Fetch, run in the queue, with their progress in the Import log.

## 1.0.0
- Initial release
