# Portage

Easily port content from one environment to another.

## Requirements

This plugin requires Craft CMS 5.12.0 or later, and PHP 8.2 or later.

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

## Shared key

Set a Shared key to encrypt and authenticate every Payload. In **Settings → Plugins → Portage**, set **Shared key** to an env var, such as `$PORTAGE_SHARED_KEY`, and give that env var the same secret in every environment. Saving the setting only accepts an env var reference, so the secret itself never reaches project config. (A `sharedKey` set in `config/portage.php` isn't checked, so keep it an env var reference there too.)

With a Shared key, every Export produces a `portage:v1e:` Payload, encrypted with Craft's authenticated encryption, and an Import only accepts Payloads encrypted with the same key: an unencrypted Payload, one encrypted with a different key and one changed after Export are all refused. Without a Shared key, an encrypted Payload is refused. If the setting names an env var that isn't set, Export and Import both fail rather than fall back to unencrypted Payloads.

## Limits

An Import refuses a Payload larger than `maxPayloadSize` bytes (8 MB by default), whether as text or decompressed, and stops decompressing as soon as it passes the limit, so a small Payload that decompresses to gigabytes can't exhaust memory. Raise it in `config/portage.php`:

```php
return [
    'maxPayloadSize' => 32 * 1024 * 1024,
];
```

The envelope is validated before anything is planned: a Payload with an unknown format version, a malformed section, or a Root element or Reference whose element type isn't an entry, category, tag, user or asset is refused.

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

`Schema::seomaticSectionBundle()` gives a section URLs and SEOmatic a section-level meta bundle for it, and returns its row.

`Schema::volume()` saves a volume on its own local filesystem in a temp directory, with public URLs if given a `url`, and `Schema::asset()` saves an asset from a file in `tests/_data/files`, in any folder. Tests that fetch assets serve those files over a local file server, never the internet: `FileServer::start()` (in `tests/_support/FileServer.php`) starts PHP's built-in server once per run and returns its base URL. Under `/files/` it serves the fixtures, `/private/` answers 401 like a source behind basic auth, and `/slow/` waits 3 seconds first. Give a volume `FileServer::start() . '/files'` as its URL so its assets' URLs point at the stub:

```php
$photos = Schema::volume('photos', FileServer::start() . '/files');
$photo = Schema::asset($photos, 'photo.png', 'blog/2026', ['title' => 'Red square', 'alt' => 'A red square']);
```

`Schema::updateEntry()` edits an entry in the Target environment, `Schema::addFieldToEntryType()` adds a field to an entry type's layout, and `Schema::setImportDate()` sets when a logged Import happened. To simulate a different Target environment, change the install between Export and Import with `Schema::updateField()`, `Schema::changeFieldType()`, `Schema::deleteField()`, `Schema::updateSection()`, `Schema::disableSectionForSite()`, `Schema::deleteSection()`, `Schema::deleteSite()` and `Schema::deleteVolume()`. `Schema::changeUid()` and `Schema::changeUserIdentity()` make a related element match only by its Natural key, or by a user's username:

```php
$payload = Plugin::getInstance()->getPortage()->export([$entry]);
Schema::updateField($summary->uid, ['charLimit' => 42]);
$plan = Plugin::getInstance()->getPortage()->preview($payload);
```

These refresh Craft's in-memory schema caches, as a new request would. Craft otherwise keeps serving the old fields and entry types to the Import. If you change schema through Craft's services directly, call `Schema::refresh()` afterwards. Add a `Schema` method for any new kind of change rather than changing schema inline in a test.
