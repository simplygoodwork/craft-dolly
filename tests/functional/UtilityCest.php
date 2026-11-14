<?php

namespace simplygoodwork\craftportage\tests\functional;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\elements\Asset;
use craft\fields\Assets;
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

    public function thePreviewOffersOnlyTheChoicesThatAreValidAndReplansWhenAChoiceChanges(FunctionalTester $I): void
    {
        [$payload, , $goneUid] = $this->payloadWithAMatchAndAnUnresolvedReference();
        $I->amLoggedInAs(User::find()->admin()->one());
        $I->amOnPage('/admin/utilities/portage');
        $I->submitForm('form', ['payload' => $payload]);

        $I->seeElement("select[name=\"choices[$goneUid][choice]\"] option[value=stub]");
        $I->seeElement("select[name=\"choices[$goneUid][choice]\"] option[value=replace]");
        $I->dontSeeElement("select[name=\"choices[$goneUid][choice]\"] option[value=fetch]");
        $I->dontSeeElement("select[name=\"choices[$goneUid][choice]\"] option[value=upload]");
        $I->dontSeeElement('select[data-portage-mode]');
        $I->see('Unresolved: will be skipped');

        $I->submitForm('#portage-import', ['action' => 'portage/import/preview', 'choices' => [$goneUid => ['choice' => 'stub']]]);

        $I->seeResponseCodeIs(200);
        $I->see('Unresolved: a Stub will be created');
        $I->seeOptionIsSelected("select[name=\"choices[$goneUid][choice]\"]", 'Create a Stub');
        $I->assertNull(Entry::find()->section('topics')->title('Gone')->status(null)->one(), 'Updating the Preview created the Stub');

        $I->submitForm('#portage-import', []);

        $I->seeResponseCodeIs(200);
        $stub = Entry::find()->section('topics')->title('Gone')->status(null)->one();
        $I->assertNotNull($stub);
        $I->assertFalse($stub->enabled);
        $I->see('"Gone" (entry): a Stub was created');
    }

    public function aChoiceThatCantBeCarriedOutShowsThePreviewWithTheDefaultsAndSaysWhy(FunctionalTester $I): void
    {
        [$payload, , $goneUid] = $this->payloadWithAMatchAndAnUnresolvedReference();
        $I->amLoggedInAs(User::find()->admin()->one());
        $I->amOnPage('/admin/utilities/portage');
        $I->submitForm('form', ['payload' => $payload]);

        $I->submitForm('#portage-import', ['action' => 'portage/import/preview', 'choices' => [$goneUid => ['choice' => 'replace', 'replacement' => ['999999']]]]);

        $I->seeResponseCodeIs(200);
        $I->see('The replacement for the Reference "Gone" doesn\'t exist', '#portage-choice-error');
        $I->seeElement('#portage-import');
        $I->see('Unresolved: will be skipped');
    }

    public function aReplacementWithoutAnElementIsSkippedUntilOneIsPicked(FunctionalTester $I): void
    {
        [$payload, , $goneUid] = $this->payloadWithAMatchAndAnUnresolvedReference();
        $I->amLoggedInAs(User::find()->admin()->one());
        $I->amOnPage('/admin/utilities/portage');
        $I->submitForm('form', ['payload' => $payload]);

        $I->submitForm('#portage-import', ['action' => 'portage/import/preview', 'choices' => [$goneUid => ['choice' => 'replace']]]);

        $I->see('Pick a replacement. Until you do, this will be skipped.');
        $I->seeOptionIsSelected("select[name=\"choices[$goneUid][choice]\"]", 'Pick a replacement');
    }

    public function theTargetSectionAndImportModeAreChosenInThePreview(FunctionalTester $I): void
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $blog = Schema::section('blog', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Hello']);
        $payload = Plugin::getInstance()->getPortage()->export([$source]);
        $rootUid = $source->uid;
        $I->amLoggedInAs(User::find()->admin()->one());
        $I->amOnPage('/admin/utilities/portage');
        $I->submitForm('form', ['payload' => $payload]);

        $I->seeElement("select[name=\"rootChoices[$rootUid][section]\"] option[value=\"$blog->uid\"]");
        $I->seeElement("select[name=\"rootChoices[$rootUid][mode]\"] option[value=overwrite]");

        $I->submitForm('#portage-import', ['action' => 'portage/import/preview', 'rootChoices' => [$rootUid => ['mode' => 'overwrite']]]);
        $I->see('A draft will be created on "Hello" with the Payload’s content.');

        $I->submitForm('#portage-import', ['action' => 'portage/import/preview', 'rootChoices' => [$rootUid => ['section' => $blog->uid, 'mode' => 'create']]]);
        $I->see('"Hello" will be created as a disabled entry in the "Blog" section.');
        $I->submitForm('#portage-import', []);

        $I->see('Create new, in the "Blog" section');
        $I->assertNotNull(Entry::find()->section('blog')->title('Hello')->status(null)->one());
    }

    public function anOverwriteImportedFromThePreviewCreatesADraftOnTheMatchedEntry(FunctionalTester $I): void
    {
        $article = Schema::entryType('article', [Schema::plainText('summary')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Staging title', 'summary' => 'Staging summary']);
        $payload = Plugin::getInstance()->getPortage()->export([$source]);
        Schema::updateEntry($source, ['title' => 'Prod title', 'summary' => 'Prod summary']);
        $I->amLoggedInAs(User::find()->admin()->one());
        $I->amOnPage('/admin/utilities/portage');
        $I->submitForm('form', ['payload' => $payload]);

        $I->submitForm('#portage-import', ['action' => 'portage/import/preview', 'rootChoices' => [$source->uid => ['mode' => 'overwrite']]]);
        $I->see('A draft will be created on "Prod title" with the Payload’s content.');
        $I->submitForm('#portage-import', []);

        $I->see('Created a draft on');
        $I->see('Overwrite, in the "News" section');
        $draft = Entry::find()->draftOf($source)->status(null)->one();
        $I->assertNotNull($draft, 'No draft on the matched entry');
        $I->assertSame(['Staging title', 'Staging summary'], [$draft->title, $draft->getFieldValue('summary')]);
        $I->assertSame('Prod title', Entry::find()->id($source->id)->status(null)->one()->title);
    }

    public function anUploadedFileForAnUnresolvedAssetIsStagedPreviewedAndImportedIntoTheChosenVolumeAndFolder(FunctionalTester $I): void
    {
        $photos = Schema::volume('photos');
        $archive = Schema::volume('archive');
        $photo = Schema::asset($photos, 'photo.png', 'blog', ['title' => 'Red square', 'alt' => 'A red square']);
        $assetUid = $photo->uid;
        $article = Schema::entryType('article', [Schema::field(Assets::class, 'images')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Pictured', 'images' => [$photo->id]]);
        $payload = Plugin::getInstance()->getPortage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($photo);
        $admin = User::find()->admin()->one();
        $staging = Craft::$app->getPath()->getTempPath() . "/portage-uploads/$admin->id";
        $I->amLoggedInAs($admin);
        $I->amOnPage('/admin/utilities/portage');
        $I->submitForm('form', ['payload' => $payload]);

        UploadedFile::reset();
        $I->attachFile("input[name=\"uploads[$assetUid]\"]", 'files/photo.png');
        $I->submitForm('#portage-import', [
            'action' => 'portage/import/preview',
            'choices' => [$assetUid => ['choice' => 'upload', 'volume' => $archive->uid, 'folder' => 'uploads/2026']],
        ]);

        $I->see('Unresolved: the uploaded file will be saved into the folder "uploads/2026/" of the "archive" volume');
        $I->see('Using the uploaded file photo.png.');
        $I->assertCount(1, glob("$staging/*"), 'The upload wasn\'t staged');
        $I->assertNull(Asset::find()->uid($assetUid)->one(), 'The Preview created the asset');

        UploadedFile::reset();
        $I->submitForm('#portage-import', []);

        $created = Asset::find()->uid($assetUid)->one();
        $I->assertNotNull($created, 'No asset with the source UID');
        $I->assertSame(['archive', 'uploads/2026/', 'photo.png'], [$created->getVolume()->handle, $created->getFolder()->path, $created->getFilename()]);
        $I->assertSame(['Red square', 'A red square'], [$created->title, $created->alt]);
        $I->assertSame([], glob("$staging/*"), 'The staged file was left behind');
        $I->see('Created the asset "Red square" from an uploaded file.');
        $I->see('"Red square" (asset): created from an uploaded file');
        $I->see('Saved in the folder "uploads/2026/" of the "archive" volume.');
    }

    public function anImportWithChoicesThePreviewDidNotShowImportsNothingAndShowsThePreviewAgain(FunctionalTester $I): void
    {
        [$payload, , $goneUid] = $this->payloadWithAMatchAndAnUnresolvedReference();
        $I->amLoggedInAs(User::find()->admin()->one());
        $I->amOnPage('/admin/utilities/portage');
        $I->submitForm('form', ['payload' => $payload]);

        $I->submitForm('#portage-import', ['choices' => [$goneUid => ['choice' => 'stub']]]);

        $I->seeResponseCodeIs(200);
        $I->see('Your choices changed since the Preview. Check it, then import.', '#portage-choice-error');
        $I->see('Unresolved: a Stub will be created');
        $I->assertNull(Entry::find()->section('news')->title('Related')->status(null)->one(), 'The Import ran');
        $I->assertNull(Entry::find()->section('topics')->title('Gone')->status(null)->one(), 'The Stub was created');

        $I->submitForm('#portage-import', []);

        $I->assertNotNull(Entry::find()->section('topics')->title('Gone')->status(null)->one());
    }

    public function oneChoiceThatCantBeCarriedOutDoesNotDiscardTheOthers(FunctionalTester $I): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $first = Schema::entry($topics, $topic, ['title' => 'First gone']);
        $second = Schema::entry($topics, $topic, ['title' => 'Second gone']);
        $article = Schema::entryType('article', [Schema::field(Entries::class, 'relatedTopics')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Related', 'relatedTopics' => [$first->id, $second->id]]);
        $payload = Plugin::getInstance()->getPortage()->export([$source]);
        [$firstUid, $secondUid] = [$first->uid, $second->uid];
        Schema::deleteElement($first);
        Schema::deleteElement($second);
        $I->amLoggedInAs(User::find()->admin()->one());
        $I->amOnPage('/admin/utilities/portage');
        $I->submitForm('form', ['payload' => $payload]);

        $I->submitForm('#portage-import', ['action' => 'portage/import/preview', 'choices' => [
            $firstUid => ['choice' => 'replace', 'replacement' => ['999999']],
            $secondUid => ['choice' => 'stub'],
        ]]);

        $I->see('The replacement for the Reference "First gone" doesn\'t exist', '#portage-choice-error');
        $I->seeOptionIsSelected("select[name=\"choices[$secondUid][choice]\"]", 'Create a Stub');
        $I->seeOptionIsSelected("select[name=\"choices[$firstUid][choice]\"]", 'Skip it');
    }

    public function aLargeImportIsQueuedAndItsLogEntryShowsTheProgressAndTheResult(FunctionalTester $I): void
    {
        $type = Schema::entryType('article');
        $section = Schema::section('news', [$type]);
        $entries = array_map(fn(int $i) => Schema::entry($section, $type, ['title' => "Entry $i"]), range(1, 11));
        $payload = Plugin::getInstance()->getPortage()->export($entries);
        foreach ($entries as $entry) {
            Schema::deleteElement($entry);
        }
        $I->amLoggedInAs(User::find()->admin()->one());
        $I->amOnPage('/admin/utilities/portage');
        $I->submitForm('form', ['payload' => $payload]);

        $I->submitForm('#portage-import', []);

        $I->seeResponseCodeIs(200);
        $I->seeElement('#portage-log-status[data-status=queued]');
        $I->see('Queued: waiting for the queue to start it.');
        $I->see('Not imported yet');
        $I->assertSame(0, (int)Entry::find()->section('news')->status(null)->count());

        Craft::$app->getQueue()->run();
        $I->amOnPage('/admin/utilities/portage');
        $I->see('Completed', '#portage-log');
        $I->seeElement('#portage-log a[href*="portage/log/"]');
        $I->assertSame(11, (int)Entry::find()->section('news')->status(null)->count());
    }

    public function theChoicesOfThePreviewAreCarriedThroughTheFormIntoTheQueuedImport(FunctionalTester $I): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone']);
        $goneUid = $gone->uid;
        $article = Schema::entryType('article', [Schema::field(Entries::class, 'relatedTopics')]);
        $news = Schema::section('news', [$article]);
        $entries = [Schema::entry($news, $article, ['title' => 'Related', 'relatedTopics' => [$gone->id]])];
        foreach (range(2, 11) as $i) {
            $entries[] = Schema::entry($news, $article, ['title' => "Plain $i"]);
        }
        $payload = Plugin::getInstance()->getPortage()->export($entries);
        foreach ([...$entries, $gone] as $element) {
            Schema::deleteElement($element);
        }
        $I->amLoggedInAs(User::find()->admin()->one());
        $I->amOnPage('/admin/utilities/portage');
        $I->submitForm('form', ['payload' => $payload]);

        // As the Preview signs it after the user chose to Stub.
        $previewed = Plugin::getInstance()->getPortage()->preview($payload, [$goneUid => 'stub'])->fingerprint();
        $I->submitForm('#portage-import', ['choices' => [$goneUid => ['choice' => 'stub']], 'previewed' => $previewed]);

        $I->seeElement('#portage-log-status[data-status=queued]');
        $I->see('"Gone" (entry): a Stub was created');
        Craft::$app->getQueue()->run();
        $stub = Entry::find()->uid($goneUid)->status(null)->one();
        $I->assertNotNull($stub, 'The Stub choice didn\'t reach the job');
        $I->assertFalse($stub->enabled);
        $related = Entry::find()->section('news')->title('Related')->status(null)->one();
        $I->assertSame([$stub->id], $related->getFieldValue('relatedTopics')->status(null)->ids());
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
