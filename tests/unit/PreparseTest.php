<?php

namespace simplygoodwork\craftdolly\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use jalendport\preparse\fields\PreparseFieldType;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\services\Dolly;
use simplygoodwork\craftdolly\tests\support\Schema;

/**
 * Preparse: the value is left out of the Payload, and Preparse recalculates it from the Target field's template when
 * the imported entry is saved.
 */
class PreparseTest extends Unit
{
    private const NOTE = 'The value of the field "Headline Code" will be recalculated in this environment from the field\'s template.';

    public function testTheValueIsLeftOutOfThePayload(): void
    {
        [$news, $article, $field] = $this->newsWithPreparse('{{ entry.title|upper }}');
        $source = Schema::entry($news, $article, ['title' => 'Summer Menu']);
        $this->assertSame('SUMMER MENU', Entry::find()->id($source->id)->status(null)->one()?->getFieldValue('headlineCode'));

        $payload = $this->dolly()->export([$source]);
        $preview = $this->dolly()->preview($payload);

        // The field is named, so its Drift is checked, but its value isn't carried.
        $this->assertSame([$field->uid => null], $preview->envelope['roots'][0]['fields']);
        $this->assertSame([self::NOTE], $preview->roots[0]->warnings);
    }

    public function testTheValueIsRecalculatedFromTheTargetFieldsTemplate(): void
    {
        [$news, $article, $field] = $this->newsWithPreparse('{{ entry.title|upper }}');
        $source = Schema::entry($news, $article, ['title' => 'Summer Menu']);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        Schema::updateField($field->uid, ['fieldTwig' => '{{ entry.title|lower }} (imported)']);

        $result = $this->dolly()->import($payload);

        $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $this->assertNotNull($imported);
        $this->assertSame('summer menu (imported)', $imported->getFieldValue('headlineCode'));
        $this->assertSame(
            [
                'The field "Headline Code" has different settings in this environment than where it was exported. Its values will be imported, but check them.',
                self::NOTE,
            ],
            $result->roots[0]->warnings,
        );
    }

    /**
     * @return array{0: Section, 1: EntryType, 2: PreparseFieldType}
     */
    private function newsWithPreparse(string $template): array
    {
        $field = Schema::field(PreparseFieldType::class, 'headlineCode', ['name' => 'Headline Code', 'fieldTwig' => $template, 'translationMethod' => 'none']);
        $this->assertInstanceOf(PreparseFieldType::class, $field);
        $article = Schema::entryType('article', [$field]);

        return [Schema::section('news', [$article]), $article, $field];
    }

    private function dolly(): Dolly
    {
        return Plugin::getInstance()->getDolly();
    }
}
