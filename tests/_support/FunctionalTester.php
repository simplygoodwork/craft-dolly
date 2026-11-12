<?php

declare(strict_types=1);

/**
 * Drives the control panel through Craft's test module: `amLoggedInAs()`, `amOnPage()`, `submitForm()`, `see()`.
 *
 * @SuppressWarnings(PHPMD)
 */
class FunctionalTester extends \Codeception\Actor
{
    use _generated\FunctionalTesterActions;
}
