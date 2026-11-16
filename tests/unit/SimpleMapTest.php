<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use craft\db\Query;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use ether\simplemap\fields\MapField;
use ether\simplemap\models\Map;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\tests\support\Schema;

/**
 * SimpleMap: the location travels without the Source environment's record, owner, site and field IDs, and the Import's
 * element save writes SimpleMap's own record for the imported entry.
 */
class SimpleMapTest extends Unit
{
    private const LOCATION = [
        'lat' => 39.961176,
        'lng' => -82.998794,
        'zoom' => 12,
        'address' => '1 Capitol Square, Columbus, OH',
        'parts' => ['number' => '1', 'address' => 'Capitol Square', 'city' => 'Columbus', 'state' => 'OH', 'country' => 'United States'],
    ];

    public function testTheLocationTravelsWithoutTheSourceEnvironmentsIds(): void
    {
        [$news, $article, $field] = $this->newsWithMap();
        $source = Schema::entry($news, $article, ['title' => 'Office', 'location' => self::LOCATION]);

        $payload = $this->portage()->export([$source]);
        $preview = $this->portage()->preview($payload);

        $portable = $preview->envelope['roots'][0]['fields'][$field->uid] ?? null;
        $this->assertIsArray($portable);
        $keys = array_keys($portable);
        sort($keys);
        $this->assertSame(['address', 'lat', 'lng', 'parts', 'what3words', 'zoom'], $keys);
        $this->assertSame(39.961176, $portable['lat']);
        $this->assertSame(-82.998794, $portable['lng']);
        $this->assertSame(12, $portable['zoom']);
        $this->assertSame('1 Capitol Square, Columbus, OH', $portable['address']);
        $this->assertSame('Columbus', $portable['parts']['city'] ?? null);
        $this->assertSame([], $preview->roots[0]->warnings);
    }

    public function testTheImportWritesSimpleMapsRecordForTheImportedEntry(): void
    {
        [$news, $article, $field] = $this->newsWithMap();
        $source = Schema::entry($news, $article, ['title' => 'Office', 'location' => self::LOCATION]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);

        $result = $this->portage()->import($payload);

        $this->assertSame([], $result->roots[0]->warnings);
        $id = $result->roots[0]->elementId;
        $this->assertNotNull($id);
        $imported = Entry::find()->id($id)->status(null)->one();
        $this->assertNotNull($imported);
        $map = $imported->getFieldValue('location');
        $this->assertInstanceOf(Map::class, $map);
        $this->assertSame(39.961176, $map->lat);
        $this->assertSame(-82.998794, $map->lng);
        $this->assertSame(12, $map->zoom);
        $this->assertSame('1 Capitol Square, Columbus, OH', $map->address);
        $this->assertSame('Columbus', $map->parts->city);

        $record = (new Query())->from(['{{%maps}}'])->where(['ownerId' => $id, 'fieldId' => $field->id])->one();
        $this->assertNotNull($record, 'SimpleMap has no record for the imported entry');
        $this->assertEqualsWithDelta(39.961176, (float)$record['lat'], 0.000001);
        $this->assertEqualsWithDelta(-82.998794, (float)$record['lng'], 0.000001);
    }

    public function testOverwritingAnEntryUpdatesItsMapInTheDraft(): void
    {
        [$news, $article, $field] = $this->newsWithMap();
        $source = Schema::entry($news, $article, ['title' => 'Office', 'location' => self::LOCATION]);
        $payload = $this->portage()->export([$source]);
        Schema::updateEntry($source, ['location' => ['lat' => 40.0, 'lng' => -83.0, 'zoom' => 10, 'address' => 'Elsewhere']]);

        $result = $this->portage()->import($payload, [], [$source->uid => ['mode' => 'overwrite']]);

        $this->assertSame([], $result->roots[0]->warnings);
        $draftId = $result->roots[0]->draftId;
        $this->assertNotNull($draftId);
        $draft = Entry::find()->draftId($draftId)->status(null)->one();
        $this->assertNotNull($draft);
        $this->assertSame('1 Capitol Square, Columbus, OH', $draft->getFieldValue('location')->address);
        $record = (new Query())->from(['{{%maps}}'])->where(['ownerId' => $draft->id, 'fieldId' => $field->id])->one();
        $this->assertNotNull($record, 'SimpleMap has no record for the draft');
        $this->assertEqualsWithDelta(39.961176, (float)$record['lat'], 0.000001);
        // The live entry keeps its own location.
        $live = Entry::find()->id($source->id)->status(null)->one();
        $this->assertSame('Elsewhere', $live?->getFieldValue('location')->address);
    }

    /**
     * @return array{0: Section, 1: EntryType, 2: MapField}
     */
    private function newsWithMap(): array
    {
        $field = Schema::field(MapField::class, 'location', ['name' => 'Location', 'zoom' => 12]);
        $this->assertInstanceOf(MapField::class, $field);
        $article = Schema::entryType('article', [$field]);

        return [Schema::section('news', [$article]), $article, $field];
    }

    private function portage(): Portage
    {
        return Plugin::getInstance()->getPortage();
    }
}
