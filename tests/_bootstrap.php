<?php

/**
 * Boots Craft against a dedicated test database with Portage installed.
 *
 * The suite wipes and reinstalls Craft in the test database on every run, so
 * this bootstrap refuses to run against anything that looks like the dev
 * database. Override the connection with PORTAGE_TEST_DB_* env vars.
 */

use craft\test\TestSetup;
use simplygoodwork\craftportage\Plugin;

ini_set('date.timezone', 'UTC');

// Buffer the output, so a notice PHP prints (such as the deprecations that old dependencies of the lowest supported
// versions raise on PHP 8.2, which Craft's test setup displays) can't send headers before a functional test starts its
// session. The buffer is printed when the run ends.
ob_start();

define('CRAFT_ROOT_PATH', dirname(__DIR__));
define('CRAFT_TESTS_PATH', __DIR__);
define('CRAFT_STORAGE_PATH', __DIR__ . '/_craft/storage');
define('CRAFT_TEMPLATES_PATH', __DIR__ . '/_craft/templates');
define('CRAFT_CONFIG_PATH', __DIR__ . '/_craft/config');
define('CRAFT_MIGRATIONS_PATH', __DIR__ . '/_craft/migrations');
define('CRAFT_TRANSLATIONS_PATH', __DIR__ . '/_craft/translations');
define('CRAFT_VENDOR_PATH', dirname(__DIR__) . '/vendor');

$env = static fn(string $name, string $default): string => getenv($name) ?: $default;

// Defaults match DDEV: `ddev exec` runs inside the web container, where the db host is `db`.
$dbHost = $env('PORTAGE_TEST_DB_HOST', 'db');
$dbPort = $env('PORTAGE_TEST_DB_PORT', '3306');
$dbName = $env('PORTAGE_TEST_DB_NAME', 'portage_test');
$dbUser = $env('PORTAGE_TEST_DB_USER', 'root');
$dbPassword = $env('PORTAGE_TEST_DB_PASSWORD', 'root');

if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName) || !str_contains($dbName, 'test')) {
    fwrite(STDERR, "Refusing to run tests against database \"$dbName\": its name must contain \"test\".\n");
    exit(1);
}

// Create the test database if it doesn't exist yet.
$pdo = new PDO("mysql:host=$dbHost;port=$dbPort", $dbUser, $dbPassword);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo = null;

foreach ([
    'CRAFT_DB_DSN' => "mysql:host=$dbHost;port=$dbPort;dbname=$dbName",
    'CRAFT_DB_USER' => $dbUser,
    'CRAFT_DB_PASSWORD' => $dbPassword,
    'CRAFT_DB_TABLE_PREFIX' => '',
    'CRAFT_DB_SCHEMA' => '',
] as $name => $value) {
    putenv("$name=$value");
    $_SERVER[$name] = $value;
    $_ENV[$name] = $value;
}

// Composer only lists dependencies in vendor/craftcms/plugins.php, not the root
// package, so register Portage there for Craft to discover it.
$pluginsFile = CRAFT_VENDOR_PATH . '/craftcms/plugins.php';
$plugins = file_exists($pluginsFile) ? require $pluginsFile : [];
$composer = json_decode(file_get_contents(CRAFT_ROOT_PATH . '/composer.json'), true);
$plugins[$composer['name']] = [
    'class' => Plugin::class,
    'basePath' => CRAFT_ROOT_PATH . '/src',
    'handle' => $composer['extra']['handle'],
    'aliases' => ['@simplygoodwork/craftportage' => CRAFT_ROOT_PATH . '/src'],
    'name' => $composer['extra']['name'],
    'version' => '1.0.0',
    'description' => $composer['description'],
    'developer' => $composer['extra']['developer'],
    'documentationUrl' => $composer['extra']['documentationUrl'],
];
if (!is_dir(dirname($pluginsFile))) {
    mkdir(dirname($pluginsFile), 0777, true);
}
file_put_contents($pluginsFile, '<?php' . PHP_EOL . PHP_EOL . 'return ' . var_export($plugins, true) . ';' . PHP_EOL);

TestSetup::configureCraft();
