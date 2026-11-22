# Command line

Run these commands from your Craft project directory.

## Export entries

Pass one entry ID or comma-separated IDs. Dolly writes the Payload to stdout unless you choose a file:

```bash
php craft dolly/export 123
php craft dolly/export 123,456 --output=page.dolly
```

## Preview and import entries

Pass Payload text as an argument, read it from a file, or use stdin. `--user` is required: give the email of the active user who will run the Import. New entries are disabled. Unresolved references are skipped and listed by default. The Import log records the Import against that user.

```bash
php craft dolly/import 'dolly:v1:H4sI…' --user=editor@example.com
php craft dolly/import --file=page.dolly --user=editor@example.com
php craft dolly/import --user=editor@example.com < page.dolly
```

Add `--dry-run` to print the Preview without writing anything, including an Import log entry. It lists changed fields, entry types, sections and sites, skipped values and required fields that will be left empty.

```bash
php craft dolly/import --file=page.dolly --user=editor@example.com --dry-run
```

## Choose how to handle Unresolved references

Use `--stub` for a comma-separated list of Reference UIDs to create as disabled Stubs. Use `--replace` for `uid=elementId` pairs, or `--choices` for a JSON file of choices. The [Preview rules](preview.md#resolve-related-content) apply here too. Unresolved authors use the `--user` identity; unresolved user-field values are skipped.

Replace `<uid>` in these examples with the relevant UID before running the command.

```bash
php craft dolly/import --file=page.dolly --user=editor@example.com --stub=<uid>,<uid> --replace=<uid>=123
php craft dolly/import --file=page.dolly --user=editor@example.com --choices=choices.json   # {"<uid>": "stub", "<uid>": 123}
```

For assets, `--fetch` takes comma-separated UIDs to Fetch into their own volume and folder. In a `--choices` JSON file, a Reference UID can map to `"skip"`, `"stub"`, `"fetch"` or a replacement element ID. Use an object to choose a Fetch volume and folder, or to Upload from a local file:

```json
{
    "<asset-uid-to-fetch>": {"choice": "fetch", "volume": "photos", "folder": "blog/2026"},
    "<asset-uid-to-upload>": {"choice": "upload", "path": "/path/to/photo.png"}
}
```

`--stub`, `--replace` and `--fetch` override the choices file.

## Overwrite or choose a Target section

Use `--overwrite` with comma-separated Root element UIDs, or `'*'` for every Root element that matches an entry. Non-matching Root elements are created as new entries. Overwrite creates a draft, as described in [Choose what happens to each entry](preview.md#choose-what-happens-to-each-entry).

Use `--section` with a section handle for all Root entries, or comma-separated `uid=handle` pairs for individual Root entries. The section must allow the entry type or Dolly refuses the Payload.

```bash
php craft dolly/import --file=page.dolly --user=editor@example.com --overwrite='*'
php craft dolly/import --file=page.dolly --user=editor@example.com --overwrite=<uid> --section=<uid>=blog
```

The result reports “Created a draft #X on entry #Y” for each overwrite. With `--dry-run`, it prints each Root element’s match, available modes, chosen mode and Target section. Repeated-Payload warnings, Reference matching and Drift work as described in [Check the Preview](preview.md).
