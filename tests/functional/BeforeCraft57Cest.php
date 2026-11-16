<?php

namespace simplygoodwork\craftportage\tests\functional;

use craft\elements\User;
use FunctionalTester;
use simplygoodwork\craftportage\tests\support\Requires;
use simplygoodwork\craftportage\tests\support\Schema;

/**
 * Craft before 5.7 has no field action menus, so Matrix and Neo blocks can't be copied or pasted there, while whole
 * entries can. `PasteCest` covers the block menus from Craft 5.7.
 */
class BeforeCraft57Cest
{
    public function _before(FunctionalTester $I): void
    {
        Requires::package('craftcms/cms', '<5.7.0', 'Craft without field action menus');
    }

    public function theEntryEditorOffersCopyingTheEntryButNotItsBlocks(FunctionalTester $I): void
    {
        $text = Schema::blockType('text', [Schema::plainText('body')]);
        $page = Schema::entryType('page', [Schema::matrix('blocks', [$text])]);
        $pages = Schema::section('pages', [$page]);
        $entry = Schema::entry($pages, $page, ['title' => 'Staging', 'blocks' => [
            'new1' => ['type' => 'text', 'fields' => ['body' => 'First']],
        ]]);
        $I->amLoggedInAs(User::find()->admin()->one());

        $I->amOnPage("/admin/entries/pages/$entry->id");

        $I->seeResponseCodeIs(200);
        $I->see('Copy for another environment');
        $I->dontSee('Copy selected for another environment');
        $I->dontSee('Paste from another environment');
    }
}
