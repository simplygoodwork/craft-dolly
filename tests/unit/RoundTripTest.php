<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use craft\fields\Entries;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\tests\support\Schema;

/**
 * Export → Payload → Import round trips through the core service, the same layer the CLI calls.
 */
class RoundTripTest extends Unit
{
    public function testImportsAnExportedEntryWithItsPlainTextFields(): void
    {
        $article = Schema::entryType('article', [Schema::plainText('summary')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, [
            'title' => 'Hello Portage',
            'slug' => 'hello-portage',
            'summary' => 'A short summary',
        ]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        $result = $this->portage()->import($payload);

        $imported = $this->importedEntry($result->roots[0]->elementId);
        $this->assertSame('Hello Portage', $imported->title);
        $this->assertSame('hello-portage', $imported->slug);
        $this->assertSame('news', $imported->getSection()->handle);
        $this->assertSame('article', $imported->getType()->handle);
        $this->assertSame('A short summary', $imported->getFieldValue('summary'));
    }

    public function testImportedEntryIsDisabledAndKeepsTheSourceUid(): void
    {
        [$news, $article] = $this->newsSection();
        $source = Schema::entry($news, $article, ['title' => 'Live on staging']);
        $this->assertTrue($source->enabled);
        $sourceUid = $source->uid;

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        $result = $this->portage()->import($payload);

        $imported = $this->importedEntry($result->roots[0]->elementId);
        $this->assertFalse($imported->enabled);
        $this->assertSame($sourceUid, $imported->uid);
    }

    public function testImportedEntryGetsANewUidWhenTheSourceUidIsTaken(): void
    {
        [$news, $article] = $this->newsSection();
        $source = Schema::entry($news, $article, ['title' => 'Still here']);

        $result = $this->portage()->import($this->portage()->export([$source]));

        $imported = $this->importedEntry($result->roots[0]->elementId);
        $this->assertNotSame($source->id, $imported->id);
        $this->assertNotSame($source->uid, $imported->uid);
        $this->assertSame('Still here', $imported->title);
    }

    public function testImportsTextMatrixAndEntriesFieldsWithOwnedContentRecursivelyAndKeepsItsUids(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft']);

        $text = Schema::blockType('text', [Schema::plainText('body'), Schema::field(Entries::class, 'relatedTopics')]);
        $columns = Schema::blockType('columns', [
            Schema::plainText('heading'),
            Schema::matrix('cells', [$text]),
        ]);
        $page = Schema::entryType('page', [Schema::plainText('summary'), Schema::matrix('contentBlocks', [$text, $columns])]);
        $pages = Schema::section('pages', [$page]);

        $source = Schema::entry($pages, $page, [
            'title' => 'With blocks',
            'summary' => 'A page',
            'contentBlocks' => [
                'new1' => ['type' => 'text', 'fields' => ['body' => 'Intro']],
                'new2' => [
                    'type' => 'columns',
                    'fields' => [
                        'heading' => 'Two columns',
                        'cells' => [
                            'new1' => ['type' => 'text', 'fields' => ['body' => 'Left', 'relatedTopics' => [$craft->id]]],
                            'new2' => ['type' => 'text', 'enabled' => false, 'fields' => ['body' => 'Right (hidden)']],
                        ],
                    ],
                ],
            ],
        ]);
        $sourceBlocks = $this->blocks($source, 'contentBlocks');
        $sourceCells = $this->blocks($sourceBlocks[1], 'cells');

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        $result = $this->portage()->import($payload);

        $imported = $this->importedEntry($result->roots[0]->elementId);
        $this->assertSame('A page', $imported->getFieldValue('summary'));
        $blocks = $this->blocks($imported, 'contentBlocks');
        $this->assertSame(['text', 'columns'], array_map(fn(Entry $block) => $block->getType()->handle, $blocks));
        $this->assertSame('Intro', $blocks[0]->getFieldValue('body'));
        $this->assertSame('Two columns', $blocks[1]->getFieldValue('heading'));
        $this->assertSame($imported->id, $blocks[0]->getOwnerId());

        $cells = $this->blocks($blocks[1], 'cells');
        $this->assertSame(['Left', 'Right (hidden)'], array_map(fn(Entry $cell) => $cell->getFieldValue('body'), $cells));
        $this->assertSame([true, false], array_map(fn(Entry $cell) => $cell->enabled, $cells));
        $this->assertSame([$craft->id], $cells[0]->getFieldValue('relatedTopics')->ids());
        $this->assertSame($blocks[1]->id, $cells[0]->getOwnerId());

        $this->assertSame(
            [$sourceBlocks[0]->uid, $sourceBlocks[1]->uid, $sourceCells[0]->uid, $sourceCells[1]->uid],
            [$blocks[0]->uid, $blocks[1]->uid, $cells[0]->uid, $cells[1]->uid],
        );
    }

    public function testResolvesEntriesFieldReferencesByUidAndReportsUnresolvedOnes(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft']);
        $php = Schema::entry($topics, $topic, ['title' => 'PHP']);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone']);

        $article = Schema::entryType('article', [Schema::field(Entries::class, 'relatedTopics')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, [
            'title' => 'Related',
            'relatedTopics' => [$craft->id, $gone->id, $php->id],
        ]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        Craft::$app->getElements()->deleteElement($gone);
        $result = $this->portage()->import($payload);

        $imported = $this->importedEntry($result->roots[0]->elementId);
        $this->assertSame([$craft->id, $php->id], $imported->getFieldValue('relatedTopics')->ids());
        $this->assertSame([
            ['uid' => $gone->uid, 'elementType' => 'entry', 'title' => 'Gone', 'field' => 'Related Topics'],
        ], $result->roots[0]->unresolvedReferences);
    }

    /**
     * Nested entries in a Matrix field, in order, including disabled ones.
     *
     * @return Entry[]
     */
    private function blocks(Entry $owner, string $fieldHandle): array
    {
        /** @var \craft\elements\db\EntryQuery $query */
        $query = $owner->getFieldValue($fieldHandle);

        return (clone $query)->status(null)->all();
    }

    /**
     * @return array{0: \craft\models\Section, 1: \craft\models\EntryType}
     */
    private function newsSection(): array
    {
        $article = Schema::entryType('article', [Schema::plainText('summary')]);

        return [Schema::section('news', [$article]), $article];
    }

    private function portage(): \simplygoodwork\craftportage\services\Portage
    {
        return Plugin::getInstance()->getPortage();
    }

    private function hardDelete(Entry $entry): void
    {
        Craft::$app->getElements()->deleteElement($entry, true);
    }

    private function importedEntry(?int $id): Entry
    {
        $this->assertNotNull($id, 'The Import result has no element ID');
        $entry = Entry::find()->id($id)->status(null)->one();
        $this->assertNotNull($entry, "No entry with ID $id");

        return $entry;
    }
}
