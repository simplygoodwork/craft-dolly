<?php

namespace simplygoodwork\craftportage\tests\functional;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\Site;
use FunctionalTester;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\tests\support\Schema;

/**
 * Light coverage of the control panel's "Copy for another environment" actions: what a Payload contains is covered
 * through the core service.
 */
class ExportCest
{
    private const LABEL = 'Copy for another environment';

    public function theEditorActionExportsTheDraftBeingViewed(FunctionalTester $I): void
    {
        [$entry, $draft] = $this->entryWithADraft();
        $I->amLoggedInAs(User::find()->admin()->one());

        $response = $this->post($I, 'entry', ['elementId' => $draft->id, 'siteId' => $entry->siteId]);

        $I->assertFalse($response['asFile']);
        $I->assertSame(1, $response['count']);
        $I->assertSame('hello.portage', $response['filename']);
        $imported = $this->importedTitles($I, $response['payload']);
        $I->assertSame(['Draft title'], $imported);
    }

    public function theBulkActionExportsSeveralEntriesIntoOnePayload(FunctionalTester $I): void
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $first = Schema::entry($news, $article, ['title' => 'First']);
        $second = Schema::entry($news, $article, ['title' => 'Second']);
        $I->amLoggedInAs(User::find()->admin()->one());

        $response = $this->post($I, 'entries', ['ids' => [$second->id, $first->id], 'siteId' => $first->siteId]);

