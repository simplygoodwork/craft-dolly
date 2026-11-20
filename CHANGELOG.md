# Release Notes for Dolly

## 1.0.0-beta.1 - 2026-11-20

The first beta of Dolly, which copies content from one Craft environment to another.

### Copying and importing
- Copy entries for another environment from an entry editor's action menu, or several at once with a bulk action in the entry index. The Payload goes to the clipboard, or downloads as a `.dolly` file when it's large.
- Copy selected Matrix and Neo blocks, and paste them into a Matrix or Neo field in another environment, from the field's action menu (Craft 5.7 or later). Pasted blocks are added to your draft of the entry, never to the live entry.
- Import entries from **Utilities → Dolly**, or with the `dolly/export` and `dolly/import` console commands.
- Before anything is imported, a Preview shows what will happen to each entry, related element and field. You choose the section, whether to create a new entry or overwrite a matching one (on a draft), and what to do with related elements that don't exist in this environment: skip them, pick a replacement, create a Stub, or fetch or upload an asset. Imported entries arrive disabled.
- The Preview reports Drift: sections, entry types, fields, volumes and sites whose settings differ between the two environments.
- Imports from the utility of more than 10 entries, or with an asset fetch, run in the queue.
- Every Import and paste is recorded in the Import log.
- An optional Shared key encrypts and authenticates every Payload.
- **Export content** and **Import content** permissions.

### Field types
- Craft's core fields, including Matrix, CKEditor (4.0.4 and later), and the Link, Range, JSON and Button Group fields on the Craft versions that have them.
- Neo, Hyper 2 and 3, SEOmatic, Linkit, Ether SEO, Colour Swatches, Retour short links, SimpleMap, Preparse, Position, Code, TableMaker, Icon Picker, Formie's Forms field, Solspace Calendar events, and LJ Dynamic Fields fields declared in config.
- Plugins can register Field adapters for their own field types, and their own element types.

### Requirements
- Craft CMS 5.0 or later, and PHP 8.2 or later.
