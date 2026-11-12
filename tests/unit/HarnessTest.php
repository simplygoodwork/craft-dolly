<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\tests\support\Schema;

class HarnessTest extends Unit
{
    public function testPortageIsInstalled(): void
    {
        $this->assertTrue(Craft::$app->getPlugins()->isPluginInstalled('portage'));
        $this->assertInstanceOf(Plugin::class, Plugin::getInstance());
    }

    public function testSavesAndReadsBackAnEntryInABuiltSection(): void
    {
        $entryType = Schema::entryType('article', [
            Schema::plainText('summary'),
        ]);
        $section = Schema::section('news', [$entryType]);

        $entry = Schema::entry($section, $entryType, [
            'title' => 'Hello Portage',
            'slug' => 'hello-portage',
            'summary' => 'A short summary',
        ]);

        $found = Entry::find()->section('news')->slug('hello-portage')->one();

        $this->assertNotNull($found);
        $this->assertSame($entry->id, $found->id);
        $this->assertSame('Hello Portage', $found->title);
        $this->assertSame('article', $found->getType()->handle);
        $this->assertSame('A short summary', $found->getFieldValue('summary'));
    }

    public function testSavesAndReadsBackMatrixBlocks(): void
    {
        $textBlock = Schema::blockType('textBlock', [Schema::plainText('body')]);
        $contentBlocks = Schema::matrix('contentBlocks', [$textBlock]);
        $page = Schema::entryType('page', [$contentBlocks]);
        // Reuses the "news" handle from the test above: schema must not leak between tests.
        $section = Schema::section('news', [$page]);

        $entry = Schema::entry($section, $page, [
            'title' => 'With blocks',
            'contentBlocks' => [
                'new1' => ['type' => 'textBlock', 'fields' => ['body' => 'First']],
                'new2' => ['type' => 'textBlock', 'fields' => ['body' => 'Second']],
            ],
        ]);

        $found = Entry::find()->id($entry->id)->one();
        $blocks = $found->getFieldValue('contentBlocks')->all();

        $this->assertCount(2, $blocks);
        $this->assertSame(['textBlock', 'textBlock'], array_map(fn(Entry $block) => $block->getType()->handle, $blocks));
        $this->assertSame(['First', 'Second'], array_map(fn(Entry $block) => $block->getFieldValue('body'), $blocks));
        $this->assertSame($entry->id, $blocks[0]->getOwnerId());
    }
}
