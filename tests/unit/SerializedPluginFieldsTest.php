<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\helpers\Json;
use craft\models\EntryType;
use craft\models\Section;
use nystudio107\codefield\fields\Code;
use nystudio107\codefield\models\CodeData;
use rias\positionfieldtype\fields\Position;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\tests\support\Requires;
use simplygoodwork\craftportage\tests\support\Schema;
use verbb\tablemaker\fields\TableMakerField;
use verbb\tablemaker\models\TableMakerData;

/**
 * Position, Code and TableMaker fields hold plain values, which travel as the field serializes them, with no warning.
 */
class SerializedPluginFieldsTest extends Unit
{
    public function testAPositionRoundTrips(): void
    {
        $field = Schema::field(Position::class, 'alignment', ['name' => 'Alignment', 'options' => ['left' => '1', 'center' => '1', 'right' => '1'], 'default' => 'left']);
        [$news, $article] = $this->newsWith($field);
        $source = Schema::entry($news, $article, ['title' => 'Hero', 'alignment' => 'center']);

        $imported = $this->roundTrip($source, $field->uid, 'center');

        $this->assertSame('center', $imported->getFieldValue('alignment'));
    }

    public function testCodeRoundTripsWithItsLanguage(): void
    {
        $field = Schema::field(Code::class, 'embed', ['name' => 'Embed', 'availableLanguages' => ['html', 'javascript']]);
        [$news, $article] = $this->newsWith($field);
        $source = Schema::entry($news, $article, ['title' => 'Hero', 'embed' => ['value' => '<iframe src="https://example.com"></iframe>', 'language' => 'html']]);

        $imported = $this->roundTrip($source, $field->uid, ['value' => '<iframe src="https://example.com"></iframe>', 'language' => 'html']);

        $code = $imported->getFieldValue('embed');
        $this->assertInstanceOf(CodeData::class, $code);
        $this->assertSame('<iframe src="https://example.com"></iframe>', $code->value);
        $this->assertSame('html', $code->language);
    }

    public function testATableRoundTripsWithItsColumnsRowsAndCaption(): void
    {
        $field = Schema::field(TableMakerField::class, 'data', ['name' => 'Table Data']);
        [$news, $article] = $this->newsWith($field);
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
        $stored = $field->serializeValue($source->getFieldValue('data'), $source);

        $imported = $this->roundTrip($source, $field->uid, $stored);

        $this->assertSame($stored, $field->serializeValue($imported->getFieldValue('data'), $imported));
        $this->assertStringContainsString('Cake', Json::encode($stored));
    }

    public function testATableWithElementLinksInRichTextCellsIsImportedWithAWarning(): void
    {
        Requires::package('verbb/tablemaker', '>=5.1.1', 'Element links in rich text cells');
        $field = Schema::field(TableMakerField::class, 'data', ['name' => 'Table Data']);
        [$news, $article] = $this->newsWith($field);
        $link = '<p><a href="https://example.com/news/hello#entry:12@1:url">Hello</a></p>';
        $source = Schema::entry($news, $article, ['title' => 'Links', 'data' => [
            'columns' => ['col1' => ['heading' => 'Story', 'type' => 'richtext']],
            'rows' => ['row1' => ['col1' => $link]],
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        $result = $this->portage()->import($payload);

        $this->assertSame(
            ['The field "Table Data" has rich text cells that link to entries, categories or assets, which Portage can\'t translate. They will be imported as they are, and the links may point at different elements in this environment.'],
            $result->roots[0]->warnings,
        );
        $table = Entry::find()->id($result->roots[0]->elementId)->status(null)->one()?->getFieldValue('data');
        $this->assertInstanceOf(TableMakerData::class, $table);
        $this->assertSame(['row1' => ['col1' => $link]], $table->rowsArray());
    }

    public function testATableWithoutRichTextLinksHasNoWarning(): void
    {
        $field = Schema::field(TableMakerField::class, 'data', ['name' => 'Table Data']);
        [$news, $article] = $this->newsWith($field);
        // An entry ID in a plain text cell is only text: TableMaker never turns it into a link.
        $source = Schema::entry($news, $article, ['title' => 'Notes', 'data' => [
            'columns' => ['col1' => ['heading' => 'Note', 'type' => 'singleline']],
            'rows' => ['row1' => ['col1' => 'See {entry:12:url}']],
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);

        $this->assertSame([], $this->portage()->import($payload)->roots[0]->warnings);
    }

    /**
     * Exports the entry, checks the Payload carries the expected value with no warning, deletes the entry and imports
     * it again, with no warning.
     */
    private function roundTrip(Entry $source, string $fieldUid, mixed $expected): Entry
    {
        $payload = $this->portage()->export([$source]);
        $preview = $this->portage()->preview($payload);
        $this->assertSame($expected, $preview->envelope['roots'][0]['fields'][$fieldUid] ?? null);
        $this->assertSame([], $preview->roots[0]->warnings);
        Schema::deleteElement($source);

        $result = $this->portage()->import($payload);

        $this->assertSame([], $result->roots[0]->warnings);
        $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $this->assertNotNull($imported);

        return $imported;
    }

    /**
     * @return array{0: Section, 1: EntryType}
     */
    private function newsWith(FieldInterface $field): array
    {
        $article = Schema::entryType('article', [$field]);

        return [Schema::section('news', [$article]), $article];
    }

    private function portage(): Portage
    {
        return Plugin::getInstance()->getPortage();
    }
}
