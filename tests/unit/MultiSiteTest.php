<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\base\Field;
use craft\elements\Entry;
use craft\enums\PropagationMethod;
use craft\models\Site;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\tests\support\Schema;

/**
 * Multi-site content: Root elements and their Owned content travel with their content for every site they exist in.
 */
class MultiSiteTest extends Unit
{
    private Site $english;

    private Site $german;

    protected function _before(): void
    {
        $this->english = Craft::$app->getSites()->getPrimarySite();
        $this->german = Schema::site('german', ['language' => 'de']);
    }

    public function testKeepsDifferentTranslatableValuesOnEachSite(): void
    {
        $article = Schema::entryType('article', [Schema::plainText('summary', ['translationMethod' => Field::TRANSLATION_METHOD_SITE])]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Hello', 'slug' => 'hello', 'summary' => 'In English']);
        Schema::translate($source, $this->german, ['title' => 'Hallo', 'slug' => 'hallo', 'summary' => 'Auf Deutsch']);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        $result = $this->portage()->import($payload);

        $this->assertSame([], $result->roots[0]->errors);
        $id = $result->roots[0]->elementId;
        $english = $this->importedEntry($id, $this->english);
        $german = $this->importedEntry($id, $this->german);
        $this->assertSame(['Hello', 'hello', 'In English'], [$english->title, $english->slug, $english->getFieldValue('summary')]);
        $this->assertSame(['Hallo', 'hallo', 'Auf Deutsch'], [$german->title, $german->slug, $german->getFieldValue('summary')]);
    }

    public function testRecordsNonTranslatableValuesOnceAndImportsThemOnEverySite(): void
    {
        $code = Schema::plainText('productCode');
        $summary = Schema::plainText('summary', ['translationMethod' => Field::TRANSLATION_METHOD_SITE]);
        $product = Schema::entryType('product', [$code, $summary]);
        $products = Schema::section('products', [$product]);
        $source = Schema::entry($products, $product, ['title' => 'Kettle', 'productCode' => 'K-100', 'summary' => 'Boils water']);
        Schema::translate($source, $this->german, ['title' => 'Wasserkocher', 'summary' => 'Kocht Wasser']);

        $payload = $this->portage()->export([$source]);
        $root = $this->decode($payload)['roots'][0];
        $this->assertSame([$code->uid => 'K-100'], $root['fields']);
        $this->assertSame([$summary->uid => 'Boils water'], $root['sites'][$this->english->uid]['fields']);
        $this->assertSame([$summary->uid => 'Kocht Wasser'], $root['sites'][$this->german->uid]['fields']);

        $this->hardDelete($source);
        $id = $this->portage()->import($payload)->roots[0]->elementId;

        $this->assertSame('K-100', $this->importedEntry($id, $this->english)->getFieldValue('productCode'));
        $this->assertSame('K-100', $this->importedEntry($id, $this->german)->getFieldValue('productCode'));
        $this->assertSame('Kocht Wasser', $this->importedEntry($id, $this->german)->getFieldValue('summary'));
    }

    public function testSkipsASiteMissingInTheTargetEnvironmentWithAWarningAndImportsTheOthers(): void
    {
        $french = Schema::site('french', ['language' => 'fr']);
        $article = Schema::entryType('article', [Schema::plainText('summary', ['translationMethod' => Field::TRANSLATION_METHOD_SITE])]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Hello', 'summary' => 'In English']);
        Schema::translate($source, $this->german, ['title' => 'Hallo', 'summary' => 'Auf Deutsch']);
        Schema::translate($source, $french, ['title' => 'Bonjour', 'summary' => 'En français']);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        Schema::deleteSite($this->german);
        $result = $this->portage()->import($payload);

        $root = $result->roots[0];
        $this->assertSame('created', $root->status);
        $this->assertContains('The site "German" doesn\'t exist in this environment. Content for that site will be skipped.', $root->warnings);
        $this->assertSame('In English', $this->importedEntry($root->elementId, $this->english)->getFieldValue('summary'));
        $this->assertSame('En français', $this->importedEntry($root->elementId, $french)->getFieldValue('summary'));
    }

    public function testSkipsASiteTheSectionIsntEnabledForWithAWarning(): void
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Hello']);
        Schema::translate($source, $this->german, ['title' => 'Hallo']);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        Schema::disableSectionForSite($news, $this->german);
        $result = $this->portage()->import($payload);

        $root = $result->roots[0];
        $this->assertSame('created', $root->status);
        $this->assertContains('The "News" section isn\'t enabled for the site "German" in this environment. Content for that site will be skipped.', $root->warnings);
        $this->assertSame('Hello', $this->importedEntry($root->elementId, $this->english)->title);
        $this->assertNull(Entry::find()->id($root->elementId)->siteId($this->german->id)->status(null)->one());
    }

    public function testMatrixEntriesKeepTheirContentForEachSite(): void
    {
        $text = Schema::blockType('text', [
            Schema::plainText('body', ['translationMethod' => Field::TRANSLATION_METHOD_SITE]),
            Schema::plainText('anchor'),
        ]);
        $page = Schema::entryType('page', [Schema::matrix('contentBlocks', [$text])]);
        $pages = Schema::section('pages', [$page]);
        $source = Schema::entry($pages, $page, [
            'title' => 'About',
            'contentBlocks' => [
                'new1' => ['type' => 'text', 'fields' => ['body' => 'Intro', 'anchor' => 'intro']],
                'new2' => ['type' => 'text', 'fields' => ['body' => 'Outro', 'anchor' => 'outro']],
            ],
        ]);
        $germanBlocks = $this->blocks($this->importedEntry($source->id, $this->german), 'contentBlocks');
        Schema::translate($source, $this->german, ['contentBlocks' => [
            'entries' => [
                $germanBlocks[0]->id => ['fields' => ['body' => 'Einleitung']],
                $germanBlocks[1]->id => ['fields' => ['body' => 'Schluss']],
            ],
            'sortOrder' => [$germanBlocks[0]->id, $germanBlocks[1]->id],
        ]]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        $result = $this->portage()->import($payload);

        $this->assertSame([], $result->roots[0]->errors);
        $id = $result->roots[0]->elementId;
        $english = $this->blocks($this->importedEntry($id, $this->english), 'contentBlocks');
        $german = $this->blocks($this->importedEntry($id, $this->german), 'contentBlocks');
        $this->assertSame(['Intro', 'Outro'], array_map(fn(Entry $block) => $block->getFieldValue('body'), $english));
        $this->assertSame(['Einleitung', 'Schluss'], array_map(fn(Entry $block) => $block->getFieldValue('body'), $german));
        $this->assertSame(['intro', 'outro'], array_map(fn(Entry $block) => $block->getFieldValue('anchor'), $german));
        $this->assertSame(
            array_map(fn(Entry $block) => $block->id, $english),
            array_map(fn(Entry $block) => $block->id, $german),
        );
    }

    public function testMatrixEntriesWhoseUidsAreTakenStillGetTheirContentForEachSite(): void
    {
        $text = Schema::blockType('text', [Schema::plainText('body', ['translationMethod' => Field::TRANSLATION_METHOD_SITE])]);
        $page = Schema::entryType('page', [Schema::matrix('contentBlocks', [$text])]);
        $pages = Schema::section('pages', [$page]);
        $source = Schema::entry($pages, $page, [
            'title' => 'About',
            'contentBlocks' => ['new1' => ['type' => 'text', 'fields' => ['body' => 'Intro']]],
        ]);
        $germanBlock = $this->blocks($this->importedEntry($source->id, $this->german), 'contentBlocks')[0];
        Schema::translate($source, $this->german, ['contentBlocks' => [
            'entries' => [$germanBlock->id => ['fields' => ['body' => 'Einleitung']]],
            'sortOrder' => [$germanBlock->id],
        ]]);

        $result = $this->portage()->import($this->portage()->export([$source]));

        $this->assertSame([], $result->roots[0]->errors);
        $id = $result->roots[0]->elementId;
        $english = $this->blocks($this->importedEntry($id, $this->english), 'contentBlocks');
        $german = $this->blocks($this->importedEntry($id, $this->german), 'contentBlocks');
        $this->assertNotSame($germanBlock->uid, $english[0]->uid);
        $this->assertSame(['Intro'], array_map(fn(Entry $block) => $block->getFieldValue('body'), $english));
        $this->assertSame(['Einleitung'], array_map(fn(Entry $block) => $block->getFieldValue('body'), $german));
    }

    public function testMatrixFieldsThatDontPropagateKeepEachSitesOwnEntries(): void
    {
        $text = Schema::blockType('text', [Schema::plainText('body')]);
        $page = Schema::entryType('page', [Schema::matrix('contentBlocks', [$text], ['propagationMethod' => PropagationMethod::None])]);
        $pages = Schema::section('pages', [$page]);
        $source = Schema::entry($pages, $page, [
            'title' => 'About',
            'contentBlocks' => [
                'new1' => ['type' => 'text', 'fields' => ['body' => 'Intro']],
                'new2' => ['type' => 'text', 'fields' => ['body' => 'Outro']],
            ],
        ]);
        Schema::translate($source, $this->german, ['contentBlocks' => [
            'new1' => ['type' => 'text', 'fields' => ['body' => 'Nur auf Deutsch']],
        ]]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        $result = $this->portage()->import($payload);

        $this->assertSame([], $result->roots[0]->errors);
        $id = $result->roots[0]->elementId;
        $english = $this->blocks($this->importedEntry($id, $this->english), 'contentBlocks');
        $german = $this->blocks($this->importedEntry($id, $this->german), 'contentBlocks');
        $this->assertSame(['Intro', 'Outro'], array_map(fn(Entry $block) => $block->getFieldValue('body'), $english));
        $this->assertSame(['Nur auf Deutsch'], array_map(fn(Entry $block) => $block->getFieldValue('body'), $german));
    }

    public function testCreatesAnEntryFromOneSiteOnlyInThatSite(): void
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article], ['propagationMethod' => PropagationMethod::Custom]);
        $source = Schema::entry($news, $article, ['title' => 'English only']);
        $this->assertNull(Entry::find()->id($source->id)->siteId($this->german->id)->status(null)->one());

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        $result = $this->portage()->import($payload);

        $root = $result->roots[0];
        $this->assertSame([], $root->warnings);
        $this->assertSame('English only', $this->importedEntry($root->elementId, $this->english)->title);
        $this->assertNull(Entry::find()->id($root->elementId)->siteId($this->german->id)->status(null)->one());
    }

