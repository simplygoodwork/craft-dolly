<?php

namespace simplygoodwork\craftdolly\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use craft\helpers\Json;
use craft\models\EntryType;
use craft\models\Section;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\services\Dolly;
use simplygoodwork\craftdolly\tests\support\Requires;
use simplygoodwork\craftdolly\tests\support\Schema;
use verbb\tablemaker\fields\TableMakerField;
use verbb\tablemaker\models\TableMakerData;

/**
 * TableMaker fields move their columns, rows and caption as TableMaker stores them. Links to entries, categories and
 * assets in rich text cells, as link fragments (`#entry:12@1:url`) or reference tags, are Embedded references.
 */
class TableMakerTest extends Unit
{
    private TableMakerField $field;

    protected function _before(): void
    {
        $this->field = Schema::field(TableMakerField::class, 'data', ['name' => 'Table Data']);
    }

    public function testATableRoundTripsWithItsColumnsRowsAndCaption(): void
    {
        [$news, $article] = $this->news();
        $source = Schema::entry($news, $article, ['title' => 'Prices', 'data' => [
            'caption' => 'Prices',
            'columns' => [
                'col1' => ['heading' => 'Item', 'type' => 'singleline'],
                'col2' => ['heading' => 'Price', 'type' => 'number'],
            ],
            'rows' => [
                'row1' => ['col1' => 'Tea', 'col2' => '3'],
                'row2' => ['col1' => 'Cake', 'col2' => '4.5'],
            ],
        ]]);
        // TableMaker's storage format differs between its versions, so the value is compared as the field stores it.
        $stored = $this->storedValueOf($source);

        $payload = $this->dolly()->export([$source]);
        $preview = $this->dolly()->preview($payload);
        $this->assertSame($stored, $preview->envelope['roots'][0]['fields'][$this->field->uid] ?? null);
        $this->assertSame([], $preview->roots[0]->warnings);
        Schema::deleteElement($source);
        $result = $this->dolly()->import($payload);

        $this->assertSame([], $result->roots[0]->warnings);
        $imported = $this->importedEntry($result->roots[0]->elementId);
        $this->assertSame($stored, $this->storedValueOf($imported));
        $this->assertStringContainsString('Cake', Json::encode($stored));
    }

    public function testReferenceTagsInPlainTextCellsAreOnlyText(): void
    {
        [$news, $article] = $this->news();
        // TableMaker only resolves reference tags in rich text cells, so in a plain text cell an entry ID is only text.
        $source = Schema::entry($news, $article, ['title' => 'Notes', 'data' => [
            'columns' => ['col1' => ['heading' => 'Note', 'type' => 'singleline']],
            'rows' => ['row1' => ['col1' => 'See {entry:12:url}']],
        ]]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        $result = $this->dolly()->import($payload);

        $this->assertSame([], $result->roots[0]->warnings);
        $this->assertSame([['See {entry:12:url}']], $this->cellsOf($result->roots[0]->elementId));
    }

    public function testLinksInRichTextCellsPointAtTheElementsTheyResolveToInThisEnvironment(): void
    {
        $this->requireRichText();
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);
        $colours = Schema::categoryGroup('colours');
        $red = Schema::category($colours, ['title' => 'Red', 'slug' => 'red']);
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $siteUid = Craft::$app->getSites()->getPrimarySite()->uid;

        [$news, $article] = $this->news();
        $source = Schema::entry($news, $article, ['title' => 'Links', 'data' => [
            'columns' => ['col1' => ['heading' => 'Story', 'type' => 'richtext'], 'col2' => ['heading' => 'Note', 'type' => 'singleline']],
            'rows' => ['row1' => [
                'col1' => "<p><a href=\"https://example.com/topics/craft?ref=table#entry:$craft->id@$siteId:url\">Craft</a> in <a href=\"https://example.com/colours/red#category:$red->id:url\">red</a>, {entry:$craft->id:title}</p>",
                'col2' => "#entry:$craft->id@$siteId:url",
            ]],
        ]]);

        $payload = $this->dolly()->export([$source]);
        $portable = $this->dolly()->preview($payload)->envelope['roots'][0]['fields'][$this->field->uid];
        $this->assertSame(
            "<p><a href=\"https://example.com/topics/craft?ref=table#entry:$craft->uid@$siteUid:url\">Craft</a> in <a href=\"https://example.com/colours/red#category:$red->uid:url\">red</a>, {entry:$craft->uid:title}</p>",
            Json::decode($portable['rows']['row1']['col1']),
        );
        Schema::deleteElement($source);
        Schema::deleteElement($craft);
        Schema::deleteElement($red);
        $newCraft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);
        $newRed = Schema::category($colours, ['title' => 'Red', 'slug' => 'red']);
        $this->assertNotSame($craft->id, $newCraft->id);

        $result = $this->dolly()->import($payload);

