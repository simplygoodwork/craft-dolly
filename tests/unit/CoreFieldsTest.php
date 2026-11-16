<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fields\ButtonGroup;
use craft\fields\Checkboxes;
use craft\fields\Color;
use craft\fields\ContentBlock;
use craft\fields\Country;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\Email;
use craft\fields\Entries;
use craft\fields\Icon;
use craft\fields\Json;
use craft\fields\Lightswitch;
use craft\fields\Link;
use craft\fields\Money;
use craft\fields\MultiSelect;
use craft\fields\Number;
use craft\fields\RadioButtons;
use craft\fields\Range;
use craft\fields\Table;
use craft\fields\Time;
use craft\fields\Url;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use simplygoodwork\craftportage\events\RegisterFieldAdaptersEvent;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\fields\FieldAdapter;
use simplygoodwork\craftportage\fields\FieldAdapterRegistry;
use simplygoodwork\craftportage\import\ImportContext;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\tests\support\Requires;
use simplygoodwork\craftportage\tests\support\Schema;
use simplygoodwork\craftportage\tests\support\UnadaptedField;
use simplygoodwork\craftportage\tests\support\UnserializableField;
use yii\base\Event;
use yii\base\InvalidConfigException;

/**
 * Craft's remaining core field types, the fallback for a field type with no adapter, and the event that registers one.
 */
class CoreFieldsTest extends Unit
{
    private const OPTIONS = [
        ['label' => 'Small', 'value' => 's'],
        ['label' => 'Medium', 'value' => 'm'],
        ['label' => 'Large', 'value' => 'l'],
    ];

    public function testNumberLightswitchAndEmailRoundTrip(): void
    {
        $values = $this->roundTrip(
            [
                Schema::field(Number::class, 'count', ['decimals' => 2]),
                Schema::field(Lightswitch::class, 'featured'),
                Schema::field(Email::class, 'contact'),
            ],
            ['count' => 12.5, 'featured' => true, 'contact' => 'hello@example.com'],
        );

        $this->assertSame(12.5, $values->getFieldValue('count'));
        $this->assertTrue($values->getFieldValue('featured'));
        $this->assertSame('hello@example.com', $values->getFieldValue('contact'));
    }

    public function testRangeAndUrlRoundTrip(): void
    {
        Requires::classes('The Range field and the Link field', Range::class, Link::class);

        $values = $this->roundTrip(
            [
                Schema::field(Range::class, 'rating'),
                // `Url` is a class alias of `Link`, so this goes through the Link adapter.
                Schema::field(Url::class, 'website'),
            ],
            ['rating' => 40, 'website' => ['type' => 'url', 'value' => 'https://example.com']],
        );

        $this->assertEquals(40, $values->getFieldValue('rating'));
        $this->assertSame('https://example.com', $values->getFieldValue('website')->serialize()['value']);
    }

