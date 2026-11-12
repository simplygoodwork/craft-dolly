# A Payload carries full content only for Root elements and their Owned content

Related elements form an unbounded graph (entry A relates to B, which relates to C, which relates back to A), so a Payload contains full content only for the Root elements the user selected and their Owned content (blocks, nested entries, CKEditor nested entries). Every other related element — and every Embedded reference — travels as a Reference: identifying information (UID, Natural key, title, type, URL) but no content. On Import, References are resolved by UID, then Natural key; Unresolved references are skipped by default, or the user may create a Stub, pick a replacement, or (for assets) fetch or upload the file.

## Considered Options

- **Recursively include related elements' content**: rejected because Payloads balloon unpredictably, cycles need special handling, and users get surprise content created in the Target environment that they never selected.

## Consequences

- Porting a cluster of interrelated entries requires selecting each as a Root element (a later version may let users add related elements to the same Payload explicitly).
- A Stub is never a faithful copy; it exists only so the relation survives the Import.
- Deliberate exception: an asset Reference also carries the asset's title, alt text, focal point and custom field values, so an asset created by fetching or uploading during Import isn't stripped of its metadata (notably alt text).
