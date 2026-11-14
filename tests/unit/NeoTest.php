<?php

namespace simplygoodwork\craftportage\tests\unit;

use benf\neo\elements\Block;
use Codeception\Test\Unit;
use Craft;
use craft\base\Field;
use craft\elements\Entry;
use craft\enums\PropagationMethod;
use craft\fields\Entries;
use craft\models\Site;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\tests\support\Schema;

/**
 * The Neo adapter: blocks are Owned content, re-created in the same hierarchy.
 */
class NeoTest extends Unit
{
    public function testRoundTripsBlocksSeveralLevelsDeepKeepingHierarchyOrderContentAndUids(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);

        $text = Schema::blockType('text', [Schema::plainText('body')]);
        $section = Schema::neoBlockType('section', [
            Schema::plainText('heading'),
            Schema::field(Entries::class, 'relatedTopics'),
            Schema::matrix('items', [$text]),
        ]);
        $paragraph = Schema::neoBlockType('paragraph', [Schema::plainText('copy')]);
        $content = Schema::neo('content', [$section, $paragraph]);
        $article = Schema::entryType('article', [$content]);
        $news = Schema::section('news', [$article]);

        $source = Schema::entry($news, $article, [
            'title' => 'Nested',
            'content' => [
                ['type' => 'section', 'level' => 1, 'fields' => [
                    'heading' => 'One',
                    'relatedTopics' => [$craft->id],
                    'items' => ['new1' => ['type' => 'text', 'fields' => ['body' => 'In matrix']]],
                ]],
                ['type' => 'section', 'level' => 2, 'fields' => ['heading' => 'One.A']],
                ['type' => 'paragraph', 'level' => 3, 'fields' => ['copy' => 'Deep']],
                ['type' => 'paragraph', 'level' => 3, 'enabled' => false, 'fields' => ['copy' => 'Deep, disabled']],
                ['type' => 'paragraph', 'level' => 2, 'fields' => ['copy' => 'One.B']],
                ['type' => 'section', 'level' => 1, 'fields' => ['heading' => 'Two']],
            ],
        ]);
        $sourceBlocks = $this->blocks($source, 'content');
        $this->assertCount(6, $sourceBlocks);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);

        $result = $this->portage()->import($payload);

        $this->assertSame([], $result->roots[0]->errors ?? [], 'errors');
        $this->assertSame([], $result->roots[0]->warnings);
        $blocks = $this->blocks($this->importedEntry($result->roots[0]->elementId), 'content');
        $this->assertCount(6, $blocks);
        $this->assertSame(
            [
                ['section', 1, true],
                ['section', 2, true],
                ['paragraph', 3, true],
                ['paragraph', 3, false],
                ['paragraph', 2, true],
                ['section', 1, true],
            ],
            array_map(fn(Block $block) => [$block->getType()->handle, (int)$block->level, $block->enabled], $blocks),
        );
        $this->assertSame(
            ['One', 'One.A', null, null, null, 'Two'],
            array_map(fn(Block $block) => $block->getType()->handle === 'section' ? $block->getFieldValue('heading') : null, $blocks),
        );
        $this->assertSame(
            ['Deep', 'Deep, disabled', 'One.B'],
            array_map(fn(Block $block) => $block->getFieldValue('copy'), [$blocks[2], $blocks[3], $blocks[4]]),
        );
        $this->assertSame([$craft->id], $blocks[0]->getFieldValue('relatedTopics')->ids());
        $this->assertSame(
            ['In matrix'],
            array_map(fn(Entry $entry) => $entry->getFieldValue('body'), $blocks[0]->getFieldValue('items')->status(null)->all()),
        );
        $this->assertSame(array_map(fn(Block $block) => $block->uid, $sourceBlocks), array_map(fn(Block $block) => $block->uid, $blocks));
    }

    public function testSkipsABlockWhoseTypeIsMissingWithItsChildrenAndImportsItsSiblings(): void
    {
        $quote = Schema::neoBlockType('quote', [Schema::plainText('quoteText')]);
        $paragraph = Schema::neoBlockType('paragraph', [Schema::plainText('copy')]);
        $content = Schema::neo('content', [$quote, $paragraph]);
        $article = Schema::entryType('article', [$content]);
        $news = Schema::section('news', [$article]);

        $source = Schema::entry($news, $article, [
            'title' => 'Skips',
            'content' => [
                ['type' => 'paragraph', 'level' => 1, 'fields' => ['copy' => 'Before']],
                ['type' => 'quote', 'level' => 1, 'fields' => ['quoteText' => 'Gone']],
                ['type' => 'paragraph', 'level' => 2, 'fields' => ['copy' => 'Child of gone']],
                ['type' => 'paragraph', 'level' => 3, 'fields' => ['copy' => 'Grandchild of gone']],
                ['type' => 'paragraph', 'level' => 1, 'fields' => ['copy' => 'Sibling']],
                ['type' => 'paragraph', 'level' => 2, 'fields' => ['copy' => 'Child of sibling']],
            ],
        ]);
        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteNeoBlockType($content, 'quote');

        $plan = $this->portage()->preview($payload);
        $this->assertSame('missing', $plan->drift->get('neoBlockTypes', $quote->uid)?->state);

        $result = $this->portage()->import($payload);

        $this->assertSame([], $result->roots[0]->errors ?? [], 'errors');
        $this->assertContains(
            'A block of type "Quote" in "Content" will be skipped, with the blocks nested under it: its block type doesn\'t exist in that field in this environment.',
            $result->roots[0]->warnings,
        );
        $blocks = $this->blocks($this->importedEntry($result->roots[0]->elementId), 'content');
        $this->assertSame(
            [['Before', 1], ['Sibling', 1], ['Child of sibling', 2]],
            array_map(fn(Block $block) => [$block->getFieldValue('copy'), (int)$block->level], $blocks),
        );
    }

    public function testImportsABlockOfADriftedBlockTypeWithAWarning(): void
    {
        $paragraph = Schema::neoBlockType('paragraph', [Schema::plainText('copy')]);
        $content = Schema::neo('content', [$paragraph]);
        $article = Schema::entryType('article', [$content]);
        $news = Schema::section('news', [$article]);

        $source = Schema::entry($news, $article, [
            'title' => 'Drift',
            'content' => [['type' => 'paragraph', 'level' => 1, 'fields' => ['copy' => 'Kept']]],
        ]);
        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::updateNeoBlockType($content, 'paragraph', ['description' => 'Changed in the Target environment']);

        $plan = $this->portage()->preview($payload);
        $this->assertSame('drifted', $plan->drift->get('neoBlockTypes', $paragraph->uid)?->state);

        $result = $this->portage()->import($payload);

        $this->assertSame([], $result->roots[0]->errors ?? [], 'errors');
        $this->assertSame(
            ['The Neo block type "Paragraph" has different settings in this environment than where it was exported. Its content will be imported, but check it.'],
            $result->roots[0]->warnings,
        );
        $blocks = $this->blocks($this->importedEntry($result->roots[0]->elementId), 'content');
        $this->assertSame(['Kept'], array_map(fn(Block $block) => $block->getFieldValue('copy'), $blocks));
    }

    public function testGeneratesNewUidsForBlocksWhoseUidsAreTaken(): void
    {
        $paragraph = Schema::neoBlockType('paragraph', [Schema::plainText('copy')]);
        $content = Schema::neo('content', [$paragraph]);
        $article = Schema::entryType('article', [$content]);
        $news = Schema::section('news', [$article]);

        $source = Schema::entry($news, $article, [
            'title' => 'Collide',
            'content' => [['type' => 'paragraph', 'level' => 1, 'fields' => ['copy' => 'Same']]],
        ]);
        $sourceUid = $this->blocks($source, 'content')[0]->uid;
        $payload = $this->portage()->export([$source]);

        // The source entry still exists, so the Import creates a new entry whose block can't keep the UID.
        $result = $this->portage()->import($payload);

        $this->assertSame([], $result->roots[0]->errors ?? [], 'errors');
        $blocks = $this->blocks($this->importedEntry($result->roots[0]->elementId), 'content');
        $this->assertCount(1, $blocks);
        $this->assertNotSame($sourceUid, $blocks[0]->uid);
        $this->assertSame('Same', $blocks[0]->getFieldValue('copy'));
    }

    public function testBlocksKeepTheirContentAndHierarchyForEachSite(): void
    {
        $german = Schema::site('german', ['language' => 'de']);
        $paragraph = Schema::neoBlockType('paragraph', [
            Schema::plainText('copy', ['translationMethod' => Field::TRANSLATION_METHOD_SITE]),
            Schema::plainText('anchor'),
        ]);
        $content = Schema::neo('content', [$paragraph]);
        $page = Schema::entryType('page', [$content]);
        $pages = Schema::section('pages', [$page]);
        $source = Schema::entry($pages, $page, [
            'title' => 'About',
            'content' => [
                ['type' => 'paragraph', 'level' => 1, 'fields' => ['copy' => 'Intro', 'anchor' => 'intro']],
                ['type' => 'paragraph', 'level' => 2, 'fields' => ['copy' => 'Detail', 'anchor' => 'detail']],
                ['type' => 'paragraph', 'level' => 1, 'fields' => ['copy' => 'Outro', 'anchor' => 'outro']],
            ],
        ]);
        $germanBlocks = $this->blocks($this->entryIn($source->id, $german), 'content');
        Schema::translate($source, $german, ['content' => [
            'blocks' => [
                $germanBlocks[0]->id => ['fields' => ['copy' => 'Einleitung']],
                $germanBlocks[1]->id => ['fields' => ['copy' => 'Einzelheit']],
                $germanBlocks[2]->id => ['fields' => ['copy' => 'Schluss']],
            ],
            'sortOrder' => array_map(fn(Block $block) => $block->id, $germanBlocks),
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        $result = $this->portage()->import($payload);

        $this->assertSame([], $result->roots[0]->errors ?? [], 'errors');
        $id = $result->roots[0]->elementId;
        $english = $this->blocks($this->entryIn($id, Craft::$app->getSites()->getPrimarySite()), 'content');
        $translated = $this->blocks($this->entryIn($id, $german), 'content');
        $this->assertSame([['Intro', 1], ['Detail', 2], ['Outro', 1]], $this->copyAndLevel($english));
        $this->assertSame([['Einleitung', 1], ['Einzelheit', 2], ['Schluss', 1]], $this->copyAndLevel($translated));
        $this->assertSame(['intro', 'detail', 'outro'], array_map(fn(Block $block) => $block->getFieldValue('anchor'), $translated));
        $this->assertSame(array_map(fn(Block $block) => $block->id, $english), array_map(fn(Block $block) => $block->id, $translated));
    }

    public function testFieldsThatDontPropagateKeepEachSitesOwnBlocks(): void
    {
        $german = Schema::site('german', ['language' => 'de']);
        $paragraph = Schema::neoBlockType('paragraph', [Schema::plainText('copy')]);
        $content = Schema::neo('content', [$paragraph], ['propagationMethod' => PropagationMethod::None]);
        $page = Schema::entryType('page', [$content]);
        $pages = Schema::section('pages', [$page]);
        $source = Schema::entry($pages, $page, [
            'title' => 'About',
            'content' => [
                ['type' => 'paragraph', 'level' => 1, 'fields' => ['copy' => 'Intro']],
                ['type' => 'paragraph', 'level' => 2, 'fields' => ['copy' => 'Detail']],
            ],
        ]);
        Schema::translate($source, $german, ['content' => [
            ['type' => 'paragraph', 'level' => 1, 'fields' => ['copy' => 'Nur auf Deutsch']],
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        $result = $this->portage()->import($payload);

        $this->assertSame([], $result->roots[0]->errors ?? [], 'errors');
        $id = $result->roots[0]->elementId;
        $english = $this->blocks($this->entryIn($id, Craft::$app->getSites()->getPrimarySite()), 'content');
        $translated = $this->blocks($this->entryIn($id, $german), 'content');
        $this->assertSame([['Intro', 1], ['Detail', 2]], $this->copyAndLevel($english));
        $this->assertSame([['Nur auf Deutsch', 1]], $this->copyAndLevel($translated));
    }

    public function testOverwriteReplacesANeoFieldsBlocksAsAWholeOnTheDraft(): void
    {
        $paragraph = Schema::neoBlockType('paragraph', [Schema::plainText('copy')]);
        $content = Schema::neo('content', [$paragraph]);
        $article = Schema::entryType('article', [$content]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, [
            'title' => 'Page',
            'content' => [
                ['type' => 'paragraph', 'level' => 1, 'fields' => ['copy' => 'First']],
                ['type' => 'paragraph', 'level' => 2, 'fields' => ['copy' => 'Second']],
            ],
        ]);
        $payload = $this->portage()->export([$source]);
        $blockIds = array_map(fn(Block $block) => $block->id, $this->blocks($source, 'content'));
        Schema::updateEntry($source, ['content' => [
            'blocks' => [
                $blockIds[0] => [],
                $blockIds[1] => [],
                'new3' => ['type' => 'paragraph', 'level' => 1, 'fields' => ['copy' => 'Prod only']],
            ],
            'sortOrder' => [...$blockIds, 'new3'],
        ]]);
        $canonical = Entry::find()->id($source->id)->status(null)->one();
        $this->assertSame([['First', 1], ['Second', 2], ['Prod only', 1]], $this->copyAndLevel($this->blocks($canonical, 'content')));

        $result = $this->portage()->import($payload, [], [$source->uid => ['mode' => 'overwrite']]);

        $this->assertSame('overwritten', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $draft = Entry::find()->draftId($result->roots[0]->draftId)->status(null)->one();
        $this->assertSame([['First', 1], ['Second', 2]], $this->copyAndLevel($this->blocks($draft, 'content')));
        $untouched = Entry::find()->id($source->id)->status(null)->one();
        $this->assertSame([['First', 1], ['Second', 2], ['Prod only', 1]], $this->copyAndLevel($this->blocks($untouched, 'content')));
    }

    /**
     * @param list<Block> $blocks
     * @return list<array{mixed, int}>
     */
    private function copyAndLevel(array $blocks): array
    {
        return array_map(fn(Block $block) => [$block->getFieldValue('copy'), (int)$block->level], $blocks);
    }

    private function entryIn(?int $id, Site $site): Entry
    {
        $entry = Entry::find()->id($id)->siteId($site->id)->status(null)->one();
        $this->assertNotNull($entry, "No entry with ID $id in the site \"$site->handle\"");

        return $entry;
    }

    /**
     * @return list<Block>
     */
    private function blocks(Entry $entry, string $handle): array
    {
        return array_values($entry->getFieldValue($handle)->status(null)->all());
    }

    private function portage(): Portage
    {
        return Plugin::getInstance()->getPortage();
    }

    private function importedEntry(?int $id): Entry
    {
        $this->assertNotNull($id, 'The Import result has no element ID');
        $entry = Entry::find()->id($id)->status(null)->one();
        $this->assertNotNull($entry, "No entry with ID $id");

        return $entry;
    }
}
