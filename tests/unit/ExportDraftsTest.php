<?php

namespace simplygoodwork\craftdolly\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Entry;
use craft\models\Section;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\services\Dolly;
use simplygoodwork\craftdolly\tests\support\Schema;

/**
 * Exporting what the entry editor is showing: a draft's content, carried as the entry it's a draft of.
 */
class ExportDraftsTest extends Unit
{
    public function testADraftIsExportedWithItsOwnContentAsTheEntryItIsADraftOf(): void
    {
        $article = Schema::entryType('article', [Schema::plainText('summary')]);
        $news = Schema::section('news', [$article]);
        $entry = Schema::entry($news, $article, ['title' => 'Live title', 'summary' => 'Live summary']);
        $draft = Schema::draft($entry, ['title' => 'Draft title', 'summary' => 'Draft summary']);
        $payload = $this->dolly()->export([$draft]);

        // The Payload is imported where the entry was changed since, and where it's matched by UID.
        $result = $this->dolly()->import($payload, [], [$entry->uid => ['mode' => 'overwrite']]);

        $root = $result->roots[0];
        $this->assertSame('overwritten', $root->status, implode(' ', $root->errors));
        $this->assertSame($entry->id, $root->elementId, 'The Payload names the entry, not the draft, so it matches the entry');
        $imported = Entry::find()->draftId($root->draftId)->status(null)->one();
        $this->assertSame('Draft title', $imported->title);
        $this->assertSame('Draft summary', $imported->getFieldValue('summary'));
        $this->assertSame('Live title', Entry::find()->id($entry->id)->status(null)->one()->title);
    }

    public function testAProvisionalDraftsUnsavedChangesAreExported(): void
    {
        $article = Schema::entryType('article', [Schema::plainText('summary')]);
        $news = Schema::section('news', [$article]);
        $entry = Schema::entry($news, $article, ['title' => 'Live title', 'summary' => 'Live summary']);
        $draft = Schema::draft($entry, ['summary' => 'Typed but not applied'], provisional: true);
        $payload = $this->dolly()->export([$draft]);

        Schema::deleteElement($entry);
        $result = $this->dolly()->import($payload);

        $root = $result->roots[0];
        $this->assertSame('created', $root->status, implode(' ', $root->errors));
        $created = Entry::find()->id($root->elementId)->status(null)->one();
        $this->assertSame('Live title', $created->title);
        $this->assertSame('Typed but not applied', $created->getFieldValue('summary'));
        $this->assertSame($entry->uid, $created->uid, 'The imported entry keeps the UID of the entry the draft belongs to');
    }

    public function testSeveralEntriesBecomeOnePayloadWithSeveralRootElements(): void
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $first = Schema::entry($news, $article, ['title' => 'First']);
        $second = Schema::entry($news, $article, ['title' => 'Second']);
        $payload = $this->dolly()->export([$first, $second]);
        Schema::deleteElement($first);
        Schema::deleteElement($second);

        $result = $this->dolly()->import($payload);

        $this->assertCount(2, $result->roots);
        $this->assertSame(['created', 'created'], array_map(fn($root) => $root->status, $result->roots));
        $this->assertSame(['First', 'Second'], array_map(fn($root) => $root->title, $result->roots));
    }

    public function testADraftOfAStructureEntryIsImportedUnderItsParent(): void
    {
        $article = Schema::entryType('article');
        $pages = Schema::section('pages', [$article], ['type' => Section::TYPE_STRUCTURE]);
        $parent = Schema::entry($pages, $article, ['title' => 'Parent', 'slug' => 'parent']);
        $child = Schema::entry($pages, $article, ['title' => 'Child', 'slug' => 'child', 'parentId' => $parent->id]);
        $draft = Schema::draft($child, ['title' => 'Child draft']);
        $payload = $this->dolly()->export([$draft]);
        Schema::deleteElement($child);

        $result = $this->dolly()->import($payload);

        $this->assertSame('created', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $created = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $this->assertSame('Child draft', $created->title);
        $this->assertSame($parent->id, $created->getParent()?->id);
    }

    private function dolly(): Dolly
    {
        return Plugin::getInstance()->getDolly();
    }
}
