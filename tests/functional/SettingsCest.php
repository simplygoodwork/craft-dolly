<?php

namespace simplygoodwork\craftportage\tests\functional;

use craft\elements\User;
use FunctionalTester;

/**
 * Light coverage of Portage's settings page: the Shared key's behaviour is covered through the core service.
 */
class SettingsCest
{
    public function theSettingsPageOffersTheSharedKeyAsAnEnvVar(FunctionalTester $I): void
    {
        $I->amLoggedInAs(User::find()->admin()->one());

        $I->amOnPage('/admin/settings/plugins/portage');

        $I->seeResponseCodeIs(200);
        $I->see('Shared key');
        // The autosuggest input itself is rendered by Vue in the browser.
        $I->seeElement('#settings-sharedKey-field');
    }
}
