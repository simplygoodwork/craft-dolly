# Portage

Easily port content from one environment to another.

## Requirements

This plugin requires Craft CMS 5.10.0 or later, and PHP 8.2 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “Portage”. Then press “Install”.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require simplygoodwork/craft-portage

# tell Craft to install the plugin
./craft plugin/install portage
```

## Command line

Export entries (comma-separated IDs) as a Payload, to stdout or a file:

```bash
php craft portage/export 123
php craft portage/export 123,456 --output=page.portage
```

Import a Payload passed as an argument, from a file, or on stdin. `--user` is required: the email of the active user the Import runs as. New entries are created disabled, Unresolved references are skipped and listed, and the Import is recorded in the Import log against that user:

```bash
php craft portage/import 'portage:v1:H4sI…' --user=editor@example.com
php craft portage/import --file=page.portage --user=editor@example.com
php craft portage/import --user=editor@example.com < page.portage
```

Add `--dry-run` to print the Preview and write nothing, not even an Import log entry. The Preview lists the Drift of every field, entry type, section and site that changed since Export, what will be skipped, and the required fields that will be left empty:

```bash
php craft portage/import --file=page.portage --user=editor@example.com --dry-run
```

Related entries, categories, tags and users (including authors) are matched by UID, then by their Natural key: section + slug (plus the parent slug path in Structures) for entries, group + slug (plus parent path) for categories, group + title for tags, and email, then username, for users. A Natural key that matches more than one element matches none, so the Reference is left unresolved rather than related to the wrong element. The Preview shows the rule that matched each Reference.

An Unresolved reference is skipped by default. Choose to create a disabled Stub in its original section or group with `--stub`, or to relate an existing element instead with `--replace`, or put the choices in a JSON file for `--choices`. Users are never Stubbed: an unresolved author is replaced by the `--user` the Import runs as, and unresolved users in user fields are skipped. Nor are entries the same Payload imports as Root elements, since a Stub would duplicate them. Stubs are created in the first site of their section or group, at the top level of a Structure. A choice that can't be carried out refuses the whole Payload before anything is written.

```bash
php craft portage/import --file=page.portage --user=editor@example.com --stub=<uid>,<uid> --replace=<uid>=123
php craft portage/import --file=page.portage --user=editor@example.com --choices=choices.json   # {"<uid>": "stub", "<uid>": 123}
```

A Root entry that matches an existing entry, by UID or else by its Natural key in the target section, can be imported in one of two modes: create a new entry (the default; a UID that's already used gets a fresh one, and so does any of its Owned content) or overwrite. Overwrite creates a draft on the matched entry and applies only the fields the Payload carries and that aren't skipped; a Matrix or Neo field's blocks are replaced as a whole. The matched entry itself is never changed, and its enabled state and authors are kept. Choose overwrite with `--overwrite`, a comma-separated list of Root element UIDs, or `*` for every Root element that matches an entry (the rest are created). Send Root entries to another section with `--section`: a section handle for every Root entry, or comma-separated `uid=handle` pairs. The section must allow the entry type, otherwise the Payload is refused. The report says "Created a draft #X on entry #Y" for each overwrite, and `--dry-run` prints each Root element's match, the modes available, the mode chosen and the target section:

```bash
php craft portage/import --file=page.portage --user=editor@example.com --overwrite='*'
php craft portage/import --file=page.portage --user=editor@example.com --overwrite=<uid> --section=<uid>=blog
```

When the Import log already holds the Payload, the Preview warns who imported it and when. The warning doesn't stop the Import.

Values for fields that are missing or have changed type in this environment are skipped with a warning; values for fields whose settings changed are imported with a warning. A Root element whose section is missing isn't imported, but the rest of the Payload is. A Payload exported from a different Craft major is refused.

SEOmatic's SEO Settings field moves its values as they are, including Twig and object templates. The SEO, Facebook and Twitter images are References like any asset (matched, fetched or cleared with a warning), and only images are accepted. Twig written by hand that contains an asset ID, and the images of the site-level identity and creator settings, move untranslated. Section-level SEOmatic bundles are never read or written. A setting that pulls from a field the Target entry doesn't have is imported with a warning.

Ether SEO's SEO field moves its title, description, keywords, score, social titles and descriptions, robots and canonical URL as they are. The Twitter and Facebook images are References like any asset (matched, fetched or cleared with a warning), and only images are accepted. Ether fills empty robots with the environment's global default robots each time it loads a value, so Portage exports the robots and social texts the entry stores in the database: robots an entry doesn't store are filled with the Target environment's defaults, not the Source environment's. The social handles (the site's Twitter handle and Facebook app ID) come from each environment's own settings and aren't moved. The canonical URL isn't translated, so it may still point at the Source environment.

A Linkit field's link names its link type by Linkit's handle for it (`entry`, `url`, `email`…), never by class. URL, email, phone and social links move as they are, with their custom text and target. An entry, asset, category or user link is a Reference like any other (matched, stubbed, replaced, fetched or cleared with a warning). A Commerce product link, and a link of a type the Target field doesn't enable, is cleared with a warning, before any Reference it holds is resolved.

Colour Swatches fields move the selected swatch by its label. The field looks a swatch up by label in its palette (its own colours, or a palette from `config/colour-swatches.php`) every time it saves, so the Target field's palette decides the colour and CSS class. A label the Target palette doesn't have is dropped and the Preview warns about it, rather than the field quietly replacing it. The field then shows its default swatch, if one is flagged as default. It may also save its default setting or a random colour instead, depending on the Craft version and whether the entry is new.

Formie's Forms field moves its relations like any relation field. Forms aren't in project config, so a form's UID usually differs between environments, and a form that doesn't match by UID matches by its handle. An Unresolved form can be skipped or replaced with another form, but is never Stubbed. Replacements aren't limited to a form template (Formie 3) or form group (Formie 4).

When Solspace Calendar is installed, its events are a registered element type (`calendarEvent`), so a Calendar Events field moves its relations like any relation field. An event is matched by UID, then by its calendar and its slug. The slug is the event's slug in the site the entry was exported from, and it matches the event in any site, since an event's slug can differ per site. A replacement for an unresolved event must be in the same calendar. Events are never Stubbed: skip the Reference or choose a replacement.

## Copying content in the control panel

A user with the **Export content** permission gets **Copy for another environment**, separate from Craft's own Copy (which is unchanged):

- In an entry editor's action menu. It exports what the editor is showing: unsaved changes are saved to the draft first (the provisional draft, or the named draft being edited), and that draft's content is exported as the entry it belongs to. A new entry that isn't published yet is exported as a new entry. A revision, a nested entry and another user's provisional draft can't be exported. Only the sites the user can edit are included in the Payload.
- As a bulk action in the entry index. It exports the selected canonical entries as one Payload with a Root element for each; drafts and revisions in the index aren't offered.

The Payload goes to the clipboard, with a confirmation. A Payload larger than `clipboardLimit` bytes (100,000 by default; set it in `config/portage.php`), or one the browser won't let the page copy, is offered as a `.portage` file to download instead (a button in the notice), which the Portage utility accepts like pasted text. Both actions check that the user can view the entries.

### Copying blocks in Matrix and Neo fields

Users with the "Export content" permission can copy blocks, and users with the "Import content" permission can paste them, in the entry editor:

- **Copy.** Select one or more blocks with their checkboxes, then choose **Copy for another environment** in a block's menu (it copies every selected block when the block is one of them; the page's block IDs are checked against the draft, so a page that's out of date is refused rather than copying the wrong block), or **Copy selected for another environment** in the field's menu. It works for Matrix fields in the cards and inline views, and for Neo fields. A Neo block is copied with the blocks nested under it, and the Payload's Root elements are the blocks you selected. Unsaved changes are saved to your draft first, and the Payload goes to the clipboard like an entry's.
- **Paste.** Choose **Paste from another environment** in a Matrix or Neo field's menu. A Payload on the clipboard is previewed straight away; otherwise the modal asks for the text or a `.portage` file. The modal's Preview has the same choices for Unresolved references as the utility's. **Paste** adds the blocks to the end of the field in your draft of the entry (the provisional draft is created if there's none), as normal edits: the live entry is never changed, and you save or apply the draft as usual. A paste is recorded in the Import log. Only the draft's own site is written, as Craft's editor does; Craft propagates the blocks to the entry's other sites as the field's propagation method says, so a site the user may not edit is never saved. If the paste takes the field past its maximum number of blocks, the Preview warns, and the draft saves anyway (Craft checks the maximum when the draft is applied).

Previewing a paste saves what's on screen to your draft first, which creates the provisional draft if there isn't one, even if you then cancel. This is deliberate: it's what Craft's own "add block" does, and the Preview needs the draft's blocks and sites, so deferring the draft until you click Paste would mean planning against live content.

Matrix blocks can be pasted into any Matrix field that allows their entry types; the blocks it doesn't allow are skipped with a warning. Neo blocks can only be pasted into the Neo field they were copied from (matched by UID), since their block types and hierarchy belong to it: any other field refuses the Payload with a message. A Payload of blocks isn't accepted by the utility or `portage/import`, and a Payload of entries isn't accepted by a field. In the core service, `import()` and `preview()` take a `BlockTarget` (a draft and one of its Matrix or Neo fields) for a paste.

## Large Imports in the queue

An Import from the Portage utility of more than 10 Root elements, or with any asset Fetch from a source URL, runs as a queue job instead of in the request. The Import log entry is created at once as *Queued*, moves to *In progress* as the job starts, and gets each Root element's result as it's imported (each is *not imported yet* until then, and the entry's page reloads itself while the Import is unfinished), then ends *Completed* with a result for every Root element. A Root element that fails keeps its own failed result and doesn't stop the others, since each has its own transaction. An Import is *Failed* only if the job couldn't run at all: the user who queued it is gone or no longer has the **Import content** permission, the Payload is refused, the job couldn't be added to the queue, its worker died (an Import in progress for over an hour without a result is shown as failed), or this environment changed after the Preview so that the Import would no longer do what the Preview showed (nothing is imported then, and the Payload has to be previewed again).

The job carries the Payload text and the choices the Preview showed exactly as the user imported them, plus the fingerprint of the plan the user saw, and runs as the user who queued it. Files uploaded for unresolved assets stay in the user's staging directory until the job has used them, then are discarded, so a queue worker has to share Craft's runtime temp path with the web server. Staged files are purged after 24 hours (when that user next uploads one), so a queue that is more than a day behind fails the Import for an uploaded asset. Without a Shared key, the Payload text sits in Craft's `queue` table, and shows in the Queue Manager's job details, until the job has run; with one it's the encrypted text. A job that has started is never retried, because some of its Root elements may already be imported.

`portage/import` always runs inline, whatever the size. So does pasting blocks into a draft, whatever its size or asset Fetches. This is a product decision that departs from "any asset fetch" in the spec: the editor needs the blocks in its draft when the paste is done, so a paste can't wait for a job. In the core service, `import()` only queues when it's called with `queue: true`, which the utility does.

## Shared key

Set a Shared key to encrypt and authenticate every Payload. In **Settings → Plugins → Portage**, set **Shared key** to an env var, such as `$PORTAGE_SHARED_KEY`, and give that env var the same secret in every environment. Saving the setting only accepts an env var reference, so the secret itself never reaches project config. (A `sharedKey` set in `config/portage.php` isn't checked, so keep it an env var reference there too.)

With a Shared key, every Export produces a `portage:v1e:` Payload, encrypted with Craft's authenticated encryption, and an Import only accepts Payloads encrypted with the same key: an unencrypted Payload, one encrypted with a different key and one changed after Export are all refused. Without a Shared key, an encrypted Payload is refused. If the setting names an env var that isn't set, Export and Import both fail rather than fall back to unencrypted Payloads.

## Field types

Portage moves Craft's core fields (plain text, number, range, dropdown, radio buttons, checkboxes, multi-select, button group, lightswitch, date, time, color, email, Link, money, table, JSON, country, icon, and the relation, Matrix, CKEditor, Neo, Hyper and SEOmatic SEO Settings fields), Linkit, Ether SEO and Colour Swatches fields with a Field adapter each. Adapters for third-party fields are registered only when their plugin is installed. An option field whose options changed between environments drops any selected value the Target field no longer offers, and the Preview warns about it.

A field type with no adapter falls back to Craft's own `serializeValue()` and `normalizeValue()`. The Preview warns that its value will be imported as it is, and may contain environment-specific IDs, since Portage can't translate them. A field that can't be serialized is left out of the Payload.

Fields that hold nested content and have no adapter, such as Content Block and Addresses, are skipped with a warning rather than falling back, since Craft's raw serialization would carry the source IDs of every element inside them untranslated.

A Money field travels with its currency: if the Target field's currency differs, the Preview warns that the amount (in minor units) may change magnitude. A Date field that shows its time zone keeps its named time zone.

### Registering an adapter for your field type

A plugin can register an adapter for its own field class with `FieldAdapterRegistry::EVENT_REGISTER_ADAPTERS`. Implement `simplygoodwork\craftportage\fields\FieldAdapter`: `export()` returns the JSON-safe value for the Payload, and `import()` returns a value for `setFieldValue()` in the Target environment. Adapters are matched on the field's exact class, and a registered adapter replaces the fallback (and any adapter Portage ships for that class).

```php
use simplygoodwork\craftportage\events\RegisterFieldAdaptersEvent;
use simplygoodwork\craftportage\fields\FieldAdapterRegistry;
use yii\base\Event;

