# Check the Preview

The Preview shows what each Root element will become, how related content matches, what will be skipped, and which required fields will be left empty. It also shows where the Payload came from and who exported it.

The Preview refreshes as you change choices, select replacements or upload files. The **Import** or **Paste** button is disabled while it refreshes. Use **Update Preview** to re-check the current site with your choices, for example if content or settings changed since you opened it. If the plan has changed when you try to import, Dolly shows the Preview again so you can review it first.

If the Import log already contains this Payload, the Preview tells you who imported it and when. This warning does not stop you importing it again.

## Choose what happens to each entry

The **Target section** is the section receiving a Root entry. You can keep the Payload’s section or choose another section that allows its entry type.

An **Import mode** controls what happens when a Root entry matches an existing entry in that section. Dolly matches by UID first, then by identifying attributes called a **Natural key**. For entries, this is the section and slug, plus the parent slug path in a Structure.

**Owned content** means blocks and nested entries that belong to an element, including CKEditor nested entries. It travels with full content, recursively. Other related content travels as **References**: pointers that identify it without carrying its full content.

| Choice | What happens |
| --- | --- |
| **Create a new entry** | The default. Dolly creates a disabled entry and leaves any matching entry alone. If the source UID is already used, the new entry gets a fresh UID, as does any of its Owned content. |
| **Overwrite it (on a draft)** | Dolly creates a draft on the matched entry. It applies only fields present in the Payload that are not skipped. A Matrix or Neo field’s blocks are replaced as a whole. The matched live entry is unchanged; its enabled state and authors are kept. |

The Preview shows the matching entry, the available modes, the chosen mode and the Target section. If overwrite is unavailable, it explains why. Changing the Target section resets the mode to creating a new entry, because the matching entry may change.

## Resolve related content

A **Reference** points to related content without carrying its full content. Dolly records a **Resolution** when it matches that Reference to an element in the Target environment. The Preview shows which rule matched it.

Dolly tries the UID first, then the Natural key:

| Related content | Natural key |
| --- | --- |
| Entries | Section + slug, plus parent slug path in Structures |
| Categories | Group + slug, plus parent path |
| Tags | Group + title |
| Users, including entry authors | Email, then username |
| Assets | Volume + folder path + filename |
| Formie forms | Form handle |
| Solspace Calendar events | Calendar + slug; see [Form and event relations](field-types.md#form-and-event-relations) |

If a Natural key matches several elements, Dolly matches none of them. This leaves an **Unresolved reference**: related content Dolly cannot identify in the Target environment.

For an Unresolved reference, the Preview offers the choices that apply:

| Choice | What it means |
| --- | --- |
| Skip | The default. Leave the Reference out and report it. |
| Create a Stub | Create a disabled, minimal element using only the Reference’s identifying information. A **Stub** preserves the relation; it is not a full copy of the source content. |
| Choose a replacement | Relate an existing element in the Target environment instead. |
| Fetch | For an unresolved asset, download its file from the source URL during Import and create the asset in the Target environment. |
| Upload | For an unresolved asset, create the asset from a file you upload in the Preview instead. |

Stubs are created in their original section or group, in its first site, at the top level of a Structure. Users are never Stubbed. An unresolved author is replaced by the user running the Import; unresolved users in user fields are skipped. An entry being imported as a Root element in the same Payload is never Stubbed, because that would duplicate it. Assets, Formie forms and Calendar events are not Stubbed either.

A choice that cannot be carried out refuses the whole Payload before anything is written. The Preview explains the problem so you can change the choice.

## Bring in missing assets

**Fetch** and **Upload** create the asset with the Reference’s title, alt text, focal point and custom field values. Choose its own volume, or another volume and folder path in the Preview.

Fetching needs a usable source URL. It fails for private volumes and sources behind basic auth; upload the file instead. Until you pick a replacement or an upload file, that Reference is skipped.

## Review changes to fields and settings

A **Schema reference** identifies a project-config item, such as a field, entry type, section or site, by UID. **Drift** describes whether that item still matches the Source environment. Dolly compares a **Fingerprint**, a hash of its config recorded at Export, with the Target environment’s config.

| State | What it means |
| --- | --- |
| Matched | The UID and Fingerprint match. |
| Drifted | The UID matches, but the settings changed. Field values are imported with a warning. |
| Type changed | The field has changed type. Its value is skipped with a warning. |
| Missing | The item is absent. Missing field values are skipped with a warning. A Root element with a missing section is not imported; other Root elements can still be imported. You can choose an available Target section that allows its entry type. |

Check the Preview’s **What happens** column and warnings before you continue.
