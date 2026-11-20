<?php

namespace simplygoodwork\craftdolly\tests\functional;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Json;
use craft\models\EntryType;
use craft\models\Section;
use FunctionalTester;
use simplygoodwork\craftdolly\import\BlockTarget;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\tests\support\Requires;
use simplygoodwork\craftdolly\tests\support\Schema;

/**
 * Light coverage of the control panel's copy and paste of blocks in Matrix and Neo fields: what a Payload of blocks
 * holds and what a paste does are covered through the core service.
 */
class PasteCest
{
    public function _before(FunctionalTester $I): void
    {
        Requires::craft('5.7.0', 'Block copy/paste');
    }

    public function copyingBlocksExportsThemByTheirPositionInTheEntrysDraft(FunctionalTester $I): void
    {
        [$entry, $section] = $this->sourceWithBlocks();
        $draft = Schema::draft($entry, [], provisional: true);
        $I->amLoggedInAs(User::find()->admin()->one());

        $response = $this->post($I, 'export/blocks', [
            'elementId' => $draft->id,
            'siteId' => $entry->siteId,
            'field' => 'blocks',
            'positions' => [2, 0],
        ]);

        $I->assertSame(2, $response['count']);
        $I->assertSame('blocks', $response['kind']);
        $I->assertSame('blocks.dolly', $response['filename']);
        $I->assertFalse($response['asFile']);
        // The blocks are exported in the order they have in the field.
        $plan = Plugin::getInstance()->getDolly()->preview($response['payload'], target: $this->target($this->emptyPage($section), 'blocks'));
        $I->assertSame(['"Text"', '"Text"'], array_map(fn($root) => '"' . $root->title . '"', $plan->roots));
        $bodies = array_map(fn(Entry $block) => $block->getFieldValue('body'), $this->pastedBlocks($I, $response['payload'], $section));
        $I->assertSame(['First', 'Third'], $bodies);
    }

    public function onlyBlocksOfAMatrixOrNeoFieldCanBeCopied(FunctionalTester $I): void
    {
        [$entry] = $this->sourceWithBlocks();
        $I->amLoggedInAs(User::find()->admin()->one());

        $response = $this->post($I, 'export/blocks', ['elementId' => $entry->id, 'siteId' => $entry->siteId, 'field' => 'summary', 'positions' => [0]], 400);

        $I->assertSame('Only blocks in a Matrix or Neo field can be copied.', $response['message']);
    }

    public function blocksAtPositionsThatDontExistCantBeCopied(FunctionalTester $I): void
    {
        [$entry] = $this->sourceWithBlocks();
        $I->amLoggedInAs(User::find()->admin()->one());

        $response = $this->post($I, 'export/blocks', ['elementId' => $entry->id, 'siteId' => $entry->siteId, 'field' => 'blocks', 'positions' => [9]], 400);

        $I->assertSame('Some of the entries couldn’t be found. Reload the page and try again.', $response['message']);
    }

    public function blocksTheDraftNoLongerHasAtThosePositionsAreRefused(FunctionalTester $I): void
    {
        [$entry] = $this->sourceWithBlocks();
        $blocks = $entry->getFieldValue('blocks')->status(null)->all();
        $I->amLoggedInAs(User::find()->admin()->one());

        $response = $this->post($I, 'export/blocks', [
            'elementId' => $entry->id,
            'siteId' => $entry->siteId,
            'field' => 'blocks',
            'positions' => [0],
            'ids' => [$blocks[1]->id],
        ], 400);

        $I->assertSame('The blocks changed since the page loaded. Reload the page and try again.', $response['message']);
    }

    public function copyingBlocksNeedsTheExportPermission(FunctionalTester $I): void
    {
        [$entry] = $this->sourceWithBlocks();
        $editor = Schema::user('editor');
        Schema::grantPermissions($editor, ['accessCp', ...$this->entryPermissions($entry)]);
        $I->amLoggedInAs($editor);

        $this->post($I, 'export/blocks', ['elementId' => $entry->id, 'siteId' => $entry->siteId, 'field' => 'blocks', 'positions' => [0]], 403);
    }

    public function theFieldMenuOffersNeitherActionWithoutThePermissions(FunctionalTester $I): void
    {
        $this->openSourceAs($I, []);

        $I->dontSee('Copy selected for another environment');
        $I->dontSee('Paste from another environment');
    }

    public function theFieldMenuOffersCopyWithTheExportPermission(FunctionalTester $I): void
    {
        $this->openSourceAs($I, ['dolly-exportContent']);

        $I->see('Copy selected for another environment');
        $I->dontSee('Paste from another environment');
    }

