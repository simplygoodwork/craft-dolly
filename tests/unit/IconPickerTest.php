<?php

namespace simplygoodwork\craftdolly\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Entry;
use craft\helpers\FileHelper;
use craft\models\EntryType;
use craft\models\Section;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\services\Dolly;
use simplygoodwork\craftdolly\tests\support\Requires;
use simplygoodwork\craftdolly\tests\support\Schema;
use verbb\iconpicker\fields\IconPickerField;
use verbb\iconpicker\IconPicker;
use verbb\iconpicker\iconsets\CssGg;
use verbb\iconpicker\iconsets\SvgFolder;
use verbb\iconpicker\models\Icon;

/**
 * Icon Picker: the selected icon travels with its icon set, which is checked in the Target environment. An icon whose
 * set is missing or not offered by the Target field is dropped with a warning; an SVG whose file is missing is kept
 * with one.
 */
class IconPickerTest extends Unit
{
    private const STAR = ['type' => 'svg', 'value' => '/brand/star.svg', 'iconSetHandle' => 'brand'];

    private string $iconSetsPath;
    private string $originalIconSetsPath;

    protected function _before(): void
    {
        $this->iconSetsPath = sys_get_temp_dir() . '/dolly-icon-picker-' . uniqid();
        FileHelper::writeToFile("$this->iconSetsPath/brand/star.svg", '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $settings = IconPicker::$plugin->getSettings();
        $this->originalIconSetsPath = $settings->iconSetsPath;
        $settings->iconSetsPath = $this->iconSetsPath;
    }

    protected function _after(): void
    {
        IconPicker::$plugin->getSettings()->iconSetsPath = $this->originalIconSetsPath;
        FileHelper::removeDirectory($this->iconSetsPath);
    }

    public function testAnSvgIconTravelsWithItsIconSet(): void
    {
        Requires::package('verbb/icon-picker', '>=3.0.1', 'Icon sets named in the value');
        Schema::iconSet(SvgFolder::class, 'brand', ['folder' => 'brand']);
        [$news, $article, $field] = $this->newsWithIcons();
        $source = Schema::entry($news, $article, ['title' => 'Banner', 'banner' => self::STAR]);

        $payload = $this->dolly()->export([$source]);
        $preview = $this->dolly()->preview($payload);
        $exported = $preview->envelope['roots'][0]['fields'][$field->uid] ?? null;
        $this->assertIsArray($exported);
        $this->assertSame('/brand/star.svg', $exported['value'] ?? null);
        $this->assertSame('svg', $exported['type'] ?? null);
        $this->assertSame('brand', $exported['iconSetHandle'] ?? null);
        $this->assertArrayNotHasKey('displayValue', $exported);
        $this->assertSame([], $preview->roots[0]->warnings);
        Schema::deleteElement($source);

        $result = $this->dolly()->import($payload);

        $icon = $this->importedIcon($result->roots[0]->elementId);
        $this->assertSame('/brand/star.svg', $icon->value);
        $this->assertSame('svg', $icon->type);
        $this->assertSame('brand', $icon->iconSetHandle);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testACssIconTravelsWithItsIconSet(): void
    {
        Requires::package('verbb/icon-picker', '>=3.0.1', 'Icon sets named in the value');
        Schema::iconSet(CssGg::class, 'gg');
        [$news, $article] = $this->newsWithIcons();
        $source = Schema::entry($news, $article, ['title' => 'Banner', 'banner' => ['type' => 'css', 'value' => 'gg-heart', 'iconSetHandle' => 'gg']]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);

        $result = $this->dolly()->import($payload);

        $icon = $this->importedIcon($result->roots[0]->elementId);
        $this->assertSame('gg-heart', $icon->value);
        $this->assertSame('css', $icon->type);
        $this->assertSame('gg', $icon->iconSetHandle);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testAnIconWhoseSetDoesntExistIsDropped(): void
    {
        Requires::package('verbb/icon-picker', '>=3.0.1', 'Icon sets named in the value');
        $brand = Schema::iconSet(SvgFolder::class, 'brand', ['folder' => 'brand']);
        [$news, $article] = $this->newsWithIcons();
        $source = Schema::entry($news, $article, ['title' => 'Banner', 'banner' => self::STAR]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteIconSet($brand);

        $warning = 'The field "Banner" has the icon "star" from the icon set "brand", which doesn\'t exist in this environment. That value will be dropped.';
        $this->assertSame([$warning], $this->dolly()->preview($payload)->roots[0]->warnings);

        $result = $this->dolly()->import($payload);

        $this->assertNull($this->importedIcon($result->roots[0]->elementId)->value);
        $this->assertSame([$warning], $result->roots[0]->warnings);
    }

    public function testAnIconFromASetTheTargetFieldDoesntOfferIsDropped(): void
    {
        Requires::package('verbb/icon-picker', '>=3.0.1', 'Icon sets named in the value');
        $brand = Schema::iconSet(SvgFolder::class, 'brand', ['folder' => 'brand']);
        $gg = Schema::iconSet(CssGg::class, 'gg');
        [$news, $article, $field] = $this->newsWithIcons(['iconSets' => [$brand->uid, $gg->uid]]);
        $source = Schema::entry($news, $article, ['title' => 'Banner', 'banner' => self::STAR]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        Schema::updateField($field->uid, ['iconSets' => [$gg->uid]]);

        $result = $this->dolly()->import($payload);

        $this->assertNull($this->importedIcon($result->roots[0]->elementId)->value);
        $this->assertContains(
            'The field "Banner" doesn\'t offer the icon set "Brand" in this environment. Its icon "star" will be dropped.',
            $result->roots[0]->warnings,
        );
    }

    public function testAnSvgIconWhoseFileIsMissingIsKeptWithAWarning(): void
    {
        Requires::package('verbb/icon-picker', '>=3.0.1', 'Icon sets named in the value');
        Schema::iconSet(SvgFolder::class, 'brand', ['folder' => 'brand']);
        [$news, $article] = $this->newsWithIcons();
        $source = Schema::entry($news, $article, ['title' => 'Banner', 'banner' => self::STAR]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        unlink("$this->iconSetsPath/brand/star.svg");

        $result = $this->dolly()->import($payload);

        $this->assertSame('/brand/star.svg', $this->importedIcon($result->roots[0]->elementId)->value);
        $this->assertSame(
            ['The field "Banner" has the icon "star", whose file "brand/star.svg" isn\'t in this environment\'s icon sets folder. The icon will be imported, but won\'t show until the file is added.'],
            $result->roots[0]->warnings,
        );
    }

    public function testAnIconSetIsFoundByItsUidAndTakesItsTargetHandle(): void
    {
        Requires::package('verbb/icon-picker', '>=3.1.0', 'Icon set UIDs in the value');
        $brand = Schema::iconSet(SvgFolder::class, 'brand', ['folder' => 'brand']);
        [$news, $article, $field] = $this->newsWithIcons();
        $source = Schema::entry($news, $article, ['title' => 'Banner', 'banner' => self::STAR]);

        $payload = $this->dolly()->export([$source]);
        $exported = $this->dolly()->preview($payload)->envelope['roots'][0]['fields'][$field->uid] ?? null;
        $this->assertSame($brand->uid, $exported['iconSetUid'] ?? null);
        Schema::deleteElement($source);
        $brand->handle = 'logos';
        IconPicker::$plugin->getIconSets()->saveIconSet($brand);

        $result = $this->dolly()->import($payload);

        $icon = $this->importedIcon($result->roots[0]->elementId);
        $this->assertSame('/brand/star.svg', $icon->value);
        $this->assertSame('logos', $icon->iconSetHandle);
        $this->assertSame($brand->uid, $icon->iconSetUid);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testAnIconThatNamesNoIconSetIsImportedAsItIs(): void
    {
        [$news, $article] = $this->newsWithIcons();
        $source = Schema::entry($news, $article, ['title' => 'Banner', 'banner' => ['type' => 'css', 'value' => 'gg-heart']]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);

        $result = $this->dolly()->import($payload);

        $icon = $this->importedIcon($result->roots[0]->elementId);
        $this->assertSame('gg-heart', $icon->value);
        $this->assertSame('css', $icon->type);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{0: Section, 1: EntryType, 2: IconPickerField}
     */
    private function newsWithIcons(array $config = []): array
    {
        $field = Schema::field(IconPickerField::class, 'banner', array_merge(['name' => 'Banner', 'iconSets' => '*'], $config));
        $this->assertInstanceOf(IconPickerField::class, $field);
        $article = Schema::entryType('article', [$field]);

        return [Schema::section('news', [$article]), $article, $field];
    }

    private function importedIcon(?int $id): Icon
    {
        $this->assertNotNull($id, 'The Import result has no element ID');
        $entry = Entry::find()->id($id)->status(null)->one();
        $this->assertNotNull($entry, "No entry with ID $id");
        $value = $entry->getFieldValue('banner');
        $this->assertInstanceOf(Icon::class, $value);

        return $value;
    }

    private function dolly(): Dolly
    {
        return Plugin::getInstance()->getDolly();
    }
}