    public function testDateTimeColorAndCountryRoundTrip(): void
    {
        $values = $this->roundTrip(
            [
                Schema::field(Date::class, 'published', ['showTime' => true]),
                Schema::field(Time::class, 'opens'),
                Schema::field(Color::class, 'accent', ['allowCustomColors' => true]),
                Schema::field(Country::class, 'country'),
            ],
            ['published' => '2026-03-04T05:06:07+00:00', 'opens' => '09:30:00', 'accent' => '#ff8800', 'country' => 'NZ'],
        );

        $this->assertSame('2026-03-04 05:06:07', $values->getFieldValue('published')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        $this->assertSame('09:30', $values->getFieldValue('opens')->format('H:i'));
        $this->assertSame('#ff8800', $values->getFieldValue('accent')->getHex());
        $this->assertSame('NZ', $values->getFieldValue('country')->getCountryCode());
    }

    public function testMoneyTableAndIconRoundTrip(): void
    {
        $values = $this->roundTrip(
            [
                Schema::field(Money::class, 'price', ['currency' => 'USD']),
                Schema::field(Table::class, 'rates', ['columns' => [
                    'col1' => ['heading' => 'Name', 'handle' => 'name', 'width' => '', 'type' => 'singleline'],
                    'col2' => ['heading' => 'Rate', 'handle' => 'rate', 'width' => '', 'type' => 'number'],
                ]]),
                Schema::field(Icon::class, 'symbol'),
            ],
            [
                'price' => '1999',
                'rates' => [['col1' => 'Standard', 'col2' => '10'], ['col1' => 'Reduced', 'col2' => '5']],
                'symbol' => 'star',
            ],
        );

        $this->assertSame('1999', $values->getFieldValue('price')->getAmount());
        $this->assertSame('USD', $values->getFieldValue('price')->getCurrency()->getCode());
        $this->assertSame([['col1' => 'Standard', 'col2' => '10'], ['col1' => 'Reduced', 'col2' => '5']], array_map(fn(array $row) => ['col1' => $row['col1'], 'col2' => $row['col2']], $values->getFieldValue('rates')));
        // Craft 5.7 and earlier return the icon's name; later versions return an IconData.
        $symbol = $values->getFieldValue('symbol');
        $this->assertSame('star', is_string($symbol) ? $symbol : $symbol->name);
    }

    public function testJsonRoundTrip(): void
    {
        Requires::classes('The JSON field', Json::class);

        $values = $this->roundTrip(
            [Schema::field(Json::class, 'settings')],
            ['settings' => ['a' => [1, 2], 'b' => ['c' => true]]],
        );

        $this->assertSame(['a' => [1, 2], 'b' => ['c' => true]], $values->getFieldValue('settings')->getValue());
    }

    public function testOptionFieldsRoundTrip(): void
    {
        $values = $this->roundTrip(
            [
                Schema::field(Dropdown::class, 'size', ['options' => self::OPTIONS]),
                Schema::field(RadioButtons::class, 'radio', ['options' => self::OPTIONS]),
                Schema::field(Checkboxes::class, 'boxes', ['options' => self::OPTIONS]),
                Schema::field(MultiSelect::class, 'multi', ['options' => self::OPTIONS]),
            ],
            ['size' => 'm', 'radio' => 'l', 'boxes' => ['s', 'l'], 'multi' => ['m']],
        );

        $this->assertSame('m', (string)$values->getFieldValue('size'));
        $this->assertSame('l', (string)$values->getFieldValue('radio'));
        $this->assertSame(['s', 'l'], array_map('strval', iterator_to_array($values->getFieldValue('boxes'))));
        $this->assertSame(['m'], array_map('strval', iterator_to_array($values->getFieldValue('multi'))));
    }

    public function testButtonGroupRoundTrips(): void
    {
        Requires::classes('The Button Group field', ButtonGroup::class);

        $values = $this->roundTrip(
            [Schema::field(ButtonGroup::class, 'group', ['options' => self::OPTIONS])],
            ['group' => 's'],
        );

        $this->assertSame('s', (string)$values->getFieldValue('group'));
    }

    public function testAnOptionRemovedBetweenExportAndImportIsDroppedWithAWarning(): void
    {
        $size = Schema::field(Dropdown::class, 'size', ['options' => self::OPTIONS]);
        $boxes = Schema::field(Checkboxes::class, 'boxes', ['options' => self::OPTIONS]);
        $article = Schema::entryType('article', [$size, $boxes]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Options', 'size' => 'l', 'boxes' => ['s', 'm', 'l']]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::updateField($size->uid, ['options' => array_slice(self::OPTIONS, 0, 2)]);
        Schema::updateField($boxes->uid, ['options' => array_slice(self::OPTIONS, 0, 2)]);

        $result = $this->portage()->import($payload);

        $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $this->assertNull($imported->getFieldValue('size')->value);
        $this->assertSame(['s', 'm'], array_map('strval', iterator_to_array($imported->getFieldValue('boxes'))));
        $this->assertContains('The field "' . $size->name . '" no longer has the option "l" in this environment. That value will be dropped.', $result->roots[0]->warnings);
        $this->assertContains('The field "' . $boxes->name . '" no longer has the option "l" in this environment. That value will be dropped.', $result->roots[0]->warnings);
    }

    public function testAFieldTypeWithNoAdapterRoundTripsThroughTheFallbackAndWarnsInThePreview(): void
    {
        $notes = Schema::field(UnadaptedField::class, 'notes');
        $article = Schema::entryType('article', [$notes]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Fallback', 'notes' => 'Some notes']);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);

        $expected = 'The field "' . $notes->name . '" is a Unadapted field, which Portage has no adapter for. Its value will be imported as it is, and any IDs in it may point at different elements in this environment.';
        $preview = $this->portage()->preview($payload);
        $this->assertContains($expected, $preview->roots[0]->warnings);

        $result = $this->portage()->import($payload);
        $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $this->assertSame('Some notes', $imported->getFieldValue('notes'));
        $this->assertContains($expected, $result->roots[0]->warnings);
    }

    public function testAnAdapterRegisteredThroughTheEventIsUsedInsteadOfTheFallback(): void
    {
        $adapter = new class() implements FieldAdapter {
            public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
            {
                return strtoupper((string)$value);
            }

            public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
            {
                return strtolower((string)$portable) . '!';
            }
        };
        $register = function(RegisterFieldAdaptersEvent $event) use ($adapter) {
            $event->adapters[UnadaptedField::class] = $adapter;
        };
        Event::on(FieldAdapterRegistry::class, FieldAdapterRegistry::EVENT_REGISTER_ADAPTERS, $register);

        try {
            $notes = Schema::field(UnadaptedField::class, 'notes');
            $article = Schema::entryType('article', [$notes]);
            $news = Schema::section('news', [$article]);
            $source = Schema::entry($news, $article, ['title' => 'Registered', 'notes' => 'Some Notes']);

            $payload = $this->portage()->export([$source]);
            Schema::deleteElement($source);
            $result = $this->portage()->import($payload);
        } finally {
            Event::off(FieldAdapterRegistry::class, FieldAdapterRegistry::EVENT_REGISTER_ADAPTERS, $register);
        }

        $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $this->assertSame('some notes!', $imported->getFieldValue('notes'));
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testADateKeepsItsNamedTimeZone(): void
    {
        $values = $this->roundTrip(
            [Schema::field(Date::class, 'starts', ['showTime' => true, 'showTimeZone' => true])],
            ['starts' => ['datetime' => '2026-03-04 05:06:07', 'timezone' => 'Pacific/Auckland']],
        );

        $this->assertSame('Pacific/Auckland', $values->getFieldValue('starts')->getTimezone()->getName());
        $this->assertSame('2026-03-04 05:06:07', $values->getFieldValue('starts')->format('Y-m-d H:i:s'));
    }

    public function testAMoneyFieldInAnotherCurrencyWarnsThatTheAmountMayChangeMagnitude(): void
    {
        $price = Schema::field(Money::class, 'price', ['currency' => 'USD']);
        $article = Schema::entryType('article', [$price]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Price', 'price' => '1999']);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::updateField($price->uid, ['currency' => 'JPY']);

        $result = $this->portage()->import($payload);

        $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $this->assertSame('1999', $imported->getFieldValue('price')->getAmount());
        $this->assertContains('The field "' . $price->name . '" is in USD in the source environment and JPY in this one. Its amount is in minor units and will be imported as it is, so it may change magnitude.', $result->roots[0]->warnings);
    }

    public function testAContentBlockIsSkippedWithAWarningInsteadOfCarryingRawElementIds(): void
    {
        Requires::classes('The Content Block field', ContentBlock::class);

        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft']);
        $related = Schema::field(Entries::class, 'related', ['sources' => '*']);
        $layout = new FieldLayout(['type' => Entry::class]);
        $layout->setTabs([new FieldLayoutTab(['name' => 'Content', 'layout' => $layout, 'elements' => [new CustomField($related)]])]);
        $block = Schema::field(ContentBlock::class, 'block', ['fieldLayout' => $layout]);
        $article = Schema::entryType('article', [$block]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Block', 'block' => ['fields' => ['related' => [$craft->id]]]]);

        $payload = $this->portage()->export([$source]);
        $this->assertNull($this->portage()->preview($payload)->envelope['roots'][0]['fields'][$block->uid]);
        Schema::deleteElement($source);

        $result = $this->portage()->import($payload);

        $this->assertContains('The field "' . $block->name . '" holds nested content Portage can\'t move yet. Its values will be skipped.', $result->roots[0]->warnings);
        $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $this->assertSame(0, $imported->getFieldValue('block')->getFieldValue('related')->count());
    }

    public function testAFieldThatCannotBeSerializedDoesNotFailTheExport(): void
    {
        $unserializable = Schema::field(UnserializableField::class, 'broken');
        $article = Schema::entryType('article', [$unserializable, Schema::plainText('summary')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Broken', 'summary' => 'Still here']);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        $result = $this->portage()->import($payload);

        $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $this->assertSame('Still here', $imported->getFieldValue('summary'));
    }

    public function testAnAdapterThatIsNotAFieldAdapterIsRefused(): void
    {
        $register = function(RegisterFieldAdaptersEvent $event) {
            $event->adapters[UnadaptedField::class] = new \stdClass();
        };
        Event::on(FieldAdapterRegistry::class, FieldAdapterRegistry::EVENT_REGISTER_ADAPTERS, $register);

        try {
            $this->expectException(InvalidConfigException::class);
            $this->expectExceptionMessage('The adapter registered for ' . UnadaptedField::class . ' must implement ' . FieldAdapter::class . '.');
            new FieldAdapterRegistry();
        } finally {
            Event::off(FieldAdapterRegistry::class, FieldAdapterRegistry::EVENT_REGISTER_ADAPTERS, $register);
        }
    }

    /**
     * Exports an entry with the fields, deletes it, imports the Payload and returns the imported entry.
     *
     * @param list<FieldInterface> $fields
     * @param array<string, mixed> $values
     */
    private function roundTrip(array $fields, array $values): Entry
    {
        $article = Schema::entryType('article', $fields);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Core fields', ...$values]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        $result = $this->portage()->import($payload);

        $this->assertSame([], $result->roots[0]->warnings);

        return Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
    }

    private function portage(): Portage
    {
        return Plugin::getInstance()->getPortage();
    }
}
