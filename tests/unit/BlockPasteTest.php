<?php

namespace simplygoodwork\craftdolly\tests\unit;

use benf\neo\elements\Block;
use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\PropagationMethod;
use craft\fields\Entries;
use craft\models\EntryType;
use simplygoodwork\craftdolly\import\BlockTarget;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\services\Dolly;
use simplygoodwork\craftdolly\tests\support\Requires;
use simplygoodwork\craftdolly\tests\support\Schema;

/**
 * Copying Matrix and Neo blocks out of a field and pasting them into a draft's field, through the core service.
 */
class BlockPasteTest extends Unit
{
    /**
     * A paste is always made by a user, who needs the permission to edit the draft.
     */
    protected function _before(): void
    {
        parent::_before();
        Requires::craft('5.7.0', 'Block copy/paste');
        Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    }

    public function testMatrixBlocksArePastedIntoTheDraftAfterItsOwnBlocksAndTheLiveEntryIsUntouched(): void
    {
        [$article, $news] = $this->matrixSchema();
        $source = Schema::entry($news, $article, ['title' => 'Staging', 'blocks' => [
            'new1' => ['type' => 'text', 'fields' => ['body' => 'First']],
            'new2' => ['type' => 'quote', 'fields' => ['quote' => 'Second']],
            'new3' => ['type' => 'text', 'fields' => ['body' => 'Third']],
        ]]);
        $copied = $this->blocks($source, 'blocks');
        $payload = $this->dolly()->export([$copied[0], $copied[2]]);
        Schema::deleteElement($source);
        $target = Schema::entry($news, $article, ['title' => 'Prod', 'blocks' => [
            'new1' => ['type' => 'text', 'fields' => ['body' => 'Already here']],
        ]]);
        $draft = Schema::draft($target, [], provisional: true);

        $result = $this->dolly()->import($payload, target: $this->target($draft, 'blocks'));

        $this->assertFalse($result->isRefused());
        $this->assertCount(2, $result->roots);
        foreach ($result->roots as $root) {
            $this->assertSame('pasted', $root->status, implode(' ', $root->errors));
            $this->assertSame($draft->id, $root->elementId);
        }
        $this->assertSame(['Already here', 'First', 'Third'], $this->bodies($this->draftOf($draft), 'blocks'));
        $this->assertSame(['Already here'], $this->bodies(Entry::find()->id($target->id)->status(null)->one(), 'blocks'), 'The live entry is untouched');
        $pasted = $this->blocks($this->draftOf($draft), 'blocks');
        $this->assertSame([$copied[0]->uid, $copied[2]->uid], [$pasted[1]->uid, $pasted[2]->uid], 'The blocks keep their UIDs');
        $this->assertSame(1, count(Plugin::getInstance()->getImportLog()->getEntries()));
    }

    public function testPastingMoreThanTenBlocksStillRunsInlineWhenTheQueueIsAllowed(): void
    {
        [$article, $news] = $this->matrixSchema();
        $blocks = [];
        foreach (range(1, 11) as $i) {
            $blocks["new$i"] = ['type' => 'text', 'fields' => ['body' => "Block $i"]];
        }
        $source = Schema::entry($news, $article, ['title' => 'Staging', 'blocks' => $blocks]);
        $payload = $this->dolly()->export($this->blocks($source, 'blocks'));
        $draft = Schema::draft(Schema::entry($news, $article, ['title' => 'Prod']), [], provisional: true);

        $result = $this->dolly()->import($payload, target: $this->target($draft, 'blocks'), queue: true);

        $this->assertFalse($result->queued);
        $this->assertCount(11, $result->roots);
        $this->assertCount(11, $this->blocks($this->draftOf($draft), 'blocks'));
        $this->assertSame(0, (int)(new \craft\db\Query())->from(\craft\db\Table::QUEUE)->where(['like', 'job', 'ImportJob'])->count());
    }

