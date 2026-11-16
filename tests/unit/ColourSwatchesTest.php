<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use percipiolondon\colourswatches\fields\ColourSwatches;
use percipiolondon\colourswatches\models\ColourSwatches as Swatch;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\tests\support\Schema;

/**
 * Colour Swatches: the selected swatch travels by its label, and a label the Target field's palette doesn't have is
 * dropped with a warning rather than silently replaced.
 */
class ColourSwatchesTest extends Unit
{
    private const SWATCHES = [
        ['label' => 'blue', 'color' => '#2638c4', 'default' => '1', 'class' => 'is-blue'],
        ['label' => 'teal', 'color' => '#48d3cd', 'default' => '', 'class' => 'is-teal'],
        ['label' => 'coral', 'color' => '#fe704e', 'default' => '', 'class' => ''],
    ];

    public function testTheSwatchTravelsByItsLabelAndColour(): void
    {
        [$news, $article, $field] = $this->newsWithSwatches(self::SWATCHES);
        $source = Schema::entry($news, $article, ['title' => 'Banner', 'banner' => ['label' => 'teal', 'color' => '#48d3cd']]);

        $payload = $this->portage()->export([$source]);
        $preview = $this->portage()->preview($payload);
        $this->assertSame(['label' => 'teal', 'color' => '#48d3cd'], $preview->envelope['roots'][0]['fields'][$field->uid] ?? null);
        $this->assertSame([], $preview->roots[0]->warnings);
        Schema::deleteElement($source);

        $result = $this->portage()->import($payload);

        $swatch = $this->importedSwatch($result->roots[0]->elementId);
        $this->assertSame('teal', $swatch->label);
        $this->assertSame('#48d3cd', $swatch->color);
        $this->assertSame('is-teal', $swatch->class);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testTheSwatchTakesTheTargetPalettesColourForItsLabel(): void
    {
        [$news, $article, $field] = $this->newsWithSwatches(self::SWATCHES);
        $source = Schema::entry($news, $article, ['title' => 'Banner', 'banner' => ['label' => 'teal', 'color' => '#48d3cd']]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::updateField($field->uid, ['options' => [
            ['label' => 'blue', 'color' => '#2638c4', 'default' => '1', 'class' => ''],
            ['label' => 'teal', 'color' => '#00a0a0', 'default' => '', 'class' => 'teal-dark'],
        ]]);

        $result = $this->portage()->import($payload);

        $swatch = $this->importedSwatch($result->roots[0]->elementId);
        $this->assertSame('teal', $swatch->label);
        $this->assertSame('#00a0a0', $swatch->color);
        $this->assertSame('teal-dark', $swatch->class);
        // The palette changed, so the field's Drift is reported, but the swatch is kept.
        $this->assertSame(['The field "Banner" has different settings in this environment than where it was exported. Its values will be imported, but check them.'], $result->roots[0]->warnings);
    }

    public function testASwatchTheTargetPaletteDoesntHaveIsDroppedAndTheFieldUsesItsDefault(): void
    {
        [$news, $article, $field] = $this->newsWithSwatches(self::SWATCHES);
        $source = Schema::entry($news, $article, ['title' => 'Banner', 'banner' => ['label' => 'coral', 'color' => '#fe704e']]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::updateField($field->uid, ['options' => array_slice(self::SWATCHES, 0, 2)]);

        $drift = 'The field "Banner" has different settings in this environment than where it was exported. Its values will be imported, but check them.';
        $warning = 'The field "Banner" no longer has the colour "coral" in this environment. That value will be dropped, and the field will use its default colour "blue".';
        $this->assertSame([$drift, $warning], $this->portage()->preview($payload)->roots[0]->warnings);

        $result = $this->portage()->import($payload);

        $this->assertSame('blue', $this->importedSwatch($result->roots[0]->elementId)->label);
        $this->assertSame([$drift, $warning], $result->roots[0]->warnings);
    }

    public function testASwatchTheTargetPaletteDoesntHaveIsDroppedWhenTheFieldHasNoDefault(): void
    {
        [$news, $article, $field] = $this->newsWithSwatches(self::SWATCHES);
        $source = Schema::entry($news, $article, ['title' => 'Banner', 'banner' => ['label' => 'coral', 'color' => '#fe704e']]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::updateField($field->uid, ['options' => [
            ['label' => 'blue', 'color' => '#2638c4', 'default' => '', 'class' => ''],
            ['label' => 'teal', 'color' => '#48d3cd', 'default' => '', 'class' => ''],
        ]]);

        $result = $this->portage()->import($payload);

        $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $this->assertNotNull($imported);
        $this->assertNull($imported->getFieldValue('banner'));
        $this->assertSame(
            [
                'The field "Banner" has different settings in this environment than where it was exported. Its values will be imported, but check them.',
                'The field "Banner" no longer has the colour "coral" in this environment. That value will be dropped.',
            ],
            $result->roots[0]->warnings,
        );
    }

    public function testAConfigFilePaletteIsCheckedInTheTargetEnvironmentsConfig(): void
    {
        $red = ['label' => 'Red', 'default' => false, 'class' => null, 'color' => [['color' => '#ef4444', 'background' => 'bg-red-500']]];
        $green = ['label' => 'Green', 'default' => false, 'class' => null, 'color' => [['color' => '#22c55e', 'background' => 'bg-green-500']]];
        $blue = ['label' => 'Blue', 'default' => false, 'class' => null, 'color' => [['color' => '#3b82f6', 'background' => 'bg-blue-500']]];
        [$news, $article] = $this->newsWithSwatches([], ['useConfigFile' => true, 'palette' => 'Brand', 'setRandom' => true]);

        $source = Schema::withColourSwatchesSettings(['palettes' => ['Brand' => [$red, $green]]], function() use ($news, $article) {
            $entry = Schema::entry($news, $article, ['title' => 'Banner', 'banner' => ['label' => 'Green', 'color' => [['color' => '#22c55e', 'background' => 'bg-green-500']]]]);
            return [$entry, $this->portage()->export([$entry])];
        });
        [$entry, $payload] = $source;
        Schema::deleteElement($entry);

        $result = Schema::withColourSwatchesSettings(['palettes' => ['Brand' => [$red, $blue]]], fn() => $this->portage()->import($payload));

        $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $this->assertNotNull($imported);
        // Whether the field picks a random colour depends on the Craft version, but it's never the dropped one.
        $this->assertNotSame('Green', $imported->getFieldValue('banner')?->label);
        $this->assertSame(
            ['The field "Banner" no longer has the colour "Green" in this environment. That value will be dropped, and the field may pick a random colour instead.'],
            $result->roots[0]->warnings,
        );
    }

    /**
     * @param list<array<string, mixed>> $swatches
     * @param array<string, mixed> $config
     * @return array{0: Section, 1: EntryType, 2: ColourSwatches}
     */
    private function newsWithSwatches(array $swatches, array $config = []): array
    {
        $field = Schema::field(ColourSwatches::class, 'banner', array_merge(['name' => 'Banner', 'options' => $swatches], $config));
        $this->assertInstanceOf(ColourSwatches::class, $field);
        $article = Schema::entryType('article', [$field]);

        return [Schema::section('news', [$article]), $article, $field];
    }

    private function importedSwatch(?int $id): Swatch
    {
        $this->assertNotNull($id, 'The Import result has no element ID');
        $entry = Entry::find()->id($id)->status(null)->one();
        $this->assertNotNull($entry, "No entry with ID $id");
        $value = $entry->getFieldValue('banner');
        $this->assertInstanceOf(Swatch::class, $value);

        return $value;
    }

    private function portage(): Portage
    {
        return Plugin::getInstance()->getPortage();
    }
}
