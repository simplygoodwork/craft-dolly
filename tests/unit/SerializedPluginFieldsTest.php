<?php

namespace simplygoodwork\craftdolly\tests\unit;

use Codeception\Test\Unit;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use nystudio107\codefield\fields\Code;
use nystudio107\codefield\models\CodeData;
use rias\positionfieldtype\fields\Position;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\services\Dolly;
use simplygoodwork\craftdolly\tests\support\Schema;

/**
 * Position and Code fields hold plain values, which travel as the field serializes them, with no warning.
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

    /**
     * Exports the entry, checks the Payload carries the expected value with no warning, deletes the entry and imports
     * it again, with no warning.
     */
    private function roundTrip(Entry $source, string $fieldUid, mixed $expected): Entry
    {
        $payload = $this->dolly()->export([$source]);
        $preview = $this->dolly()->preview($payload);
        $this->assertSame($expected, $preview->envelope['roots'][0]['fields'][$fieldUid] ?? null);
        $this->assertSame([], $preview->roots[0]->warnings);
        Schema::deleteElement($source);

        $result = $this->dolly()->import($payload);

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

    private function dolly(): Dolly
    {
        return Plugin::getInstance()->getDolly();
    }
}