    public function testWarnsWhenTheSectionPropagatesAnEntryToSitesItDidntExistIn(): void
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article], ['propagationMethod' => PropagationMethod::Custom]);
        $source = Schema::entry($news, $article, ['title' => 'English only']);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        Schema::updateSection($news, ['propagationMethod' => PropagationMethod::All]);
        $result = $this->portage()->import($payload);

        $root = $result->roots[0];
        $this->assertContains(
            'The "News" section propagates entries to the site "German" in this environment, so the entry will be created there too, with the content from "Craft test site".',
            $root->warnings,
        );
        $this->assertSame('English only', $this->importedEntry($root->elementId, $this->german)->title);
    }

    /**
     * @return list<Entry>
     */
    private function blocks(Entry $owner, string $fieldHandle): array
    {
        /** @var \craft\elements\db\EntryQuery $query */
        $query = $owner->getFieldValue($fieldHandle);

        return (clone $query)->status(null)->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $payload): array
    {
        return json_decode(gzdecode(base64_decode(substr($payload, strlen('portage:v1:')), true)), true, 512, JSON_THROW_ON_ERROR);
    }

    private function portage(): Portage
    {
        return Plugin::getInstance()->getPortage();
    }

    private function hardDelete(Entry $entry): void
    {
        Craft::$app->getElements()->deleteElement($entry, true);
    }

    private function importedEntry(?int $id, Site $site): Entry
    {
        $this->assertNotNull($id, 'The Import result has no element ID');
        $entry = Entry::find()->id($id)->siteId($site->id)->status(null)->one();
        $this->assertNotNull($entry, "No entry with ID $id in the site \"$site->handle\"");

        return $entry;
    }
}