    public function theFieldMenuOffersPasteWithTheImportPermission(FunctionalTester $I): void
    {
        $this->openSourceAs($I, ['dolly-importContent']);

        $I->dontSee('Copy selected for another environment');
        $I->see('Paste from another environment');
    }

    /**
     * @param list<string> $permissions
     */
    private function openSourceAs(FunctionalTester $I, array $permissions): void
    {
        [$entry] = $this->sourceWithBlocks();
        $editor = Schema::user('editor');
        Schema::grantPermissions($editor, ['accessCp', ...$permissions, ...$this->entryPermissions($entry)]);
        $I->amLoggedInAs($editor);

        $I->amOnPage("/admin/entries/pages/$entry->id");
        $I->seeResponseCodeIs(200);
    }

    public function previewingAPayloadRendersThePreviewWithTheUtilitysChoices(FunctionalTester $I): void
    {
        [$payload, $draft] = $this->payloadAndTargetDraft($I);
        $I->amLoggedInAs(User::find()->admin()->one());

        $response = $this->post($I, 'paste/preview', $this->pasteParams($draft, $payload));

        $I->assertStringContainsString('&quot;Text&quot; will be added to the field &quot;Blocks&quot;.', $response['html']);
        $I->assertStringContainsString('Nothing has been pasted yet.', $response['html']);
        $I->assertStringContainsString('name="previewed"', $response['html']);
        $I->assertStringContainsString('Paste</button>', $response['html']);
    }

    public function aPayloadThatCantBePastedIsShownAsARefusal(FunctionalTester $I): void
    {
        [$payload, $draft] = $this->payloadAndTargetDraft($I);
        $entriesPayload = Plugin::getInstance()->getDolly()->export([Entry::find()->id($draft->getCanonicalId())->one()]);
        $I->amLoggedInAs(User::find()->admin()->one());

        $response = $this->post($I, 'paste/preview', $this->pasteParams($draft, $entriesPayload));

        $I->assertStringContainsString('This Payload holds whole entries, not blocks. Import it in the Dolly utility.', $response['html']);
        $I->assertStringNotContainsString('Paste</button>', $response['html']);
    }

    public function pastingAddsTheBlocksToTheDraftAndTheLiveEntryIsUntouched(FunctionalTester $I): void
    {
        [$payload, $draft, $target] = $this->payloadAndTargetDraft($I);
        $I->amLoggedInAs(User::find()->admin()->one());
        $preview = $this->previewedToken($I, $draft, $payload);

        $response = $this->post($I, 'paste/paste', $this->pasteParams($draft, $payload) + ['previewed' => $preview]);

        $I->assertSame('Pasted 1 block into the field "Blocks".', $response['message']);
        $I->assertSame(1, $response['pasted']);
        $blocks = Entry::find()->id($draft->id)->drafts(null)->provisionalDrafts(null)->status(null)->one()->getFieldValue('blocks')->status(null)->all();
        $I->assertSame(['First'], array_map(fn(Entry $block) => $block->getFieldValue('body'), $blocks));
        $I->assertSame([], Entry::find()->id($target->id)->status(null)->one()->getFieldValue('blocks')->status(null)->all(), 'The live entry is untouched');
    }

    public function pastingAndPreviewingNeedTheImportPermission(FunctionalTester $I): void
    {
        [$payload, $draft, $target] = $this->payloadAndTargetDraft($I);
        $editor = Schema::user('editor');
        Schema::grantPermissions($editor, ['accessCp', 'dolly-exportContent', ...$this->entryPermissions($target)]);
        $I->amLoggedInAs($editor);

        $this->post($I, 'paste/paste', $this->pasteParams($draft, $payload) + ['previewed' => 'x'], 403);
    }

    public function pastingWithChoicesThatChangedSinceThePreviewPastesNothing(FunctionalTester $I): void
    {
        [$payload, $draft] = $this->payloadAndTargetDraft($I);
        $I->amLoggedInAs(User::find()->admin()->one());

        $response = $this->post($I, 'paste/paste', $this->pasteParams($draft, $payload) + ['previewed' => 'stale'], 400);

        $I->assertSame('Your choices changed since the Preview. Check it, then paste.', $response['message']);
        $blocks = Entry::find()->id($draft->id)->drafts(null)->provisionalDrafts(null)->status(null)->one()->getFieldValue('blocks')->status(null)->all();
        $I->assertSame([], $blocks);
    }