        $I->assertSame(2, $response['count']);
        $I->assertSame('entries.portage', $response['filename']);
        $I->assertSame(['Second', 'First'], $this->importedTitles($I, $response['payload']));
    }

    public function aLargePayloadIsOfferedAsAFile(FunctionalTester $I): void
    {
        $entry = $this->entryWithALargePayload();
        $I->amLoggedInAs(User::find()->admin()->one());

        $response = $this->post($I, 'entries', ['ids' => [$entry->id], 'siteId' => $entry->siteId]);

        $I->assertTrue($response['asFile']);
        $I->assertStringStartsWith('portage:v1:', $response['payload']);
        $this->importedTitles($I, $response['payload']);
    }

    public function theUtilityAcceptsTheDownloadedFile(FunctionalTester $I): void
    {
        $entry = $this->entryWithALargePayload();
        $I->amLoggedInAs(User::find()->admin()->one());
        $response = $this->post($I, 'entries', ['ids' => [$entry->id], 'siteId' => $entry->siteId]);
        $I->assertTrue($response['asFile']);

        $file = 'download-' . uniqid() . '.portage';
        file_put_contents(codecept_data_dir($file), $response['payload'] . PHP_EOL);
        try {
            \yii\web\UploadedFile::reset();
            $I->amOnPage('/admin/utilities/portage');
            $I->attachFile('input[name="payloadFile"]', $file);
            $I->submitForm('form', []);
        } finally {
            unlink(codecept_data_dir($file));
        }

        $I->seeResponseCodeIs(200);
        $I->see('Nothing has been imported yet.');
        $I->see('"Big"');
    }

    public function theActionsAreHiddenWithoutTheExportPermission(FunctionalTester $I): void
    {
        [$entry] = $this->entryWithADraft();
        $editor = Schema::user('editor');
        Schema::grantPermissions($editor, ['accessCp', ...$this->entryPermissions($entry)]);
        $I->amLoggedInAs($editor);

        $I->amOnPage("/admin/entries/news/$entry->id");
        $I->seeResponseCodeIs(200);
        $I->dontSee(self::LABEL);
        $I->assertNotContains(\simplygoodwork\craftportage\elements\CopyForAnotherEnvironment::class, Entry::actions('*'));

        $this->postForbidden($I, $entry, $I->grabAttributeFrom('input[name="CRAFT_CSRF_TOKEN"]', 'value'));
    }

    public function theActionsAreOfferedWithTheExportPermission(FunctionalTester $I): void
    {
        [$entry] = $this->entryWithADraft();
        $editor = Schema::user('editor');
        Schema::grantPermissions($editor, ['accessCp', ...$this->entryPermissions($entry), 'portage-exportContent']);
        $I->amLoggedInAs($editor);

        $I->amOnPage("/admin/entries/news/$entry->id");

        $I->seeResponseCodeIs(200);
        $I->see(self::LABEL);
        $I->assertContains(\simplygoodwork\craftportage\elements\CopyForAnotherEnvironment::class, Entry::actions('*'));
    }

    public function aUserWhoCannotSeeAnEntryCannotExportIt(FunctionalTester $I): void
    {
        [$entry] = $this->entryWithADraft();
        $editor = Schema::user('editor');
        Schema::grantPermissions($editor, ['accessCp', 'portage-exportContent']);
        $I->amLoggedInAs($editor);

        $this->postForbidden($I, $entry);
    }

    /**
     * An entry whose Payload is over the clipboard limit: random text doesn't compress.
     */
    private function entryWithALargePayload(): Entry
    {
        $article = Schema::entryType('article', [Schema::plainText('body')]);
        $news = Schema::section('news', [$article]);

        return Schema::entry($news, $article, ['title' => 'Big', 'body' => StringHelper::randomString(150000)]);
    }

    /**
     * @return array{Entry, Entry} an entry and its draft
     */
    private function entryWithADraft(): array
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $entry = Schema::entry($news, $article, ['title' => 'Hello', 'slug' => 'hello']);

        return [$entry, Schema::draft($entry, ['title' => 'Draft title'])];
    }

    public function onlyTheSitesTheUserCanEditAreExported(FunctionalTester $I): void
    {
        $german = Schema::site('german', ['language' => 'de']);
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $entry = Schema::entry($news, $article, ['title' => 'Hello']);
        Schema::translate($entry, $german, ['title' => 'Hallo']);
        $primary = Craft::$app->getSites()->getPrimarySite();
        $editor = Schema::user('editor');
        Schema::grantPermissions($editor, ['accessCp', 'portage-exportContent', "editSite:$primary->uid", ...$this->entryPermissions($entry)]);
        $I->amLoggedInAs($editor);

        $editorsPayload = $this->post($I, 'entries', ['ids' => [$entry->id], 'siteId' => $entry->siteId])['payload'];
        $I->amLoggedInAs(User::find()->admin()->one());
        $adminsPayload = Plugin::getInstance()->getPortage()->export([$entry]);

        // The new entry is propagated to the German site either way: its title there is the Payload's, or a copy of the English one.
        $I->assertSame('Hello', $this->germanTitleAfterImporting($editorsPayload, $entry, $german), 'The Payload has a site the user can\'t edit');
        $I->assertSame('Hallo', $this->germanTitleAfterImporting($adminsPayload, $entry, $german), 'Without a limit, every site is exported');
    }

    public function anotherUsersProvisionalDraftCannotBeExported(FunctionalTester $I): void
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $entry = Schema::entry($news, $article, ['title' => 'Hello']);
        $draft = Schema::draft($entry, ['title' => 'Their unsaved changes'], provisional: true);
        $uid = $news->uid;
        $editor = Schema::user('editor');
        Schema::grantPermissions($editor, ['accessCp', 'portage-exportContent', ...$this->entryPermissions($entry), "viewPeerEntryDrafts:$uid", "savePeerEntryDrafts:$uid"]);
        $I->amLoggedInAs($editor);
        $I->amOnPage("/admin/entries/news/$entry->id");
        $token = $I->grabAttributeFrom('input[name="CRAFT_CSRF_TOKEN"]', 'value');

        $I->haveHttpHeader('X-CSRF-Token', $token);
        $I->haveHttpHeader('Accept', 'application/json');
        $I->sendAjaxPostRequest('/admin/actions/portage/export/entry', ['elementId' => $draft->id, 'siteId' => $entry->siteId]);

        $I->seeResponseCodeIs(403);
    }

    public function aRequestWithoutACsrfTokenIsRefused(FunctionalTester $I): void
    {
        [$entry] = $this->entryWithADraft();
        $I->amLoggedInAs(User::find()->admin()->one());

        $I->haveHttpHeader('Accept', 'application/json');
        $I->sendAjaxPostRequest('/admin/actions/portage/export/entries', ['ids' => [$entry->id], 'siteId' => $entry->siteId]);

        $I->seeResponseCodeIs(400);
    }

    /**
     * Imports a Payload where its entry no longer exists, and returns the new entry's title in a site.
     */
    private function germanTitleAfterImporting(string $payload, Entry $entry, Site $site): string
    {
        $existing = Entry::find()->id($entry->id)->status(null)->one();
        if ($existing) {
            Schema::deleteElement($existing);
        }
        $result = Plugin::getInstance()->getPortage()->import($payload);

        return (string)Entry::find()->id($result->roots[0]->elementId)->siteId($site->id)->status(null)->one()?->title;
    }

    /**
     * @return list<string> what an editor needs to open an entry in the control panel
     */
    private function entryPermissions(Entry $entry): array
    {
        $uid = $entry->getSection()->uid;

        return ["viewEntries:$uid", "saveEntries:$uid", "viewPeerEntries:$uid", "savePeerEntries:$uid"];
    }

    private function postForbidden(FunctionalTester $I, Entry $entry, ?string $csrfToken = null): void
    {
        $I->haveHttpHeader('X-CSRF-Token', $csrfToken ?? $this->csrfToken($I));
        $I->haveHttpHeader('Accept', 'application/json');
        $I->sendAjaxPostRequest('/admin/actions/portage/export/entries', ['ids' => [$entry->id], 'siteId' => $entry->siteId]);
        $I->seeResponseCodeIs(403);
    }

    /**
     * Craft's own pages carry the token its JavaScript sends with every action request.
     */
    private function csrfToken(FunctionalTester $I): string
    {
        $I->amOnPage('/admin/myaccount');

        return $I->grabAttributeFrom('input[name="CRAFT_CSRF_TOKEN"]', 'value');
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function post(FunctionalTester $I, string $action, array $params): array
    {
        $I->haveHttpHeader('X-CSRF-Token', $this->csrfToken($I));
        $I->haveHttpHeader('Accept', 'application/json');
        $I->sendAjaxPostRequest("/admin/actions/portage/export/$action", $params);
        $I->seeResponseCodeIs(200);

        return Json::decode($I->grabPageSource());
    }

    /**
     * Previews the Payload as Import would and returns the titles of its Root elements.
     *
     * @return list<string>
     */
    private function importedTitles(FunctionalTester $I, string $payload): array
    {
        $result = Plugin::getInstance()->getPortage()->import($payload);
        $I->assertFalse($result->isRefused());

        return array_map(fn($root) => (string)$root->title, $result->roots);
    }
}
