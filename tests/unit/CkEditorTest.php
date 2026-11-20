<?php

namespace simplygoodwork\craftdolly\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\base\Field;
use craft\elements\Entry;
use craft\fields\Entries;
use craft\htmlfield\HtmlFieldData;
use craft\models\Site;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\services\Dolly;
use simplygoodwork\craftdolly\tests\support\Schema;

/**
 * The CKEditor adapter: HTML with ref tags and nested entries, which are Owned content.
 */
class CkEditorTest extends Unit
{
    protected function _before(): void
    {
        // CKEditor before 4.1 renders nested entries into the HTML whenever it normalizes a value in a site request.
        // Dolly reads and sets values in the control panel, the console and the queue, never a site request, and
        // these tests run as one, so they run as the control panel there.
        if (!class_exists(\craft\ckeditor\data\FieldData::class)) {
            Craft::$app->getRequest()->setIsCpRequest(true);
        }
    }

    public function testRoundTripsFormattedHtmlRefTagLinksAndNestedEntriesWithTheirContentAndUids(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);

        $text = Schema::blockType('text', [Schema::plainText('body')]);
        $callout = Schema::entryType('callout', [
            Schema::plainText('heading'),
            Schema::field(Entries::class, 'relatedTopics'),
            Schema::matrix('items', [$text]),
        ], ['hasTitleField' => false]);
        $body = Schema::ckeditor('richBody', [$callout]);
        $article = Schema::entryType('article', [$body]);
        $news = Schema::section('news', [$article]);

        $source = Schema::entry($news, $article, ['title' => 'Rich']);
        $marker = Schema::nestedCkeditorEntry($source, $body, $callout, [
            'heading' => 'Heads up',
            'relatedTopics' => [$craft->id],
            'items' => ['new1' => ['type' => 'text', 'fields' => ['body' => 'Inside a block']]],
        ]);
        $source = Schema::updateEntry($source, [
            'richBody' => "<h2>Title</h2><p>Some <strong>bold</strong> and <a href=\"{entry:$craft->id:url}\">a link</a>.</p>$marker<p>After</p>",
        ]);
        $sourceNested = $this->nestedEntries($source, 'richBody');
        $this->assertCount(1, $sourceNested);

        $payload = $this->dolly()->export([$source]);
        $this->hardDelete($source);

        $result = $this->dolly()->import($payload);