        $this->assertSame([], $result->roots[0]->warnings);
        $this->assertSame(['row1' => [
            'col1' => "<p><a href=\"https://example.com/topics/craft?ref=table#entry:$newCraft->id@$siteId:url\">Craft</a> in <a href=\"https://example.com/colours/red#category:$newRed->id:url\">red</a>, {entry:$newCraft->id:title}</p>",
            'col2' => "#entry:$craft->id@$siteId:url",
        ]], $this->tableOf($result->roots[0]->elementId)->rowsArray());
    }

    public function testAnUnresolvedLinkInARichTextCellIsRemovedAndItsTextKept(): void
    {
        $this->requireRichText();
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone topic', 'slug' => 'gone-topic']);
        $goneUid = $gone->uid;

        [$news, $article] = $this->news();
        $source = Schema::entry($news, $article, ['title' => 'Links', 'data' => [
            'columns' => ['col1' => ['heading' => 'Story', 'type' => 'richtext']],
            'rows' => ['row1' => ['col1' => "<p>See <a href=\"https://example.com/topics/gone-topic#entry:$gone->id:url\">the <em>gone</em> topic</a> and <a href=\"https://example.com/about\">us</a>. {entry:$gone->id:title||Untitled}</p>"]],
        ]]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($gone);

        $this->assertSame('Unresolved: will be skipped', $this->dolly()->preview($payload)->references[$goneUid]->outcome());

        $result = $this->dolly()->import($payload);

        $this->assertSame([
            'The entry "Gone topic" linked in the field "Table Data" doesn\'t exist in this environment. The link will be removed and its text kept.',
            'The entry "Gone topic" referenced in the field "Table Data" doesn\'t exist in this environment. The reference tag will be replaced with its fallback text, or removed.',
        ], $result->roots[0]->warnings);
        $this->assertSame(
            ['row1' => ['col1' => '<p>See the <em>gone</em> topic and <a href="https://example.com/about">us</a>. Untitled</p>']],
            $this->tableOf($result->roots[0]->elementId)->rowsArray(),
        );
    }

    public function testChoosingStubForALinkInARichTextCellPointsTheLinkAtTheStub(): void
    {
        $this->requireRichText();
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone topic', 'slug' => 'gone-topic']);
        $goneUid = $gone->uid;
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        [$news, $article] = $this->news();
        $source = Schema::entry($news, $article, ['title' => 'Links', 'data' => [
            'columns' => ['col1' => ['heading' => 'Story', 'type' => 'richtext']],
            'rows' => ['row1' => ['col1' => "<p><a href=\"https://example.com/topics/gone-topic#entry:$gone->id@$siteId:url\">Gone</a> {entry:$gone->id:title}</p>"]],
        ]]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($gone);

        $result = $this->dolly()->import($payload, [$goneUid => 'stub']);

        $stub = Entry::find()->uid($goneUid)->status(null)->one();
        $this->assertNotNull($stub, 'No Stub with the source UID');
        $this->assertSame([], $result->roots[0]->warnings);
        $this->assertSame(
            ['row1' => ['col1' => "<p><a href=\"https://example.com/topics/gone-topic#entry:$stub->id@$siteId:url\">Gone</a> {entry:$stub->id:title}</p>"]],
            $this->tableOf($result->roots[0]->elementId)->rowsArray(),
        );
    }

    public function testALinkToAMissingSiteInARichTextCellIsRemovedAndItsTextKept(): void
    {
        $this->requireRichText();
        $french = Schema::site('french', ['language' => 'fr']);
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);

        [$news, $article] = $this->news();
        $source = Schema::entry($news, $article, ['title' => 'Links', 'data' => [
            'columns' => ['col1' => ['heading' => 'Story', 'type' => 'richtext']],
            'rows' => ['row1' => ['col1' => "<p><a href=\"https://example.com/fr/craft#entry:$craft->id@$french->id:url\">Craft</a></p>"]],
        ]]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteSite($french);

        $result = $this->dolly()->import($payload);

        $this->assertContains(
            'The site "French" in a link to the entry "Craft" in the field "Table Data" doesn\'t exist in this environment. The link will be removed and its text kept.',
            $result->roots[0]->warnings,
        );
        $this->assertSame(['row1' => ['col1' => '<p>Craft</p>']], $this->tableOf($result->roots[0]->elementId)->rowsArray());
    }

    /**
     * Rich text cells, and links in them, need TableMaker 5.1.1+, which offers them only with CKEditor 5.
     */
    private function requireRichText(): void
    {
        Requires::package('verbb/tablemaker', '>=5.1.1', 'Element links in rich text cells');
        Requires::package('craftcms/ckeditor', '>=5.0', 'TableMaker rich text cells');
    }

    /**
     * @return array{0: Section, 1: EntryType}
     */
    private function news(): array
    {
        $article = Schema::entryType('article', [$this->field]);

        return [Schema::section('news', [$article]), $article];
    }

    /**
     * The table value as it's saved in the content table. TableMaker 5.0 keeps the table rendered as HTML (Twig
     * Markup) in its value, which is saved as a string.
     *
     * @return array<string, mixed>
     */
    private function storedValueOf(Entry $entry): array
    {
        return Json::decode(Json::encode($this->field->serializeValue($entry->getFieldValue('data'), $entry)));
    }

    /**
     * The cells of an imported table, row by row, whatever the TableMaker version: 5.0 has no TableMakerData, and
     * keys its rows and cells by position once saved.
     *
     * @return list<list<mixed>>
     */
    private function cellsOf(?int $entryId): array
    {
        $table = $this->importedEntry($entryId)->getFieldValue('data');
        $rows = $table instanceof TableMakerData ? $table->rowsArray() : ($table['rows'] ?? null);
        $this->assertIsArray($rows);

        return array_values(array_map(fn(array $row) => array_values($row), $rows));
    }

    private function tableOf(?int $entryId): TableMakerData
    {
        $table = $this->importedEntry($entryId)->getFieldValue('data');
        $this->assertInstanceOf(TableMakerData::class, $table);

        return $table;
    }

    private function importedEntry(?int $id): Entry
    {
        $entry = Entry::find()->id($id)->status(null)->one();
        $this->assertNotNull($entry);

        return $entry;
    }

    private function dolly(): Dolly
    {
        return Plugin::getInstance()->getDolly();
    }
}
