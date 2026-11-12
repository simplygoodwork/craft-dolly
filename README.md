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

Use `Schema::field(SomeField::class, 'handle', [...settings])` for any other field type.