Event::on(
    FieldAdapterRegistry::class,
    FieldAdapterRegistry::EVENT_REGISTER_ADAPTERS,
    function(RegisterFieldAdaptersEvent $event) {
        $event->adapters[MyField::class] = new MyFieldAdapter();
    },
);
```

Register the handler while your plugin initializes, before Portage is first used in the request: the registry is built once and an adapter registered later is ignored. Every registered value must implement `FieldAdapter`, or building the registry throws an `InvalidConfigException`. During `export()`, `$context->element()` is the element that holds the value, for a field whose `serializeValue()` needs it; Import never has a Target element while it plans, so `import()` has none. Use `ExportContext::addReference()` and `ImportContext::resolveReference()` for any elements the value points at, and `$context->plan->warn()` for anything the Import drops.

### Registering an element type

A Payload carries related elements as References to the element types Portage knows: entries, categories, tags, users and assets, plus Formie forms (`formieForm`) when Formie is installed. A plugin can register its own element type with `ElementTypeRegistry::EVENT_REGISTER_ELEMENT_TYPES`, under a short name that the Payload carries instead of the class (ADR-0004). Then any relation field to that type moves its relations, matched by UID and then by the type's Natural key, with no Field adapter of its own. So do Link and Hyper links to it.

Extend `simplygoodwork\craftportage\references\ElementTypeDefinition`. Only `elementClass()`, `naturalKey()` (the identifying attributes the Reference carries, JSON-safe) and `find()` (the ID of the one element matching them, or null) are required. By default a Reference of the type isn't limited to an element source and is never Stubbed. Override `sourceFor()`, `isInSource()` and `sourceName()` to limit replacements to the Reference's own source, and `canBeStubbed()` and `stub()` to allow Stubs.

```php
use simplygoodwork\craftportage\events\RegisterElementTypesEvent;
use simplygoodwork\craftportage\references\ElementTypeRegistry;
use yii\base\Event;