    public function pastingIntoTheLiveEntryIsRefused(FunctionalTester $I): void
    {
        [$payload, , $target] = $this->payloadAndTargetDraft($I);
        $I->amLoggedInAs(User::find()->admin()->one());

        $response = $this->post($I, 'paste/paste', [
            'elementId' => $target->id,
            'siteId' => $target->siteId,
            'field' => 'blocks',
            'payload' => $payload,
            'previewed' => 'x',
        ], 400);

        $I->assertStringStartsWith('Blocks can only be pasted into a draft', $response['message']);
    }

    /**
     * @return array{Entry, Section} a page with three text blocks, and its section
     */
    private function sourceWithBlocks(): array
    {
        $text = Schema::blockType('text', [Schema::plainText('body')]);
        $page = Schema::entryType('page', [Schema::plainText('summary'), Schema::matrix('blocks', [$text])]);
        $pages = Schema::section('pages', [$page]);
        $entry = Schema::entry($pages, $page, ['title' => 'Staging', 'blocks' => [
            'new1' => ['type' => 'text', 'fields' => ['body' => 'First']],
            'new2' => ['type' => 'text', 'fields' => ['body' => 'Second']],
            'new3' => ['type' => 'text', 'fields' => ['body' => 'Third']],
        ]]);

        return [$entry, $pages];
    }

    private function emptyPage(Section $section): Entry
    {
        /** @var EntryType $page */
        $page = $section->getEntryTypes()[0];

        return Schema::draft(Schema::entry($section, $page, ['title' => 'Empty']), [], provisional: true);
    }

    /**
     * @return array{string, Entry, Entry} a Payload of the first block of a page, a draft of another page to paste it into and that page
     */
    private function payloadAndTargetDraft(FunctionalTester $I): array
    {
        [$source, $section] = $this->sourceWithBlocks();
        $block = $source->getFieldValue('blocks')->status(null)->all()[0];
        $payload = Plugin::getInstance()->getDolly()->export([$block]);
        /** @var EntryType $page */
        $page = $section->getEntryTypes()[0];
        $target = Schema::entry($section, $page, ['title' => 'Prod']);
        // The source's blocks are in the way of the UIDs the paste keeps, as they would be in the other environment.
        Schema::deleteElement($source);

        return [$payload, Schema::draft($target, [], provisional: true), $target];
    }

    /**
     * @return array<string, mixed>
     */
    private function pasteParams(Entry $draft, string $payload): array
    {
        return ['elementId' => $draft->id, 'siteId' => $draft->siteId, 'field' => 'blocks', 'payload' => $payload];
    }

    private function previewedToken(FunctionalTester $I, Entry $draft, string $payload): string
    {
        $plan = Plugin::getInstance()->getDolly()->preview($payload, target: $this->target($draft, 'blocks'));

        return $plan->fingerprint();
    }

    private function target(Entry $owner, string $handle): BlockTarget
    {
        return Schema::blockTarget($owner, $handle);
    }

    /**
     * Pastes a Payload into a new page's draft, and returns the blocks it ends up with.
     *
     * @return list<Entry>
     */
    private function pastedBlocks(FunctionalTester $I, string $payload, Section $section): array
    {
        $draft = $this->emptyPage($section);
        $result = Plugin::getInstance()->getDolly()->import($payload, target: $this->target($draft, 'blocks'));
        $I->assertFalse($result->isRefused());

        return array_values(Entry::find()->id($draft->id)->drafts(null)->provisionalDrafts(null)->status(null)->one()->getFieldValue('blocks')->status(null)->all());
    }

    /**
     * @return list<string> what an editor needs to open an entry in the control panel
     */
    private function entryPermissions(Entry $entry): array
    {
        $uid = $entry->getSection()->uid;

        return ["viewEntries:$uid", "saveEntries:$uid", "viewPeerEntries:$uid", "savePeerEntries:$uid", "createDrafts:$uid"];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function post(FunctionalTester $I, string $action, array $params, int $status = 200): array
    {
        // Craft's own pages carry the token its JavaScript sends with every action request.
        $I->haveHttpHeader('Accept', 'text/html');
        $I->amOnPage('/admin/myaccount');
        $I->haveHttpHeader('X-CSRF-Token', $I->grabAttributeFrom('input[name="CRAFT_CSRF_TOKEN"]', 'value'));
        $I->haveHttpHeader('Accept', 'application/json');
        $I->sendAjaxPostRequest("/admin/actions/dolly/$action", $params);
        $I->seeResponseCodeIs($status);

        return Json::decode($I->grabPageSource()) ?: [];
    }
}