        $this->assertSame([], $result->roots[0]->errors ?? [], 'errors');
        $this->assertSame([], $result->roots[0]->warnings);
        $imported = $this->importedEntry($result->roots[0]->elementId);
        $nested = $this->nestedEntries($imported, 'richBody');
        $this->assertCount(1, $nested);
        $this->assertSame($sourceNested[0]->uid, $nested[0]->uid);
        $this->assertNotSame($sourceNested[0]->id, $nested[0]->id);
        $this->assertSame($imported->id, $nested[0]->getOwnerId());
        $this->assertSame('Heads up', $nested[0]->getFieldValue('heading'));
        $this->assertSame([$craft->id], $nested[0]->getFieldValue('relatedTopics')->ids());
        $this->assertSame(
            ['Inside a block'],
            array_map(fn(Entry $block) => $block->getFieldValue('body'), $nested[0]->getFieldValue('items')->status(null)->all()),
        );
        $this->assertSame(
            "<h2>Title</h2><p>Some <strong>bold</strong> and <a href=\"{entry:$craft->id:url||}\">a link</a>.</p><craft-entry data-entry-id=\"{$nested[0]->id}\"> </craft-entry><p>After</p>",
            $imported->getFieldValue('richBody')->getRawContent(),
        );
    }

    public function testRemovesANestedEntryWhoseTypeIsntAllowedInTheTargetFieldKeepingTheSurroundingHtml(): void
    {
        $note = Schema::entryType('note', [Schema::plainText('noteText')], ['hasTitleField' => false]);
        $quote = Schema::entryType('quote', [Schema::plainText('quoteText')], ['hasTitleField' => false]);
        $body = Schema::ckeditor('richBody', [$note, $quote]);
        $article = Schema::entryType('article', [$body]);
        $news = Schema::section('news', [$article]);

        $source = Schema::entry($news, $article, ['title' => 'Rich']);
        $noteMarker = Schema::nestedCkeditorEntry($source, $body, $note, ['noteText' => 'A note']);
        $quoteMarker = Schema::nestedCkeditorEntry($source, $body, $quote, ['quoteText' => 'A quote']);
        $source = Schema::updateEntry($source, ['richBody' => "<p>One</p>$noteMarker<p>Two</p>$quoteMarker<p>Three</p>"]);

        $payload = $this->dolly()->export([$source]);
        $this->hardDelete($source);
        Schema::updateField($body->uid, ['entryTypes' => [$note]]);

        $result = $this->dolly()->import($payload);

        $this->assertContains(
            'A nested entry of type "Quote" in "Rich Body" will be skipped: its entry type isn\'t allowed in that field in this environment.',
            $result->roots[0]->warnings,
        );
        $imported = $this->importedEntry($result->roots[0]->elementId);
        $nested = $this->nestedEntries($imported, 'richBody');
        $this->assertSame(['A note'], array_map(fn(Entry $entry) => $entry->getFieldValue('noteText'), $nested));
        $this->assertSame(
            "<p>One</p><craft-entry data-entry-id=\"{$nested[0]->id}\"> </craft-entry><p>Two</p><p>Three</p>",
            $imported->getFieldValue('richBody')->getRawContent(),
        );
    }

    public function testNestedEntriesGetNewUidsWhenTheSourceUidsAreTaken(): void
    {
        $note = Schema::entryType('note', [Schema::plainText('noteText')], ['hasTitleField' => false]);
        $body = Schema::ckeditor('richBody', [$note]);
        $article = Schema::entryType('article', [$body]);
        $news = Schema::section('news', [$article]);

        $source = Schema::entry($news, $article, ['title' => 'Rich']);
        $marker = Schema::nestedCkeditorEntry($source, $body, $note, ['noteText' => 'A note']);
        $source = Schema::updateEntry($source, ['richBody' => "<p>One</p>$marker"]);
        $sourceNested = $this->nestedEntries($source, 'richBody');

        $result = $this->dolly()->import($this->dolly()->export([$source]));

        $nested = $this->nestedEntries($this->importedEntry($result->roots[0]->elementId), 'richBody');
        $this->assertCount(1, $nested);
        $this->assertNotSame($sourceNested[0]->uid, $nested[0]->uid);
        $this->assertNotSame($sourceNested[0]->id, $nested[0]->id);
        $this->assertSame('A note', $nested[0]->getFieldValue('noteText'));
    }

    public function testAStubbedRefTagInTheHtmlAndInANestedEntrysOwnCkeditorFieldPointsAtTheStub(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone topic', 'slug' => 'gone']);

        $inner = Schema::ckeditor('innerBody');
        $box = Schema::entryType('box', [$inner], ['hasTitleField' => false]);
        $body = Schema::ckeditor('richBody', [$box]);
        $article = Schema::entryType('article', [$body]);
        $news = Schema::section('news', [$article]);

        $source = Schema::entry($news, $article, ['title' => 'Rich']);
        $marker = Schema::nestedCkeditorEntry($source, $body, $box, [
            'innerBody' => "<p>See {entry:$gone->id:title}</p>",
        ]);
        $source = Schema::updateEntry($source, ['richBody' => "<p><a href=\"{entry:$gone->id:url}\">Outer</a></p>$marker"]);

        $payload = $this->dolly()->export([$source]);
        $this->hardDelete($source);
        Schema::deleteElement($gone);
        $goneUid = $gone->uid;

        $result = $this->dolly()->import($payload, [$goneUid => 'stub']);

        $stub = Entry::find()->uid($goneUid)->status(null)->one();
        $this->assertNotNull($stub, 'No Stub with the source UID');
        $imported = $this->importedEntry($result->roots[0]->elementId);
        $nested = $this->nestedEntries($imported, 'richBody');
        $this->assertStringContainsString("<a href=\"{entry:$stub->id:url||}\">Outer</a>", $imported->getFieldValue('richBody')->getRawContent());
        $this->assertStringContainsString("<p>See {entry:$stub->id:title}</p>", $nested[0]->getFieldValue('innerBody')->getRawContent());
    }

    public function testANestedEntryKeepsItsContentForEachSite(): void
    {
        $english = Craft::$app->getSites()->getPrimarySite();
        $german = Schema::site('german', ['language' => 'de']);
        $note = Schema::entryType('note', [
            Schema::plainText('noteText', ['translationMethod' => Field::TRANSLATION_METHOD_SITE]),
            Schema::plainText('anchor'),
        ], ['hasTitleField' => false]);
        $body = Schema::ckeditor('richBody', [$note]);
        $article = Schema::entryType('article', [$body]);
        $news = Schema::section('news', [$article]);

        $source = Schema::entry($news, $article, ['title' => 'Rich']);
        $marker = Schema::nestedCkeditorEntry($source, $body, $note, ['noteText' => 'A note', 'anchor' => 'note']);
        $source = Schema::updateEntry($source, ['richBody' => "<p>One</p>$marker"]);
        Schema::translate($this->nestedEntries($source, 'richBody')[0], $german, ['noteText' => 'Eine Notiz']);

        $payload = $this->dolly()->export([$source]);
        $this->hardDelete($source);
        $result = $this->dolly()->import($payload);

        $this->assertSame([], $result->roots[0]->errors);
        $id = $result->roots[0]->elementId;
        $inEnglish = $this->nestedEntries($this->importedEntry($id, $english), 'richBody');
        $inGerman = $this->nestedEntries($this->importedEntry($id, $german), 'richBody');
        $this->assertCount(1, $inEnglish);
        $this->assertCount(1, $inGerman);
        $this->assertSame($inEnglish[0]->id, $inGerman[0]->id);
        $this->assertSame(['A note', 'note'], [$inEnglish[0]->getFieldValue('noteText'), $inEnglish[0]->getFieldValue('anchor')]);
        $this->assertSame(['Eine Notiz', 'note'], [$inGerman[0]->getFieldValue('noteText'), $inGerman[0]->getFieldValue('anchor')]);
    }

    public function testACkeditorFieldInAMatrixBlockKeepsItsNestedEntries(): void
    {
        $note = Schema::entryType('note', [Schema::plainText('noteText')], ['hasTitleField' => false]);
        $blockBody = Schema::ckeditor('blockBody', [$note]);
        $text = Schema::blockType('text', [$blockBody]);
        $page = Schema::entryType('page', [Schema::matrix('contentBlocks', [$text])]);
        $pages = Schema::section('pages', [$page]);

        $source = Schema::entry($pages, $page, [
            'title' => 'About',
            'contentBlocks' => ['new1' => ['type' => 'text']],
        ]);
        $block = $this->blocks($source, 'contentBlocks')[0];
        $marker = Schema::nestedCkeditorEntry($block, $blockBody, $note, ['noteText' => 'In a block']);
        Schema::updateEntry($block, ['blockBody' => "<p>Before</p>$marker<p>After</p>"]);
        $source = $this->importedEntry($source->id);

        $payload = $this->dolly()->export([$source]);
        $this->hardDelete($source);
        $result = $this->dolly()->import($payload);

        $this->assertSame([], $result->roots[0]->errors);
        $importedBlock = $this->blocks($this->importedEntry($result->roots[0]->elementId), 'contentBlocks')[0];
        $nested = $this->nestedEntries($importedBlock, 'blockBody');
        $this->assertCount(1, $nested);
        $this->assertSame('In a block', $nested[0]->getFieldValue('noteText'));
        $this->assertSame($importedBlock->id, $nested[0]->getOwnerId());
        $this->assertSame(
            "<p>Before</p><craft-entry data-entry-id=\"{$nested[0]->id}\">\u{00A0}</craft-entry><p>After</p>",
            $importedBlock->getFieldValue('blockBody')->getRawContent(),
        );
    }

    public function testAnEntryNestedInANestedEntryKeepsItsContent(): void
    {
        $note = Schema::entryType('note', [Schema::plainText('noteText')], ['hasTitleField' => false]);
        $inner = Schema::ckeditor('innerBody', [$note]);
        $box = Schema::entryType('box', [$inner], ['hasTitleField' => false]);
        $body = Schema::ckeditor('richBody', [$box]);
        $article = Schema::entryType('article', [$body]);
        $news = Schema::section('news', [$article]);

        $source = Schema::entry($news, $article, ['title' => 'Rich']);
        $boxMarker = Schema::nestedCkeditorEntry($source, $body, $box);
        $source = Schema::updateEntry($source, ['richBody' => "<p>Outer</p>$boxMarker"]);
        $boxEntry = $this->nestedEntries($source, 'richBody')[0];
        $noteMarker = Schema::nestedCkeditorEntry($boxEntry, $inner, $note, ['noteText' => 'Deep']);
        Schema::updateEntry($boxEntry, ['innerBody' => "<p>Inner</p>$noteMarker"]);
        $source = $this->importedEntry($source->id);

        $payload = $this->dolly()->export([$source]);
        $this->hardDelete($source);
        $result = $this->dolly()->import($payload);

        $this->assertSame([], $result->roots[0]->errors);
        $imported = $this->importedEntry($result->roots[0]->elementId);
        $boxes = $this->nestedEntries($imported, 'richBody');
        $this->assertCount(1, $boxes);
        $notes = $this->nestedEntries($boxes[0], 'innerBody');
        $this->assertCount(1, $notes);
        $this->assertSame('Deep', $notes[0]->getFieldValue('noteText'));
        $this->assertSame($boxes[0]->id, $notes[0]->getOwnerId());
    }

    public function testRequiredFieldsLeftEmptyInANestedEntryAreListedInThePreview(): void
    {
        $note = Schema::entryType('note', [Schema::plainText('noteText')], ['hasTitleField' => false]);
        $body = Schema::ckeditor('richBody', [$note]);
        $article = Schema::entryType('article', [$body]);
        $news = Schema::section('news', [$article]);

        $source = Schema::entry($news, $article, ['title' => 'Rich']);
        $marker = Schema::nestedCkeditorEntry($source, $body, $note, ['noteText' => 'Filled in']);
        $source = Schema::updateEntry($source, ['richBody' => "<p>One</p>$marker"]);
        $payload = $this->dolly()->export([$source]);
        $this->hardDelete($source);
        Schema::addFieldToEntryType($note, Schema::required(Schema::plainText('other')));

        $plan = $this->dolly()->preview($payload);

        $this->assertSame(['Rich Body › Other'], $plan->roots[0]->incompleteFields);
    }

    /**
     * The nested entries a CKEditor field's HTML embeds, in order.
     *
     * @return list<Entry>
     */
    private function nestedEntries(Entry $entry, string $handle): array
    {
        $value = $entry->getFieldValue($handle);
        $this->assertInstanceOf(HtmlFieldData::class, $value);

        // From the markers in the raw HTML, since CKEditor before 4.1 has no chunks to read the entries from.
        preg_match_all('/<craft-entry\b[^>]*?data-entry-id="(\d+)"/i', $value->getRawContent(), $matches);
        $nested = [];
        foreach ($matches[1] as $id) {
            $nested[] = Entry::find()->id((int)$id)->siteId($entry->siteId)->status(null)->drafts(null)->one()
                ?? $this->fail("A marker in the field \"$handle\" points at no entry");
        }

        return $nested;
    }

    private function hardDelete(Entry $entry): void
    {
        Schema::deleteElement($entry);
    }

    private function dolly(): Dolly
    {
        return Plugin::getInstance()->getDolly();
    }

    private function importedEntry(?int $id, ?Site $site = null): Entry
    {
        $this->assertNotNull($id, 'The Import result has no element ID');
        $query = Entry::find()->id($id)->status(null);
        if ($site) {
            $query->siteId($site->id);
        }
        $entry = $query->one();
        $this->assertNotNull($entry, "No entry with ID $id");

        return $entry;
    }

    /**
     * Nested entries in a Matrix field, in order, including disabled ones.
     *
     * @return list<Entry>
     */
    private function blocks(Entry $owner, string $fieldHandle): array
    {
        /** @var \craft\elements\db\EntryQuery $query */
        $query = $owner->getFieldValue($fieldHandle);

        return (clone $query)->status(null)->all();
    }
}
