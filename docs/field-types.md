# Supported field types

A **Field adapter** translates a field value for Export and Import, including any IDs inside it. Dolly provides adapters for these fields. Third-party adapters are registered only when their plugin is installed. Core fields added after Craft 5.0 are available only on Craft versions that include them; see [Version notes](#version-notes).

An **Embedded reference** is an element ID inside a field value, such as a rich text reference tag or link target, rather than a normal relation. Supported adapters translate these too.

| Fields | What moves |
| --- | --- |
| Plain Text, Number, Range, Lightswitch, Color, Email, Table, JSON, Country, Icon | Their values. |
| Dropdown, Radio Buttons, Checkboxes, Multi-select, Button Group | Selected options. Values no longer offered by the Target field are dropped with a Preview warning. |
| Date, Time | Their values. A Date field that shows its time zone keeps its named time zone. |
| Money | Amount and currency. If the Target field uses another currency, the Preview warns that the amount in minor units may change magnitude. |
| Entries, Categories, Tags, Users, Assets | References matched in the Target environment. |
| Matrix, Neo | Blocks and their Owned content. |
| CKEditor | Rich text, Embedded references and nested entries. Supported from CKEditor 4.0.4, with the same behavior across supported versions. |
| Link, Hyper, Linkit | Links and their References. See the notes below. |
| SEOmatic SEO Settings, Ether SEO | Entry SEO values and image References. See the notes below. |
| Colour Swatches | The selected swatch label, checked against the Target palette. |
| Retour short link | The URL, checked for redirect collisions. |
| SimpleMap (Maps) | Location data; the Target environment creates its own record. |
| Preparse | No stored value; the Target environment recalculates it on save. |
| Position, Code | Their values, including the Code field’s language. |
| TableMaker | Columns, rows, caption and translated References in rich text cells. |
| Icon Picker | The selected icon and its icon set. |
| Formie Forms, Solspace Calendar Events | References to forms or events already in the Target environment. |
| LJ Dynamic Fields: Dropdown, Radio Buttons, Checkboxes, Multi-select | IDs translated according to your [Dynamic Fields config](developers.md#configure-dynamic-fields). Undeclared fields use the fallback described below. |


## Version notes

Some fields only exist in newer Craft versions, so Dolly only moves them where Craft has them: Link (Craft 5.3), Range (5.5), JSON and Button Group (5.7).

Some plugin releases allow Craft 5.0 but don't work with it, or with Dolly:

- Hyper 2.3.1 and later use a class Craft 5.8 added, so a site on Craft 5.0–5.7 needs Hyper 2.3.0 or earlier.
- Formie 3.0.22–3.1.0 use an element query parameter Craft added in 5.2, so a site on Craft 5.0 or 5.1 needs Formie 3.1.1 or later. Before 3.0.22, forms' UIDs are out of sync, so Dolly matches forms only by their handle.
- CKEditor 4.0.0–4.0.3 replace nested entries with their rendered HTML whenever an entry is saved outside the control panel (Craft's `resave` commands included), so Dolly skips CKEditor fields on those versions, with a warning in the Preview. Update to CKEditor 4.0.4 or later.

## Links

Hyper 2 and 3 work the same way, including fields using Hyper 3’s shared link type configs. A link matches its type in the Target field by handle. The Preview reports Drift for each field’s link types.

Linkit identifies link types by handle (`entry`, `url`, `email`…), never by class. URL, email, phone and social links keep their values, custom text and target. Entry, asset, category and user links travel as References. They can be matched, Stubbed, replaced, Fetched or cleared with a warning, as appropriate for their type.

Commerce product links and link types the Target Linkit field does not enable are cleared with a warning. This happens before Dolly resolves any Reference inside the link.

## SEO fields

**SEOmatic SEO Settings** keeps its values, including Twig and object templates. SEO, Facebook and Twitter images are asset References: matched, Fetched or cleared with a warning. Only images are accepted.

Handwritten Twig containing asset IDs is not translated. Images in site-level identity and creator settings also move untranslated. Dolly never reads or writes section-level SEOmatic meta bundles. If a setting reads a field the Target entry does not have, it is imported with a warning.

**Ether SEO** keeps the title, description, keywords, score, social titles and descriptions, robots and canonical URL. Twitter and Facebook images are asset References: matched, Fetched or cleared with a warning. Only images are accepted.

Ether fills empty robots from the environment’s global default whenever it loads a value. Dolly therefore exports the robots and social texts stored in the entry’s database record. Robots the entry does not store use the Target environment’s defaults. Social handles—the site’s Twitter handle and Facebook app ID—come from each environment’s settings and do not move. The canonical URL is not translated and may still point at the Source environment.

## Colour Swatches

The field finds a swatch by label whenever it saves, using its own colours or a palette from `config/colour-swatches.php`. The Target palette determines the colour and CSS class.

A label missing from the Target palette is dropped with a Preview warning. The field then displays its default swatch, if one is marked as default. Depending on the Craft version and whether the entry is new, it may also save its default setting or a random colour.

## Retour short links

The URL moves as it is. Retour creates the redirect when the imported entry is saved, or when an overwrite draft is applied.

Dolly skips a short link with a Preview warning if its URL already belongs to another redirect in the Target environment. That could be another element’s short link or a manual redirect. The entry’s own short link is allowed, so overwriting an entry with its own short link works.

A short link in a Matrix or Neo block belongs to the top entry that owns it. Retour ties the redirect to that entry. Overwriting that entry keeps the short link. So does pasting into a draft of that entry or one of its nested entries. Pasting into another entry skips it.

If two Root elements in a Payload use the same short link, the later one’s short link is skipped with a Preview warning. A skipped short link leaves a new entry without one. An overwrite draft keeps the entry’s existing short link.

## Maps and calculated values

SimpleMap moves latitude, longitude, zoom, address and its parts, and what3words. It leaves out SimpleMap’s record, owner, site and field IDs. SimpleMap writes its own record when it saves the new entry or overwrite draft, so location searching and sorting work in the Target environment.

Preparse values are not carried in the Payload. Preparse renders them from the field’s template each time an element saves. The Target environment recalculates them from its own content and template. The Preview notes this for each Preparse field.

## Icon Picker

The selected icon travels with its icon set. Dolly matches the set by UID, stored by Icon Picker 3.1 and later. If the value has no UID, it uses the set’s handle.

If the set is missing or the Target field does not offer it, the icon is dropped with a Preview warning. If an SVG file is missing from the Target environment’s icon sets folder, the value is imported with a warning. It appears once you deploy the file. The folder defaults to `@webroot/icon-picker/` and is not in project config.

Values saved by Icon Picker 3.0.0 do not name their icon set and move as they are.

## TableMaker rich text

TableMaker moves columns, rows and caption as they are, except for entry, category and asset links in rich text cells (TableMaker 5.1.1+). These links store an element reference as a URL fragment, such as `<a href="https://…/page#entry:12@1:url">`. A cell can also contain reference tags.

Both are Embedded references. Their element IDs and sites travel as UIDs and resolve like other References. You can skip, replace or Stub them. If a link’s element or site does not resolve, Dolly removes the link and keeps its text, with a Preview warning. An unresolved reference tag becomes its fallback text, as in CKEditor.

Plain text cells remain text. TableMaker does not resolve reference tags in them, so neither does Dolly.

## Form and event relations

**Formie forms** are not in project config, so their UIDs usually differ between environments. Dolly matches by UID, then handle. Before Formie 3.0.22, forms’ UIDs were out of sync, so those forms match only by handle.

You can skip an unresolved form or replace it with another form. Forms are never Stubbed. Replacements are not limited to a form template (Formie 3) or form group (Formie 4).

Formie 4 limits control panel form queries to forms you can view in Formie. Its Forms field does not, and Dolly follows the field’s behavior. A control panel Import can relate a form you cannot view in Formie, just as editing the field can. A form you choose as a replacement must still be one you can view.

**Solspace Calendar events** are registered as `calendarEvent` when Calendar is installed and enabled. Event relations match by UID, then calendar and slug. The Payload uses the slug from the site you exported the entry from. It can match an event in any site, because an event’s slug can differ per site.

A replacement event must be in the same calendar. Events are never Stubbed. Skip the Reference or choose a replacement.

## Other field types

A field without an adapter falls back to Craft’s `serializeValue()` and `normalizeValue()`. The Preview warns that the value will be imported as it is and may contain environment-specific IDs Dolly cannot translate. A field that cannot be serialized is left out of the Payload.

Fields with nested content but no adapter, such as Content Block and Addresses, are skipped with a warning. Raw serialization would carry the source IDs of the elements inside them without translation.