Event::on(
    ElementTypeRegistry::class,
    ElementTypeRegistry::EVENT_REGISTER_ELEMENT_TYPES,
    function(RegisterElementTypesEvent $event) {
        $event->types['widget'] = new WidgetDefinition();
    },
);
```

Register the type in every environment, under the same short name: a Payload naming a type that isn't registered is refused. Short names are letters and digits, starting with a letter. Register the handler before Portage is first used in the request, as for adapters. A definition that doesn't extend `ElementTypeDefinition`, an invalid or reserved name, or a class already registered under another name throws an `InvalidConfigException`. `tests/_support/WidgetDefinition.php` is a complete example.

## Limits

An Import refuses a Payload larger than `maxPayloadSize` bytes (8 MB by default), whether as text or decompressed, and stops decompressing as soon as it passes the limit, so a small Payload that decompresses to gigabytes can't exhaust memory. Raise it in `config/portage.php`:

```php
return [
    'maxPayloadSize' => 32 * 1024 * 1024,
];
```

The envelope is validated before anything is planned: a Payload with an unknown format version, a malformed section, or a Root element or Reference whose element type isn't registered in this environment (see "Registering an element type"; by default entries, categories, tags, users and assets, plus Formie forms when Formie is installed) is refused.

## Development

### Running tests

The suite uses [Codeception](https://codeception.com) with Craft’s own test module (`craft\test\Craft`). It runs inside the DDEV project that hosts this plugin, against a dedicated `portage_test` database. That database is wiped and Craft is reinstalled on every run, with Portage installed. The dev database is never touched.

From the DDEV project root:

```bash
# once, to install the plugin's dev dependencies
ddev exec --dir /var/www/html/plugins/portage composer install