    public function testPastingTwiceKeepsEarlierPastesAndGivesTheCopiesNewUids(): void
    {
        [$article, $news] = $this->matrixSchema();
        $source = Schema::entry($news, $article, ['title' => 'Staging', 'blocks' => [
            'new1' => ['type' => 'text', 'fields' => ['body' => 'Once']],
        ]]);
        $payload = $this->dolly()->export($this->blocks($source, 'blocks'));
        $draft = Schema::draft($source, [], provisional: true);

        $this->dolly()->import($payload, target: $this->target($draft, 'blocks'));
        $this->dolly()->import($payload, target: $this->target($draft, 'blocks'));

        $blocks = $this->blocks($this->draftOf($draft), 'blocks');
        $this->assertSame(['Once', 'Once', 'Once'], array_map(fn(Entry $block) => $block->getFieldValue('body'), $blocks));
        $this->assertCount(3, array_unique(array_map(fn(Entry $block) => $block->uid, $blocks)));
    }

    public function testMatrixBlocksArePastedIntoAnotherMatrixFieldThatAllowsTheirEntryTypes(): void
    {
        [$article, $news] = $this->matrixSchema(otherField: true);
        $source = Schema::entry($news, $article, ['title' => 'Staging', 'blocks' => [
            'new1' => ['type' => 'quote', 'fields' => ['quote' => 'Wise words']],
        ]]);
        $payload = $this->dolly()->export($this->blocks($source, 'blocks'));
        $draft = Schema::draft(Schema::entry($news, $article, ['title' => 'Prod']), [], provisional: true);

        $result = $this->dolly()->import($payload, target: $this->target($draft, 'quotesOnly'));

        $this->assertSame('pasted', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertSame(['Wise words'], array_map(fn(Entry $block) => $block->getFieldValue('quote'), $this->blocks($this->draftOf($draft), 'quotesOnly')));
        $this->assertSame([], $this->blocks($this->draftOf($draft), 'blocks'));
    }

    public function testBlocksTheFieldDoesNotAllowAreSkippedWithAWarning(): void
    {
        [$article, $news] = $this->matrixSchema(otherField: true);
        $source = Schema::entry($news, $article, ['title' => 'Staging', 'blocks' => [
            'new1' => ['type' => 'text', 'fields' => ['body' => 'Not a quote']],
            'new2' => ['type' => 'quote', 'fields' => ['quote' => 'A quote']],
        ]]);
        $payload = $this->dolly()->export($this->blocks($source, 'blocks'));
        $draft = Schema::draft(Schema::entry($news, $article, ['title' => 'Prod']), [], provisional: true);
        $target = $this->target($draft, 'quotesOnly');

        $plan = $this->dolly()->preview($payload, target: $target);
        $this->assertFalse($plan->isRefused());
        $this->assertTrue($plan->roots[0]->isBlocked());
        $this->assertSame('None of its blocks can be added to the field "Quotes Only".', $plan->roots[0]->blockedBy);
        $this->assertSame(
            ['Blocks of type "Text" in "Quotes Only" will be skipped: their entry type isn\'t allowed in that field in this environment.'],
            $plan->roots[0]->warnings,
        );
        $this->assertFalse($plan->roots[1]->isBlocked());

        $result = $this->dolly()->import($payload, target: $target);

        $this->assertSame(['failed', 'pasted'], array_map(fn($root) => $root->status, $result->roots));
        $this->assertSame(['A quote'], array_map(fn(Entry $block) => $block->getFieldValue('quote'), $this->blocks($this->draftOf($draft), 'quotesOnly')));
    }

    public function testNeoBlocksAreCopiedWithTheirChildrenAndPastedIntoTheSameFieldKeepingTheHierarchy(): void
    {
        [$article, $news] = $this->neoSchema();
        $source = Schema::entry($news, $article, ['title' => 'Staging', 'content' => [
            ['type' => 'group', 'level' => 1, 'fields' => ['heading' => 'Parent']],
            ['type' => 'paragraph', 'level' => 2, 'fields' => ['copy' => 'Child A']],
            ['type' => 'group', 'level' => 2, 'fields' => ['heading' => 'Child group']],
            ['type' => 'paragraph', 'level' => 3, 'fields' => ['copy' => 'Grandchild']],
            ['type' => 'paragraph', 'level' => 1, 'fields' => ['copy' => 'Sibling']],
        ]]);
        $blocks = $this->neoBlocks($source);
        $payload = $this->dolly()->export([$blocks[0]]);
        $target = Schema::entry($news, $article, ['title' => 'Prod', 'content' => [
            ['type' => 'paragraph', 'level' => 1, 'fields' => ['copy' => 'Already here']],
        ]]);
        $draft = Schema::draft($target, [], provisional: true);

        $result = $this->dolly()->import($payload, target: $this->target($draft, 'content'));

        $this->assertSame(1, count($result->roots), 'The children are Owned content, not Root elements');
        $this->assertSame('pasted', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $pasted = $this->neoBlocks($this->draftOf($draft));
        $this->assertSame(
            [['paragraph', 1, 'Already here'], ['group', 1, 'Parent'], ['paragraph', 2, 'Child A'], ['group', 2, 'Child group'], ['paragraph', 3, 'Grandchild']],
            array_map(fn(Block $block) => [
                $block->getType()->handle,
                (int)$block->level,
                $block->getFieldValue($block->getType()->handle === 'group' ? 'heading' : 'copy'),
            ], $pasted),
        );
    }

    public function testACopiedChildBlockIsPastedAtTheTopLevelWithItsOwnChildren(): void
    {
        [$article, $news] = $this->neoSchema();
        $source = Schema::entry($news, $article, ['title' => 'Staging', 'content' => [
            ['type' => 'group', 'level' => 1, 'fields' => ['heading' => 'Parent']],
            ['type' => 'group', 'level' => 2, 'fields' => ['heading' => 'Child group']],
            ['type' => 'paragraph', 'level' => 3, 'fields' => ['copy' => 'Grandchild']],
        ]]);
        $blocks = $this->neoBlocks($source);
        // The parent and the grandchild are selected too: the grandchild is carried by the child, and not twice.
        $payload = $this->dolly()->export([$blocks[1], $blocks[2]]);
        $draft = Schema::draft(Schema::entry($news, $article, ['title' => 'Prod']), [], provisional: true);

        $result = $this->dolly()->import($payload, target: $this->target($draft, 'content'));

        $this->assertSame(1, count($result->roots));
        $this->assertSame(
            [['group', 1], ['paragraph', 2]],
            array_map(fn(Block $block) => [$block->getType()->handle, (int)$block->level], $this->neoBlocks($this->draftOf($draft))),
        );
    }

    public function testNeoBlocksCannotBePastedIntoAnotherNeoFieldOrAMatrixField(): void
    {
        [$article, $news] = $this->neoSchema(otherField: true);
        $source = Schema::entry($news, $article, ['title' => 'Staging', 'content' => [
            ['type' => 'paragraph', 'level' => 1, 'fields' => ['copy' => 'Hello']],
        ]]);
        $payload = $this->dolly()->export($this->neoBlocks($source));
        $draft = Schema::draft(Schema::entry($news, $article, ['title' => 'Prod']), [], provisional: true);

        $other = $this->dolly()->preview($payload, target: $this->target($draft, 'sidebar'));
        $this->assertTrue($other->isRefused());
        $this->assertSame('Neo blocks can only be pasted into the Neo field they were copied from ("Content"). They can\'t be pasted into the field "Sidebar".', $other->refusal);

        $matrix = $this->dolly()->preview($payload, target: $this->target($draft, 'notes'));
        $this->assertTrue($matrix->isRefused());
        $this->assertSame('Neo blocks can only be pasted into the Neo field they were copied from ("Content"). They can\'t be pasted into the field "Notes".', $matrix->refusal);

        $result = $this->dolly()->import($payload, target: $this->target($draft, 'sidebar'));
        $this->assertTrue($result->isRefused());
        $this->assertSame([], $this->neoBlocks($this->draftOf($draft), 'sidebar'));
        $this->assertSame([], Plugin::getInstance()->getImportLog()->getEntries());
    }

    public function testMatrixBlocksCannotBePastedIntoANeoField(): void
    {
        [$article, $news] = $this->neoSchema(otherField: true);
        $source = Schema::entry($news, $article, ['title' => 'Staging', 'notes' => [
            'new1' => ['type' => 'note', 'fields' => ['text' => 'Hello']],
        ]]);
        $payload = $this->dolly()->export($this->blocks($source, 'notes'));
        $draft = Schema::draft(Schema::entry($news, $article, ['title' => 'Prod']), [], provisional: true);

        $plan = $this->dolly()->preview($payload, target: $this->target($draft, 'content'));

        $this->assertSame('Matrix blocks can\'t be pasted into the field "Content", which is a Neo field. Paste them into a Matrix field.', $plan->refusal);
    }

    public function testPayloadsOfEntriesAndBlocksAreRefusedWhereTheyDontBelong(): void
    {
        [$article, $news] = $this->matrixSchema();
        $source = Schema::entry($news, $article, ['title' => 'Staging', 'blocks' => [
            'new1' => ['type' => 'text', 'fields' => ['body' => 'Hello']],
        ]]);
        $blocksPayload = $this->dolly()->export($this->blocks($source, 'blocks'));
        $entriesPayload = $this->dolly()->export([$source]);
        $draft = Schema::draft($source, [], provisional: true);

        $this->assertSame(
            'This Payload holds blocks copied from a Matrix or Neo field. Paste it with "Paste from another environment" in the menu of a Matrix or Neo field in an entry editor.',
            $this->dolly()->preview($blocksPayload)->refusal,
        );
        $this->assertTrue($this->dolly()->import($blocksPayload)->isRefused());
        $this->assertSame(
            'This Payload holds whole entries, not blocks. Import it in the Dolly utility.',
            $this->dolly()->preview($entriesPayload, target: $this->target($draft, 'blocks'))->refusal,
        );
    }

    public function testBlocksCanOnlyBePastedIntoADraftNeverLiveContent(): void
    {
        [$article, $news] = $this->matrixSchema();
        $source = Schema::entry($news, $article, ['title' => 'Staging', 'blocks' => [
            'new1' => ['type' => 'text', 'fields' => ['body' => 'Hello']],
        ]]);
        $payload = $this->dolly()->export($this->blocks($source, 'blocks'));

        $result = $this->dolly()->import($payload, target: $this->target($source, 'blocks'));

        $this->assertTrue($result->isRefused());
        $this->assertSame(
            'Blocks can only be pasted into a draft, so they can\'t be added to the field "Blocks" of the live entry "Staging". Open the entry in the editor and paste there.',
            $result->refusal,
        );
        $this->assertSame(['Hello'], $this->bodies(Entry::find()->id($source->id)->status(null)->one(), 'blocks'));
    }

    public function testUnresolvedReferencesInBlocksHaveTheSameChoicesAsEntries(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);
        $card = Schema::blockType('card', [Schema::field(Entries::class, 'related')]);
        $page = Schema::entryType('page', [Schema::matrix('blocks', [$card])]);
        $pages = Schema::section('pages', [$page]);
        $source = Schema::entry($pages, $page, ['title' => 'Staging', 'blocks' => [
            'new1' => ['type' => 'card', 'fields' => ['related' => [$craft->id]]],
        ]]);
        $payload = $this->dolly()->export($this->blocks($source, 'blocks'));
        Schema::deleteElement($craft);
        $draft = Schema::draft(Schema::entry($pages, $page, ['title' => 'Prod']), [], provisional: true);
        $target = $this->target($draft, 'blocks');

        $plan = $this->dolly()->preview($payload, target: $target);
        $reference = array_values($plan->references)[0];
        $this->assertSame(['skip', 'stub', 'replace'], $reference->availableChoices);
        $this->assertSame([['uid' => $craft->uid, 'elementType' => 'entry', 'title' => 'Craft', 'field' => 'Related']], $plan->roots[0]->unresolvedReferences);

        $result = $this->dolly()->import($payload, [$craft->uid => 'stub'], target: $target);

        $this->assertSame('pasted', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertCount(1, $result->roots[0]->stubs);
        $related = $this->blocks($this->draftOf($draft), 'blocks')[0]->getFieldValue('related')->status(null)->all();
        $this->assertSame(['Craft'], array_map(fn(Entry $entry) => $entry->title, $related));
        $this->assertFalse($related[0]->enabled);
    }

    public function testAUserWhoCannotSaveTheEntryCannotPasteIntoIt(): void
    {
        [$article, $news] = $this->matrixSchema();
        $source = Schema::entry($news, $article, ['title' => 'Staging', 'blocks' => [
            'new1' => ['type' => 'text', 'fields' => ['body' => 'Hello']],
        ]]);
        $payload = $this->dolly()->export($this->blocks($source, 'blocks'));
        $draft = Schema::draft(Schema::entry($news, $article, ['title' => 'Prod']), [], provisional: true);
        $editor = Schema::user('editor');
        Schema::grantPermissions($editor, ['accessCp', 'viewEntries:' . $news->uid]);
        Craft::$app->getUser()->setIdentity($editor);

        $result = $this->dolly()->import($payload, target: $this->target($draft, 'blocks'));

        $this->assertSame('failed', $result->roots[0]->status);
        $this->assertSame(['You don\'t have permission to edit the entry "Prod", so blocks can\'t be pasted into the field "Blocks".'], $result->roots[0]->errors);
        $this->assertSame([], $this->blocks($this->draftOf($draft), 'blocks'));
    }

    public function testOnlyTheDraftsOwnSiteIsWrittenAndCraftPropagatesTheBlocksWhenTheFieldDoes(): void
    {
        $german = Schema::site('german', ['language' => 'de']);
        [$article, $news] = $this->matrixSchema(propagation: PropagationMethod::All);
        $source = Schema::entry($news, $article, ['title' => 'Staging', 'blocks' => [
            'new1' => ['type' => 'text', 'fields' => ['body' => 'Hello']],
        ]]);
        $payload = $this->dolly()->export($this->blocks($source, 'blocks'));
        Schema::deleteElement($source);
        $draft = Schema::draft(Schema::entry($news, $article, ['title' => 'Prod']), [], provisional: true);

        $result = $this->dolly()->import($payload, target: $this->target($draft, 'blocks'));

        $this->assertSame('pasted', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertSame(['Hello'], $this->bodies($this->draftOf($draft), 'blocks'));
        $this->assertSame(['Hello'], $this->bodies($this->draftOf($draft, $german->id), 'blocks'), 'Craft propagated the block');
    }

    public function testASiteTheFieldDoesNotPropagateToIsLeftAlone(): void
    {
        $german = Schema::site('german', ['language' => 'de']);
        [$article, $news] = $this->matrixSchema(propagation: PropagationMethod::None);
        $source = Schema::entry($news, $article, ['title' => 'Staging', 'blocks' => [
            'new1' => ['type' => 'text', 'fields' => ['body' => 'Hello']],
        ]]);
        $payload = $this->dolly()->export($this->blocks($source, 'blocks'));
        Schema::deleteElement($source);
        $draft = Schema::draft(Schema::entry($news, $article, ['title' => 'Prod', 'blocks' => [
            'new1' => ['type' => 'text', 'fields' => ['body' => 'Existing']],
        ]]), [], provisional: true);

        $result = $this->dolly()->import($payload, target: $this->target($draft, 'blocks'));

        $this->assertSame('pasted', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertSame(['Existing', 'Hello'], $this->bodies($this->draftOf($draft), 'blocks'));
        $this->assertSame(['Existing'], $this->bodies($this->draftOf($draft, $german->id), 'blocks'));
    }

    public function testASiteTheUserMayNotEditIsNeverWritten(): void
    {
        $german = Schema::site('german', ['language' => 'de']);
        [$article, $news] = $this->matrixSchema(propagation: PropagationMethod::None);
        $source = Schema::entry($news, $article, ['title' => 'Staging', 'blocks' => [
            'new1' => ['type' => 'text', 'fields' => ['body' => 'Hello']],
        ]]);
        $payload = $this->dolly()->export($this->blocks($source, 'blocks'));
        Schema::deleteElement($source);
        $draft = Schema::draft(Schema::entry($news, $article, ['title' => 'Prod']), [], provisional: true);
        $primary = Craft::$app->getSites()->getPrimarySite();
        $editor = Schema::user('editor');
        Schema::grantPermissions($editor, [
            'accessCp',
            "editSite:$primary->uid",
            "viewEntries:$news->uid",
            "saveEntries:$news->uid",
            "viewPeerEntries:$news->uid",
            "savePeerEntries:$news->uid",
            "viewPeerEntryDrafts:$news->uid",
            "savePeerEntryDrafts:$news->uid",
        ]);
        Craft::$app->getUser()->setIdentity($editor);

        $result = $this->dolly()->import($payload, target: $this->target($draft, 'blocks'));

        $this->assertSame('pasted', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertSame(['Hello'], $this->bodies($this->draftOf($draft), 'blocks'));
        $this->assertSame([], $this->bodies($this->draftOf($draft, $german->id), 'blocks'));
    }

    public function testThePreviewWarnsWhenThePasteTakesTheFieldPastItsMaximum(): void
    {
        [$article, $news] = $this->matrixSchema(maxEntries: 1);
        $source = Schema::entry($news, $article, ['title' => 'Staging', 'blocks' => [
            'new1' => ['type' => 'text', 'fields' => ['body' => 'One']],
            'new2' => ['type' => 'text', 'fields' => ['body' => 'Two']],
        ]]);
        $payload = $this->dolly()->export($this->blocks($source, 'blocks'));
        Schema::deleteElement($source);
        $draft = Schema::draft(Schema::entry($news, $article, ['title' => 'Prod', 'blocks' => [
            'new1' => ['type' => 'text', 'fields' => ['body' => 'Existing']],
        ]]), [], provisional: true);

        $plan = $this->dolly()->preview($payload, target: $this->target($draft, 'blocks'));

        $this->assertSame(
            ['The field "Blocks" allows at most 1 block. Your draft has 1 and this adds 1, so remove some before applying the draft.'],
            $plan->roots[0]->warnings,
        );
        $this->assertSame('pasted', $this->dolly()->import($payload, target: $this->target($draft, 'blocks'))->roots[0]->status, 'The draft saves anyway');
    }

    public function testAPasteWithNobodyLoggedInIsBlocked(): void
    {
        [$article, $news] = $this->matrixSchema();
        $source = Schema::entry($news, $article, ['title' => 'Staging', 'blocks' => [
            'new1' => ['type' => 'text', 'fields' => ['body' => 'Hello']],
        ]]);
        $payload = $this->dolly()->export($this->blocks($source, 'blocks'));
        $draft = Schema::draft(Schema::entry($news, $article, ['title' => 'Prod']), [], provisional: true);
        Craft::$app->getUser()->setIdentity(null);

        $result = $this->dolly()->import($payload, target: $this->target($draft, 'blocks'));

        $this->assertSame('failed', $result->roots[0]->status);
        $this->assertSame([], $this->blocks($this->draftOf($draft), 'blocks'));
    }

    /**
     * @return array{EntryType, \craft\models\Section}
     */
    private function matrixSchema(bool $otherField = false, ?PropagationMethod $propagation = null, ?int $maxEntries = null): array
    {
        $text = Schema::blockType('text', [Schema::plainText('body')]);
        $quote = Schema::blockType('quote', [Schema::plainText('quote')]);
        $article = Schema::entryType('article', [
            Schema::matrix('blocks', [$text, $quote], array_filter(['propagationMethod' => $propagation, 'maxEntries' => $maxEntries])),
            ...($otherField ? [Schema::matrix('quotesOnly', [$quote], ['name' => 'Quotes Only'])] : []),
        ]);

        return [$article, Schema::section('news', [$article])];
    }

    /**
     * @return array{EntryType, \craft\models\Section}
     */
    private function neoSchema(bool $otherField = false): array
    {
        $heading = Schema::plainText('heading');
        $copy = Schema::plainText('copy');
        $blockTypes = fn() => [
            Schema::neoBlockType('group', [$heading]),
            Schema::neoBlockType('paragraph', [$copy]),
        ];
        $note = Schema::blockType('note', [Schema::plainText('text')]);
        $article = Schema::entryType('article', [
            Schema::neo('content', $blockTypes()),
            ...($otherField ? [Schema::neo('sidebar', $blockTypes()), Schema::matrix('notes', [$note])] : []),
        ]);

        return [$article, Schema::section('news', [$article])];
    }

    private function target(Entry $owner, string $handle): BlockTarget
    {
        return Schema::blockTarget($owner, $handle);
    }

    /**
     * Returns the saved draft again, as the editor would load it.
     */
    private function draftOf(Entry $draft, ?int $siteId = null): Entry
    {
        return Entry::find()->id($draft->id)->siteId($siteId ?? $draft->siteId)->drafts(null)->provisionalDrafts(null)->status(null)->one();
    }

    /**
     * @return list<Entry>
     */
    private function blocks(Entry $owner, string $handle): array
    {
        return array_values($owner->getFieldValue($handle)->status(null)->all());
    }

    /**
     * @return list<string|null>
     */
    private function bodies(Entry $owner, string $handle): array
    {
        return array_map(fn(Entry $block) => $block->getFieldValue('body'), $this->blocks($owner, $handle));
    }

    /**
     * @return list<Block>
     */
    private function neoBlocks(Entry $owner, string $handle = 'content'): array
    {
        return array_values($owner->getFieldValue($handle)->status(null)->all());
    }

    private function dolly(): Dolly
    {
        return Plugin::getInstance()->getDolly();
    }
}
