<?php

namespace simplygoodwork\craftportage\tests\support;

use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use PHPUnit\Framework\Assert;

/**
 * Skips a test whose Craft or plugin feature doesn't exist in the installed versions, so the suite passes on every
 * version the CI matrix runs (see `.github/workflows/ci.yml`). Call it first in a test, or in `_before()` to skip a
 * whole class. Works in unit tests and Cests.
 *
 * Prefer `classes()` where a feature is a class (a field type); use `craft()` or `package()` for behaviour that
 * starts at a version.
 */
final class Requires
{
    /**
     * Skips the test unless every class exists, e.g. a field type added after Craft 5.0.
     */
    public static function classes(string $feature, string ...$classes): void
    {
        foreach ($classes as $class) {
            if (!class_exists($class)) {
                Assert::markTestSkipped("$feature needs $class, which isn't in the installed version.");
            }
        }
    }

    /**
     * Skips the test unless Craft is at least $version, e.g. `Requires::craft('5.7.0', 'Block copy/paste')`.
     */
    public static function craft(string $version, string $feature): void
    {
        self::package('craftcms/cms', ">=$version", $feature);
    }

    /**
     * Skips the test unless the Composer package is installed at a version that satisfies $constraint,
     * e.g. `Requires::package('verbb/hyper', '^3.0', 'Shared link type configs')`.
     */
    public static function package(string $package, string $constraint, string $feature): void
    {
        if (!InstalledVersions::isInstalled($package)) {
            Assert::markTestSkipped("$feature needs $package $constraint, which isn't installed.");
        }
        if (!InstalledVersions::satisfies(new VersionParser(), $package, $constraint)) {
            $installed = InstalledVersions::getPrettyVersion($package);
            Assert::markTestSkipped("$feature needs $package $constraint. This is $installed.");
        }
    }
}
