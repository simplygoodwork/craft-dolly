# Portage

Portage moves content between Craft environments that share project config but have no connection to each other. Content leaves one environment as a self-contained Payload and enters another through an Import.

## Language

### Moving content

**Payload**:
The portable, version-tagged representation of selected content that a user carries from one environment to another.
_Avoid_: Blob, bundle, package, export file

**Export**:
Producing a Payload from selected content in the source environment.
_Avoid_: Copy, dump

**Import**:
Applying a Payload to the target environment, always preceded by a Preview.
_Avoid_: Paste, restore, sync

**Preview**:
The report shown before an Import commits, listing every Resolution, Drift and skipped value, and the user's choices for Unresolved References.
_Avoid_: Dry run, diff

**Source environment** / **Target environment**:
The environment a Payload was exported from, and the one it is imported into.
_Avoid_: From/to, origin, destination

**Shared key**:
An optional secret configured identically in every environment that, when set, makes every Payload encrypted and authenticated, and makes unencrypted Payloads unacceptable for Import.
_Avoid_: Password, encryption setting, token

**Import log**:
The permanent record in the Target environment of each Import: who ran it, when, the Payload's identity, the Source environment it came from and the result for each Root element.
_Avoid_: History, audit trail

### What a Payload contains

**Root element**:
An element the user explicitly selected for Export: a whole entry, or a Matrix/Neo block.
_Avoid_: Selected item, top-level element

**Owned content**:
Elements that belong to a Root element and travel with full content: its blocks, nested entries and CKEditor nested entries, recursively.
_Avoid_: Children, descendants

**Reference**:
A pointer in a Payload to an element that is related but not owned. It carries identifying information but no content.
_Avoid_: Link, relation stub, dependency

**Schema reference**:
A pointer in a Payload to a project-config-defined thing (site, section, entry type, field, volume, group, block type), expressed by its UID.
_Avoid_: Config ID, handle reference

### Resolving References

**Resolution**:
The outcome of matching a Reference to an element in the Target environment, recording which rule matched.
_Avoid_: Lookup, mapping

**Natural key**:
The identifying attributes used to match a Reference when the UID doesn't match, such as section + slug for an entry or volume + folder path + filename for an asset.
_Avoid_: Fallback key, fuzzy match

**Unresolved reference**:
A Reference that neither its UID nor its Natural key matched in the Target environment.
_Avoid_: Missing relation, broken link

**Stub**:
A disabled, minimal element created during Import to satisfy an Unresolved reference, populated only from the Reference's identifying information.
_Avoid_: Placeholder, shell entry

### Schema compatibility

**Fingerprint**:
A hash of a Schema reference's config at Export time, used to detect Drift.
_Avoid_: Checksum, signature

**Drift**:
The compatibility state of one Schema reference between the Source and Target environments: *matched*, *drifted* (same UID, different Fingerprint), *type-changed*, or *missing*.
_Avoid_: Config mismatch, schema diff

### Field handling

**Field adapter**:
The per-field-type translator that turns a field value into portable form on Export and back into a target-environment value on Import, including any IDs embedded in the value.
_Avoid_: Serializer, transformer, handler

**Embedded reference**:
An element ID stored inside a field value rather than as a relation, such as a rich text reference tag, a link field target or an SEOmatic image.
_Avoid_: Inline ID, hidden relation
