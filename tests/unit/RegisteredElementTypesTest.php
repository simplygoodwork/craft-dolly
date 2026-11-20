<?php

namespace simplygoodwork\craftdolly\tests\unit;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\elements\Entry;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\references\ElementTypeDefinition;
use simplygoodwork\craftdolly\services\Dolly;
use simplygoodwork\craftdolly\tests\support\Schema;
use simplygoodwork\craftdolly\tests\support\Widget;
use simplygoodwork\craftdolly\tests\support\WidgetDefinition;
use simplygoodwork\craftdolly\tests\support\WidgetsField;
use yii\base\InvalidConfigException;

/**
 * Element types a plugin registers with Dolly: a Payload carries their elements as References by the registered
 * short name, and a relation field to them needs no adapter of its own.
 */
class RegisteredElementTypesTest extends Unit
{
    public function testARegisteredElementTypeIsReferencedByItsShortNameAndResolvesByUidThenNaturalKey(): void
    {
        Schema::withElementType('widget', new WidgetDefinition(), function() {
            $alpha = Schema::widget('Alpha', 'alpha');
            $beta = Schema::widget('Beta', 'beta');
            $betaSourceUid = $beta->uid;
            $article = Schema::entryType('article', [Schema::field(WidgetsField::class, 'widgets')]);
            $news = Schema::section('news', [$article]);
            $source = Schema::entry($news, $article, ['title' => 'Widgets', 'widgets' => [$alpha->id, $beta->id]]);

            $payload = $this->dolly()->export([$source]);
            Schema::deleteElement($source);
            Schema::changeUid($beta);

            $plan = $this->dolly()->preview($payload);
            $this->assertSame('widget', $plan->references[$alpha->uid]->elementType);
            $this->assertSame(['slug' => 'beta'], $plan->references[$betaSourceUid]->reference['naturalKey']);
            $this->assertSame('uid', $plan->references[$alpha->uid]->resolution->rule);
            $this->assertSame('naturalKey', $plan->references[$betaSourceUid]->resolution->rule);
            $this->assertSame($beta->id, $plan->references[$betaSourceUid]->resolution->elementId);

            $result = $this->dolly()->import($payload);

            $this->assertSame([], $result->roots[0]->warnings);
            $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
            $this->assertSame([$alpha->id, $beta->id], $imported->getFieldValue('widgets')->status(null)->ids());
        });
    }

    public function testAnUnresolvedReferenceOfATypeWithoutStubsCanBeReplacedButNotStubbed(): void
    {
        Schema::withElementType('widget', new WidgetDefinition(), function() {
            $gone = Schema::widget('Gone', 'gone');
            $goneUid = $gone->uid;
            $instead = Schema::widget('Instead', 'instead');
            $article = Schema::entryType('article', [Schema::field(WidgetsField::class, 'widgets')]);
            $news = Schema::section('news', [$article]);
            $source = Schema::entry($news, $article, ['title' => 'Widgets', 'widgets' => [$gone->id]]);

            $payload = $this->dolly()->export([$source]);
            Schema::deleteElement($source);
            Schema::deleteElement($gone);

            $this->assertSame(['skip', 'replace'], $this->dolly()->preview($payload)->references[$goneUid]->availableChoices);
            $this->assertSame(
                'The Reference "Gone" can\'t be Stubbed: Dolly never Stubs widgets. Skip it or choose a replacement.',
                $this->dolly()->preview($payload, [$goneUid => 'stub'])->refusal,
            );

            $result = $this->dolly()->import($payload, [$goneUid => $instead->id]);

            $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
            $this->assertSame([$instead->id], $imported->getFieldValue('widgets')->status(null)->ids());
            $this->assertNull(Widget::find()->uid($goneUid)->status(null)->one());
        });
    }

    public function testAReferenceTagToARegisteredElementTypeUsesItsReferenceHandleInTheTargetEnvironment(): void
    {
        Schema::withElementType('widget', new WidgetDefinition(), function() {
            $alpha = Schema::widget('Alpha', 'alpha');
            $body = Schema::ckeditor('richBody');
            $article = Schema::entryType('article', [$body]);
            $news = Schema::section('news', [$article]);
            $source = Schema::entry($news, $article, [
                'title' => 'Rich',
                'richBody' => "<p>See <a href=\"{gadget:$alpha->id:url}\">the widget</a>.</p>",
            ]);
            $alphaUid = $alpha->uid;

            $payload = $this->dolly()->export([$source]);
            Schema::deleteElement($source);
            Schema::deleteElement($alpha);
            $newAlpha = Schema::widget('Alpha', 'alpha');

            $plan = $this->dolly()->preview($payload);
            $this->assertSame('widget', $plan->references[$alphaUid]->elementType);
            $this->assertSame('naturalKey', $plan->references[$alphaUid]->resolution->rule);
            $this->assertSame($newAlpha->id, $plan->references[$alphaUid]->resolution->elementId);

            $result = $this->dolly()->import($payload);

            $this->assertSame([], $result->roots[0]->warnings);
            $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
            $this->assertSame(
                "<p>See <a href=\"{gadget:$newAlpha->id:url||}\">the widget</a>.</p>",
                $imported->getFieldValue('richBody')->getRawContent(),
            );
        });
    }

    public function testAPayloadNamingAnElementTypeThatIsNotRegisteredHereIsRefused(): void
    {
        $payload = Schema::withElementType('widget', new WidgetDefinition(), function() {
            $widget = Schema::widget('Alpha', 'alpha');
            $article = Schema::entryType('article', [Schema::field(WidgetsField::class, 'widgets')]);
            $news = Schema::section('news', [$article]);
            $source = Schema::entry($news, $article, ['title' => 'Widgets', 'widgets' => [$widget->id]]);

            return $this->dolly()->export([$source]);
        });

        $this->assertSame(
            'The Payload names an element type Dolly doesn\'t support: "widget". It can\'t be imported.',
            $this->dolly()->preview($payload)->refusal,
        );
    }

    /**
     * @dataProvider invalidRegistrations
     */
    public function testAnInvalidRegistrationIsRefused(string $name, mixed $definition, string $message): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage($message);

        Schema::withElementType($name, $definition, fn() => $this->dolly()->getElementTypes());
    }

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function invalidRegistrations(): array
    {
        return [
            'not a definition' => ['gadget', new \stdClass(), 'The element type registered as "gadget" must extend simplygoodwork\craftdolly\references\ElementTypeDefinition.'],
            'a name a reference tag can\'t carry' => ['wid-get', new WidgetDefinition(), 'The element type name "wid-get" isn\'t valid: use letters and digits, starting with a letter.'],
            'the Neo block name' => ['neoBlock', new WidgetDefinition(), 'The element type name "neoBlock" is reserved for Neo blocks.'],
            'a class already registered' => ['page', new class() extends ElementTypeDefinition {
                public function elementClass(): string
                {
                    return Entry::class;
                }

                public function naturalKey(ElementInterface $element): ?array
                {
                    return null;
                }

                public function find(array $naturalKey): ?int
                {
                    return null;
                }
            }, 'The element type registered as "page" names craft\\elements\\Entry, which is already registered as "entry".'],
        ];
    }

    private function dolly(): Dolly
    {
        return Plugin::getInstance()->getDolly();
    }
}