# whole suite
ddev exec --dir /var/www/html/plugins/portage composer test

# one file, or one test
ddev exec --dir /var/www/html/plugins/portage vendor/bin/codecept run unit HarnessTest
ddev exec --dir /var/www/html/plugins/portage vendor/bin/codecept run unit HarnessTest:testSavesAndReadsBackMatrixBlocks
```

The bootstrap creates the test database if it's missing. It connects to `db` as `root`/`root` by default. Override that with `PORTAGE_TEST_DB_HOST`, `PORTAGE_TEST_DB_PORT`, `PORTAGE_TEST_DB_NAME`, `PORTAGE_TEST_DB_USER` and `PORTAGE_TEST_DB_PASSWORD`. The database name must contain `test`.

Each test runs in a transaction that is rolled back afterwards, so schema and content created in one test don't leak into the next.

Before committing, run every check (phpstan, coding standards, the whole suite):

```bash
ddev exec --dir /var/www/html/plugins/portage composer check
```

#### Other Craft and plugin versions

GitHub Actions (`.github/workflows/ci.yml`) runs `composer check` against each version set Portage supports. `composer.lock` isn't committed, so each job resolves its own versions:

| Job | PHP | Craft | Hyper | CKEditor | Formie |
|---|---|---|---|---|---|
| Lowest (`--prefer-lowest`) | 8.2 | 5.0.x | 2 | 4.0.x | 3 |
| Craft 5.7 (first with block copy/paste) | 8.2 | 5.7.x | 2 | 4 | 3 |
| Latest with Hyper 3 | 8.4 | latest | 3 | 5 | 4 |
| Latest with Hyper 2 | 8.4 | latest | 2 | 5 | 3 |

Every job installs Formie, so its tests run against Formie 3 or 4 as the table says.

To run the suite against another version set locally, copy the plugin to a scratch directory inside the DDEV web container and resolve there, so your own `vendor/` is left alone. For example, the lowest job, from the DDEV project root:

```bash
ddev exec bash -c '
  rm -rf /tmp/portage-lowest && mkdir /tmp/portage-lowest
  tar -C /var/www/html/plugins/portage --exclude=./vendor --exclude=./.git --exclude=./composer.lock -cf - . | tar -xf - -C /tmp/portage-lowest
  cd /tmp/portage-lowest
  composer config audit.block-insecure false
  composer require --no-update "craftcms/cms:~5.0.0"
  composer require --dev --no-update "verbb/hyper:^2.0" "craftcms/ckeditor:~4.0.0" "verbb/formie:^3.0"
  php8.2 /usr/local/bin/composer update --prefer-lowest --no-interaction
  PORTAGE_TEST_DB_NAME=portage_test_lowest php8.2 vendor/bin/codecept run
