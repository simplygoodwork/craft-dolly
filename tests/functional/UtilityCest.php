<?php

namespace simplygoodwork\craftportage\tests\functional;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Entries;
use FunctionalTester;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\tests\support\Schema;
use yii\web\UploadedFile;

/**
 * Light coverage of the Portage utility in the control panel: the behaviour itself is covered through the core service.
 */
class UtilityCest
{
    public function aUserWithImportContentSeesTheImportForm(FunctionalTester $I): void
    {
        $editor = Schema::user('editor');
        Schema::grantPermissions($editor, ['accessCp', 'utility:portage', 'portage-importContent']);
        $I->amLoggedInAs($editor);

        $I->amOnPage('/admin/utilities/portage');

        $I->seeResponseCodeIs(200);
        $I->see('Paste a Payload');
        $I->seeElement('textarea[name="payload"]');
        $I->seeElement('input[type="file"][name="payloadFile"]');
    }

    public function bothPermissionsAppearInTheUserPermissionsUi(FunctionalTester $I): void
    {
        $editor = Schema::user('editor');
        $I->amLoggedInAs(User::find()->admin()->one());

        $I->amOnPage("/admin/users/$editor->id/permissions");

        $I->see('Portage');
        $I->seeElement('input[type="checkbox"][value="portage-exportContent"]');
        $I->seeElement('input[type="checkbox"][value="portage-importContent"]');
        $I->see('Export content');
        $I->see('Import content');
    }

    public function pastedAndUploadedPayloadsGiveTheSamePreview(FunctionalTester $I): void
    {
        [$payload, $keptUid, $goneUid] = $this->payloadWithAMatchAndAnUnresolvedReference();
        $I->amLoggedInAs(User::find()->admin()->one());

        $I->amOnPage('/admin/utilities/portage');
        $I->submitForm('form', ['payload' => $payload]);
        $I->seeResponseCodeIs(200);
        $pasted = $I->grabTextFrom('#portage-preview');

        $file = 'preview-' . uniqid() . '.portage';
        file_put_contents(codecept_data_dir($file), $payload);
        try {
            // Yii caches uploaded files per PHP process; in a test the pasted request's empty cache would hide the file.
            UploadedFile::reset();
            $I->amOnPage('/admin/utilities/portage');
            $I->attachFile('input[name="payloadFile"]', $file);
            $I->submitForm('form', []);
        } finally {
            unlink(codecept_data_dir($file));
        }
        $I->seeResponseCodeIs(200);
        $I->assertSame($pasted, $I->grabTextFrom('#portage-preview'));

        $I->see('Nothing has been imported yet.');
        $I->see('The field "Summary" has different settings in this environment than where it was exported. Its values will be imported, but check them.');
        $I->see('Drifted');
        $I->see('"Kept"');
        $I->see('Matched by UID');
        $I->see('"Gone"');
        $I->see('Unresolved: will be skipped');
        $I->see('"Related" will be created as a disabled entry in the "News" section.');
        $I->see('Skipped in "Related Topics": "Gone"');
        $I->see('View as JSON');
        $I->see($keptUid, '#portage-json');
        $I->see($goneUid, '#portage-json');
    }

    public function importingWithDefaultsCreatesDisabledEntriesAndShowsTheResultWithLinks(FunctionalTester $I): void
    {
        [$payload] = $this->payloadWithAMatchAndAnUnresolvedReference();
        $I->amLoggedInAs(User::find()->admin()->one());
        $I->amOnPage('/admin/utilities/portage');
        $I->submitForm('form', ['payload' => $payload]);

        $I->submitForm('#portage-import', []);

        $imported = Entry::find()->section('news')->title('Related')->status(null)->one();
        $I->assertNotNull($imported);
        $I->assertFalse($imported->enabled);
        $I->seeResponseCodeIs(200);
        $I->see('Created a disabled entry');
        $I->seeLink('Related', $imported->getCpEditUrl());
        $I->see('Skipped in "Related Topics": "Gone"');
    }

    public function theImportLogListsImportsAndShowsWhoWhenWhereChoicesAndResults(FunctionalTester $I): void
    {
        [$payload, , $goneUid] = $this->payloadWithAMatchAndAnUnresolvedReference();
        $jane = Schema::user('jane', ['firstName' => 'Jane', 'lastName' => 'Doe', 'admin' => true]);
        Craft::$app->getUser()->setIdentity($jane);
        $result = Plugin::getInstance()->getPortage()->import($payload, [$goneUid => 'stub']);
        Craft::$app->getUser()->setIdentity(null);
        Schema::setImportDate((int)$result->logId, '2026-03-04 05:06:00');
        $I->amLoggedInAs($jane);

        $I->amOnPage('/admin/utilities/portage');
        $I->see('Import log');
        $I->see('Jane Doe', '#portage-log');
        $I->see('https://test.craftcms.test/ (test)', '#portage-log');
        $I->see('1 created', '#portage-log');
        $I->see('1 Stubbed', '#portage-log');
        $I->seeElement("#portage-log a[href*=\"portage/log/$result->logId\"]");
        $I->amOnPage("/admin/portage/log/$result->logId");

        $I->seeResponseCodeIs(200);
        $I->see("Import #$result->logId");
        $I->see('Jane Doe');
        $I->see('3/4/2026');
        $I->see('https://test.craftcms.test/ (test)');
        $I->see('"Gone" (entry): a Stub was created');
        $I->see('Created a disabled entry');
        $I->see('Create new, in the "News" section');
        $I->see('Created a disabled Stub for "Gone".');
        $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $I->seeLink('Related', $imported->getCpEditUrl());
    }

    public function aRefusedPayloadShowsWhy(FunctionalTester $I): void
    {
        $I->amLoggedInAs(User::find()->admin()->one());

        $I->amOnPage('/admin/utilities/portage');
        $I->submitForm('form', ['payload' => 'not a payload']);

        $I->see('This is not a Portage Payload: it should start with "portage:v1:".');
        $I->dontSeeElement('#portage-import');
    }

    /**
     * An entry relating to one entry that still exists and one that's gone, with a field that drifted since Export.
     *
     * @return array{string, string, string} the Payload and the UIDs of the kept and gone entries
     */
    private function payloadWithAMatchAndAnUnresolvedReference(): array
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $kept = Schema::entry($topics, $topic, ['title' => 'Kept']);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone']);
        $summary = Schema::plainText('summary');
        $article = Schema::entryType('article', [$summary, Schema::field(Entries::class, 'relatedTopics')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Related', 'summary' => 'Hello', 'relatedTopics' => [$kept->id, $gone->id]]);
        $payload = Plugin::getInstance()->getPortage()->export([$source]);
        $goneUid = $gone->uid;
        Schema::deleteElement($source);
        Schema::deleteElement($gone);
        Schema::updateField($summary->uid, ['charLimit' => 42]);

        return [$payload, $kept->uid, $goneUid];
    }

    public function aUserWithoutImportContentCannotImport(FunctionalTester $I): void
    {
        $editor = Schema::user('editor');
        Schema::grantPermissions($editor, ['accessCp', 'utility:portage']);
        $I->amLoggedInAs($editor);

        $I->amOnPage('/admin/utilities/portage');
        $I->see('You don\'t have permission to import content.');
        $I->dontSeeElement('textarea[name="payload"]');

        $I->amOnPage('/admin/actions/portage/import/preview');
        $I->seeResponseCodeIs(403);
    }
}
