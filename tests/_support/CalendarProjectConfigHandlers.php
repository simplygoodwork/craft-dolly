<?php

namespace simplygoodwork\craftdolly\tests\support;

use craft\web\Application;
use ReflectionMethod;
use Solspace\Calendar\Bundles\ProjectConfig\CalendarProjectConfig;
use Solspace\Calendar\Calendar;
use yii\base\BootstrapInterface;

/**
 * Registers Solspace Calendar's project config handlers on every test app.
 *
 * Calendar registers them once per PHP process, on the first app only, but every test (and every suite's install)
 * gets a new app. Without them, saving a calendar, or installing Calendar in a later suite, never writes the calendar
 * to the database, and Calendar's own handler then fails whenever a site is saved.
 */
final class CalendarProjectConfigHandlers implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        if (!class_exists(Calendar::class)) {
            return;
        }

        // Before plugins load: set only once Calendar has registered its handlers on an earlier app.
        $registered = (new ReflectionMethod(Calendar::class, 'initBundles'))->getStaticVariables()['initialized'] ?? null;
        if ($registered) {
            // Craft triggers the web application's init event on console applications too.
            $app->on(Application::EVENT_INIT, fn() => new CalendarProjectConfig());
        }
    }
}