'
```

Composer 2.9+ refuses to install versions with published security advisories, which includes Craft 5.0.0 and many early 5.x releases, hence `audit.block-insecure false`. Use a test database of its own (`PORTAGE_TEST_DB_NAME`), since the suite wipes it. Swap the constraints for another job's.

A test of a feature that doesn't exist in every supported version skips itself with `simplygoodwork\craftportage\tests\support\Requires`, rather than failing. Call it first in the test, or in `_before()` for a whole class:

```php
Requires::classes('The JSON field', \craft\fields\Json::class); // a field type added after Craft 5.0
Requires::craft('5.7.0', 'Block copy/paste');                     // behaviour that starts at a Craft version
Requires::package('verbb/hyper', '^3.0', 'Shared link type configs');
```

Console tests use `consoleCommand($command, $params, $ignoreStdOut)`. Pass `false` as the third argument whenever the test asserts `->stdout()`: with `true`, every stdout expectation is skipped and the test passes without checking the output.

### Building schema in tests

`simplygoodwork\craftportage\tests\support\Schema` (in `tests/_support/Schema.php`) builds fields, entry types, sections and entries through Craft's services, and throws with the validation errors (nested entries included) if a save fails:

```php
$textBlock = Schema::blockType('textBlock', [Schema::plainText('body')]);
$page = Schema::entryType('page', [
    Schema::plainText('summary'),
    Schema::matrix('contentBlocks', [$textBlock]),
]);
$section = Schema::section('pages', [$page]);

