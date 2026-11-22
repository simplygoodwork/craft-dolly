# Testing

## Run tests

The suite uses [Codeception](https://codeception.com) with Craft’s own test module (`craft\test\Craft`). It runs inside the DDEV project that hosts this plugin, against a dedicated `dolly_test` database. That database is wiped and Craft is reinstalled on every run, with Dolly installed. The dev database is never touched.

From the DDEV project root:

```bash
# once, to install the plugin's dev dependencies
ddev exec --dir /var/www/html/plugins/dolly composer install

# whole suite
ddev exec --dir /var/www/html/plugins/dolly composer test

# one file, or one test
ddev exec --dir /var/www/html/plugins/dolly vendor/bin/codecept run unit HarnessTest
ddev exec --dir /var/www/html/plugins/dolly vendor/bin/codecept run unit HarnessTest:testSavesAndReadsBackMatrixBlocks
```

The bootstrap creates the test database if it's missing. It connects to `db` as `root`/`root` by default. Override that with `DOLLY_TEST_DB_HOST`, `DOLLY_TEST_DB_PORT`, `DOLLY_TEST_DB_NAME`, `DOLLY_TEST_DB_USER` and `DOLLY_TEST_DB_PASSWORD`. The database name must contain `test`.

Each test runs in a transaction that is rolled back afterwards. Its schema and content do not carry over to the next test.

The suites use `tests/_support/CraftModule.php`. This is Craft’s test module with Craft 5.2.7’s rollback fix backported. Before 5.2.7, test transactions never began.

Before committing, run every check (phpstan, coding standards, the whole suite):

```bash
ddev exec --dir /var/www/html/plugins/dolly composer check
```

## Test other Craft and plugin versions

GitHub Actions (`.github/workflows/ci.yml`) runs `composer check` against each version set Dolly supports. `composer.lock` isn't committed, so each job resolves its own versions:

| Job | PHP | Craft | Hyper | CKEditor | Formie | Icon Picker |
|---|---|---|---|---|---|---|
| Lowest (`--prefer-lowest`) | 8.2 | 5.0.x | 2 | 4.0.4 | 3.1.1 | 3.0.0 |
| Craft 5.7 (first with block copy/paste) | 8.2 | 5.7.x | 2 | 4 | 3 | 3.0.x |
| Latest with Hyper 3 | 8.4 | latest | 3 | 5 | 4 | latest |
| Latest with Hyper 2 | 8.4 | latest | 2 | 5 | 3 | latest |

Every job installs Formie, so its tests run against Formie 3 or 4 as the table says.

To test another version set locally, copy the plugin to a scratch directory inside the DDEV web container. Resolve dependencies there to leave your own `vendor/` alone. For example, run the lowest job from the DDEV project root:

```bash
ddev exec bash -c '
  rm -rf /tmp/dolly-lowest && mkdir /tmp/dolly-lowest
  tar -C /var/www/html/plugins/dolly --exclude=./vendor --exclude=./.git --exclude=./composer.lock -cf - . | tar -xf - -C /tmp/dolly-lowest
  cd /tmp/dolly-lowest
  composer config audit.block-insecure false
  composer require --no-update "craftcms/cms:~5.0.0"
  composer require --dev --no-update "verbb/hyper:^2.0" "craftcms/ckeditor:~4.0.4" "verbb/formie:^3.1.1"
  php8.2 /usr/local/bin/composer update --prefer-lowest --no-interaction
  DOLLY_TEST_DB_NAME=dolly_test_lowest php8.2 -d display_errors=0 -d "error_reporting=E_ALL & ~E_DEPRECATED" vendor/bin/codecept run
'
```

Composer 2.9+ refuses versions with published security advisories. These include Craft 5.0.0 and many early 5.x releases, so the recipe sets `audit.block-insecure false`. Use a separate test database through `DOLLY_TEST_DB_NAME`; the suite wipes it. Change the constraints to test another job’s versions.

The `-d` options apply the error settings from CI’s production `php.ini`. DDEV’s PHP CLI displays deprecations. Some old Craft dependencies raise them on PHP 8.2 while Codeception starts, before the test bootstrap can buffer output. That output makes tests fail with “headers have already been sent”.

Use `simplygoodwork\craftdolly\tests\support\Requires` to skip tests for features a supported version does not have. Call it first in the test, or in `_before()` for a whole class:

```php
Requires::classes('The JSON field', \craft\fields\Json::class); // a field type added after Craft 5.0
Requires::craft('5.7.0', 'Block copy/paste');                     // behaviour that starts at a Craft version
Requires::package('verbb/hyper', '^3.0', 'Shared link type configs');
```

phpstan also checks each job’s versions. Guard code that uses an API available only in some versions with runtime checks such as `class_exists()`, `defined()` or `property_exists()`.

For older versions, the corresponding phpstan errors are ignored in `phpstan.neon`. Each ignore is scoped to its file and message, with `reportUnmatched: false`. Jobs whose versions provide the API still check it in full.

Console tests use `consoleCommand($command, $params, $ignoreStdOut)`. Pass `false` as the third argument whenever the test asserts `->stdout()`: with `true`, every stdout expectation is skipped and the test passes without checking the output.

## Build schema in tests

Use `simplygoodwork\craftdolly\tests\support\Schema`, in `tests/_support/Schema.php`, to build fields, entry types, sections and entries through Craft’s services. If a save fails, it throws with the validation errors, including errors on nested entries:

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

Use these helpers for related elements, sites and fields:

- `Schema::categoryGroup()`, `Schema::category()`, `Schema::tagGroup()`, `Schema::tag()` and `Schema::user()` build related elements.
- `Schema::site()` adds a site. Create sites before the sections that use them.
- `Schema::translate($element, $site, [...])` saves an element’s content in another site.
- `Schema::field(SomeField::class, 'handle', [...settings])` builds another field type.
- `Schema::required($field)` makes a field required when used in an entry type’s field list.

`Schema::hyper()` saves a Hyper field. Build its link types with `Schema::hyperLinkType(linkTypes\\Url::class, 'url', [...customFields])`. Use `Schema::changeHyperLinkTypes()` and `Schema::removeHyperLinkType()` to simulate link types changing in the Target environment. `Schema::withHyperLinkType()` registers an extra link type for a callback, as a plugin would.

On Hyper 3, `Schema::hyperLinkTypeConfig('navigation', [...linkTypes])` saves a shared link type config and returns its UID. Use that UID in `Schema::hyper('links', [], ['linkTypeConfig' => $uid])`, or use `'default'` for Hyper’s Default config. `Schema::changeHyperLinkTypeConfig()` changes a shared config’s link types. Guard these tests with `Requires::package('verbb/hyper', '^3.0', ...)`.

`Schema::linkit('cta', [models\\Entry::class, models\\Url::class])` saves a Linkit field with those link types enabled. `Schema::changeLinkitTypes()` changes them to simulate a different Target environment.

`Schema::withEtherSeoSettings(['robots' => ['noindex']], fn() => ...)` runs a callback with Ether SEO's global settings changed, to simulate an environment with different SEO defaults.

`Schema::withColourSwatchesSettings(['palettes' => ['Brand' => [...]]], fn() => ...)` runs a callback with Colour Swatches' config-file palettes changed, to simulate an environment whose `config/colour-swatches.php` differs.

`Schema::iconSet(SvgFolder::class, 'brand', ['folder' => 'brand'])` saves an Icon Picker icon set, and `Schema::deleteIconSet()` deletes one.

`Schema::calendar('conferences')` saves a Solspace Calendar calendar enabled for every site. `Schema::calendarEvent($calendar, '2026-12-01 09:00', ['title' => 'Launch', 'slug' => 'launch'])` saves a one-hour event in it.

Calendar registers project config handlers only on the first app in a PHP process, but each test gets a new app. To register them on later apps, `tests/_craft/config/app.php` bootstraps `CalendarProjectConfigHandlers` from `tests/_support/`. Without this, calendars never reach the database, and any test that saves a site fails in Calendar’s site handler.

`Schema::retourRedirect('go/summer', 'offers/summer')` saves a manual Retour redirect. `Schema::deleteRetourRedirect('/go/summer')` deletes one. This is needed because Retour keeps a block’s short link redirect when its entry is deleted.

`Schema::applyDraft($draftId)` applies a draft as an editor would, in a separate request as far as Retour is concerned.

`Schema::seomaticSectionBundle()` gives a section URLs and SEOmatic a section-level meta bundle for it, and returns its row.

`Schema::volume()` saves a volume with its own local filesystem in a temp directory. Give it a `url` for public URLs. `Schema::asset()` saves an asset from `tests/_data/files`, in any folder.

Asset Fetch tests use a local file server, never the internet. `FileServer::start()`, in `tests/_support/FileServer.php`, starts PHP’s built-in server once per run and returns its base URL. It serves fixtures at `/files/`, returns 401 at `/private/` to simulate basic auth, and waits 3 seconds at `/slow/`.

Give a volume `FileServer::start() . '/files'` as its URL so its assets point to that server:

```php
$photos = Schema::volume('photos', FileServer::start() . '/files');
$photo = Schema::asset($photos, 'photo.png', 'blog/2026', ['title' => 'Red square', 'alt' => 'A red square']);
```

Use these helpers to change content or the test environment:

- `Schema::updateEntry()` edits an entry in the Target environment.
- `Schema::addFieldToEntryType()` adds a field to an entry type’s layout.
- `Schema::setImportDate()` changes when a logged Import happened.
- `Schema::withElementType()` registers a type with Dolly and Craft for a callback, as a plugin would. `Schema::widget()` saves an element of the test-support `Widget` type.
- `Schema::form()` saves a Formie form with no fields.
- `Schema::asControlPanelRequest()` runs a callback as a control panel request, like a control panel Preview or Import.

To simulate a different Target environment, change the install between Export and Import. Use `Schema::updateField()`, `Schema::changeFieldType()`, `Schema::deleteField()`, `Schema::updateSection()`, `Schema::disableSectionForSite()`, `Schema::deleteSection()`, `Schema::deleteSite()` and `Schema::deleteVolume()`.

`Schema::recreateSection()` gives a section a new ID under the same UID, as it would have in another environment. It applies the section through project config. Before Craft 5.4.9, `saveSection()` gives every new section a new UID.

Use `Schema::changeUid()` and `Schema::changeUserIdentity()` to make a related element match only by its Natural key, or a user’s username. For example, change a field’s settings between Export and Preview:

```php
$payload = Plugin::getInstance()->getDolly()->export([$entry]);
Schema::updateField($summary->uid, ['charLimit' => 42]);
$plan = Plugin::getInstance()->getDolly()->preview($payload);
```

These refresh Craft's in-memory schema caches, as a new request would. Craft otherwise keeps serving the old fields and entry types to the Import. If you change schema through Craft's services directly, call `Schema::refresh()` afterwards. Add a `Schema` method for any new kind of change rather than changing schema inline in a test.
