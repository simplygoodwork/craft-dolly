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

Import a Payload passed as an argument, from a file, or on stdin. New entries are created disabled, Unresolved references are skipped and listed, and the Import is recorded in the Import log:

```bash
php craft portage/import 'portage:v1:H4sI…'
php craft portage/import --file=page.portage
php craft portage/import < page.portage
```

Add `--dry-run` to print the Preview and write nothing, not even an Import log entry. The Preview lists the Drift of every field, entry type, section and site that changed since Export, what will be skipped, and the required fields that will be left empty:

```bash
php craft portage/import --file=page.portage --dry-run
```

Values for fields that are missing or have changed type in this environment are skipped with a warning; values for fields whose settings changed are imported with a warning. A Root element whose section is missing isn't imported, but the rest of the Payload is. A Payload exported from a different Craft major is refused.

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

Use `Schema::field(SomeField::class, 'handle', [...settings])` for any other field type, and `Schema::required($field)` in an entry type's field list to make the field required.

To simulate a different Target environment, change the install between Export and Import with `Schema::updateField()`, `Schema::changeFieldType()`, `Schema::deleteField()` and `Schema::deleteSection()`:

```php
$payload = Plugin::getInstance()->getPortage()->export([$entry]);
Schema::updateField($summary->uid, ['charLimit' => 42]);
$plan = Plugin::getInstance()->getPortage()->preview($payload);
```

These refresh Craft's in-memory schema caches, as a new request would. Craft otherwise keeps serving the old fields and entry types to the Import. If you change schema through Craft's services directly, call `Schema::refresh()` afterwards. Add a `Schema` method for any new kind of change rather than changing schema inline in a test.