$entry = Schema::entry($section, $page, [
    'title' => 'Hello',
    'summary' => 'A summary',
    'contentBlocks' => [
        'new1' => ['type' => 'textBlock', 'fields' => ['body' => 'First']],
    ],
]);
```

`Schema::categoryGroup()`, `Schema::category()`, `Schema::tagGroup()`, `Schema::tag()` and `Schema::user()` build related elements. `Schema::site()` adds a site (create sites before the sections that use them), and `Schema::translate($element, $site, [...])` saves an element's content in another site. Use `Schema::field(SomeField::class, 'handle', [...settings])` for any other field type, and `Schema::required($field)` in an entry type's field list to make the field required.

`Schema::hyper()` saves a Hyper field from link types built with `Schema::hyperLinkType(linkTypes\\Url::class, 'url', [...customFields])`, and `Schema::changeHyperLinkTypes()` and `Schema::removeHyperLinkType()` change a Hyper field's link types to simulate a different Target environment. `Schema::withHyperLinkType()` registers an extra link type for a callback, as a plugin would.

`Schema::linkit('cta', [models\\Entry::class, models\\Url::class])` saves a Linkit field that enables those link types, and `Schema::changeLinkitTypes()` changes them to simulate a different Target environment.

`Schema::withEtherSeoSettings(['robots' => ['noindex']], fn() => ...)` runs a callback with Ether SEO's global settings changed, to simulate an environment with different SEO defaults.

`Schema::withColourSwatchesSettings(['palettes' => ['Brand' => [...]]], fn() => ...)` runs a callback with Colour Swatches' config-file palettes changed, to simulate an environment whose `config/colour-swatches.php` differs.

`Schema::calendar('conferences')` saves a Solspace Calendar calendar enabled for every site, and `Schema::calendarEvent($calendar, '2026-12-01 09:00', ['title' => 'Launch', 'slug' => 'launch'])` saves a one-hour event in it. Calendar registers its project config handlers only on the first app in a PHP process, but each test gets a new app, so `tests/_craft/config/app.php` bootstraps `CalendarProjectConfigHandlers` (in `tests/_support/`) to register them on every later app. Without it, calendars are never written to the database, and any test that saves a site fails in Calendar's site handler.

`Schema::seomaticSectionBundle()` gives a section URLs and SEOmatic a section-level meta bundle for it, and returns its row.

`Schema::volume()` saves a volume on its own local filesystem in a temp directory, with public URLs if given a `url`, and `Schema::asset()` saves an asset from a file in `tests/_data/files`, in any folder. Tests that fetch assets serve those files over a local file server, never the internet: `FileServer::start()` (in `tests/_support/FileServer.php`) starts PHP's built-in server once per run and returns its base URL. Under `/files/` it serves the fixtures, `/private/` answers 401 like a source behind basic auth, and `/slow/` waits 3 seconds first. Give a volume `FileServer::start() . '/files'` as its URL so its assets' URLs point at the stub:

```php
$photos = Schema::volume('photos', FileServer::start() . '/files');
$photo = Schema::asset($photos, 'photo.png', 'blog/2026', ['title' => 'Red square', 'alt' => 'A red square']);
```

`Schema::updateEntry()` edits an entry in the Target environment, `Schema::addFieldToEntryType()` adds a field to an entry type's layout, and `Schema::setImportDate()` sets when a logged Import happened. `Schema::withElementType()` registers an element type for a callback, as a plugin would, and `Schema::widget()` saves an element of the test-support type `Widget`. `Schema::form()` saves a Formie form with no fields. To simulate a different Target environment, change the install between Export and Import with `Schema::updateField()`, `Schema::changeFieldType()`, `Schema::deleteField()`, `Schema::updateSection()`, `Schema::disableSectionForSite()`, `Schema::deleteSection()`, `Schema::deleteSite()` and `Schema::deleteVolume()`. `Schema::changeUid()` and `Schema::changeUserIdentity()` make a related element match only by its Natural key, or by a user's username:

```php
$payload = Plugin::getInstance()->getPortage()->export([$entry]);
Schema::updateField($summary->uid, ['charLimit' => 42]);
$plan = Plugin::getInstance()->getPortage()->preview($payload);
```

These refresh Craft's in-memory schema caches, as a new request would. Craft otherwise keeps serving the old fields and entry types to the Import. If you change schema through Craft's services directly, call `Schema::refresh()` afterwards. Add a `Schema` method for any new kind of change rather than changing schema inline in a test.
