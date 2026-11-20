<?php

namespace simplygoodwork\craftdolly\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use craft\fields\Number;
use craft\fields\PlainText;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\services\Dolly;
use simplygoodwork\craftdolly\tests\support\Schema;

/**
 * Schema Drift between Export and Import, simulated by changing the install in between.
 */
class DriftTest extends Unit
{
    public function testFingerprintsAreStableForUnchangedConfigAndChangeWithAFieldsSettings(): void
    {
        $summary = Schema::plainText('summary');
        $article = Schema::entryType('article', [$summary]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Hello', 'summary' => 'Text']);

        $first = $this->envelope($this->dolly()->export([$source]));
        $site = Craft::$app->getSites()->getPrimarySite();
        foreach ([['sites', $site->uid], ['sections', $news->uid], ['entryTypes', $article->uid], ['fields', $summary->uid]] as [$kind, $uid]) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first['schema'][$kind][$uid]['fingerprint'] ?? '', "$kind $uid");
        }
        $this->assertSame('summary', $first['schema']['fields'][$summary->uid]['handle']);
        $this->assertSame(PlainText::class, $first['schema']['fields'][$summary->uid]['type']);

        // Re-saving without changes keeps the config, so the Fingerprint stays the same.
        Schema::updateField($summary->uid);
        $second = $this->envelope($this->dolly()->export([$source]));
        $this->assertSame($first['schema'], $second['schema']);

        Schema::updateField($summary->uid, ['charLimit' => 42]);
        $third = $this->envelope($this->dolly()->export([$source]));
        $this->assertNotSame(
            $first['schema']['fields'][$summary->uid]['fingerprint'],
            $third['schema']['fields'][$summary->uid]['fingerprint'],
        );
        $this->assertSame(
            $first['schema']['sections'][$news->uid]['fingerprint'],
            $third['schema']['sections'][$news->uid]['fingerprint'],
        );
    }

    public function testADriftedFieldsValueIsImportedWithAWarningNamingTheField(): void
    {
        $summary = Schema::plainText('summary');
        $article = Schema::entryType('article', [$summary]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Hello', 'summary' => 'Text']);
        $payload = $this->dolly()->export([$source]);

        Schema::updateField($summary->uid, ['charLimit' => 42]);

        $plan = $this->dolly()->preview($payload);
        $this->assertFalse($plan->isRefused());
        $this->assertSame('drifted', $plan->drift->get('fields', $summary->uid)?->state);
        $this->assertSame('matched', $plan->drift->get('sections', $news->uid)?->state);
        $this->assertSame('matched', $plan->drift->get('entryTypes', $article->uid)?->state);
        $warning = 'The field "Summary" has different settings in this environment than where it was exported. Its values will be imported, but check them.';
        $this->assertSame([$warning], $plan->roots[0]->warnings);

        $result = $this->dolly()->import($payload);
        $this->assertSame([$warning], $result->roots[0]->warnings);
        $this->assertSame('Text', $this->importedEntry($result->roots[0]->elementId)->getFieldValue('summary'));
    }

    public function testATypeChangedFieldsValueIsSkippedAndTheRequiredFieldIsListedAsIncomplete(): void
    {
        $summary = Schema::plainText('summary');
        $subtitle = Schema::plainText('subtitle');
        $article = Schema::entryType('article', [Schema::required($summary), $subtitle]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Hello', 'summary' => 'Text', 'subtitle' => 'Sub']);
        $payload = $this->dolly()->export([$source]);

        Schema::changeFieldType($summary->uid, Number::class);

        $plan = $this->dolly()->preview($payload);
        $this->assertSame('type-changed', $plan->drift->get('fields', $summary->uid)?->state);
        $warning = 'The field "Summary" is a Number field in this environment but was a Plain Text field where it was exported. Its values will be skipped.';
        $this->assertSame([$warning], $plan->roots[0]->warnings);
        $this->assertSame(['Summary'], $plan->roots[0]->incompleteFields);

        $result = $this->dolly()->import($payload);
        $this->assertSame('created', $result->roots[0]->status);
        $this->assertSame([$warning], $result->roots[0]->warnings);
        $this->assertSame(['Summary'], $result->roots[0]->incompleteFields);
        $imported = $this->importedEntry($result->roots[0]->elementId);
        $this->assertFalse($imported->enabled);
        $this->assertNull($imported->getFieldValue('summary'));
        $this->assertSame('Sub', $imported->getFieldValue('subtitle'));
    }

    public function testAMissingFieldsValueIsSkippedAndTheRestOfTheEntryImports(): void
    {
        $summary = Schema::plainText('summary');
        $subtitle = Schema::plainText('subtitle');
        $article = Schema::entryType('article', [$summary, $subtitle]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Hello', 'summary' => 'Text', 'subtitle' => 'Sub']);
        $payload = $this->dolly()->export([$source]);

        Schema::deleteField($subtitle);

        $plan = $this->dolly()->preview($payload);
        $this->assertSame('missing', $plan->drift->get('fields', $subtitle->uid)?->state);
        $warning = 'The field "Subtitle" doesn\'t exist in this environment. Its values will be skipped.';
        $this->assertSame([$warning], $plan->roots[0]->warnings);

        $result = $this->dolly()->import($payload);
        $this->assertSame([$warning], $result->roots[0]->warnings);
        $imported = $this->importedEntry($result->roots[0]->elementId);
        $this->assertSame('Hello', $imported->title);
        $this->assertSame('Text', $imported->getFieldValue('summary'));
    }

    public function testMatrixEntriesWhoseEntryTypeIsNoLongerAllowedInTheFieldAreSkippedWithAWarning(): void
    {
        $text = Schema::blockType('text', [Schema::plainText('body')]);
        $quote = Schema::blockType('quote', [Schema::plainText('quoteText')]);
        $contentBlocks = Schema::matrix('contentBlocks', [$text, $quote]);
        $page = Schema::entryType('page', [$contentBlocks]);
        $pages = Schema::section('pages', [$page]);
        $source = Schema::entry($pages, $page, [
            'title' => 'With blocks',
            'contentBlocks' => [
                'new1' => ['type' => 'text', 'fields' => ['body' => 'First']],
                'new2' => ['type' => 'quote', 'fields' => ['quoteText' => 'Quoted']],
                'new3' => ['type' => 'text', 'fields' => ['body' => 'Last']],
            ],
        ]);
        $payload = $this->dolly()->export([$source]);

        Schema::updateField($contentBlocks->uid, ['entryTypes' => [$text]]);

        $warning = 'Blocks of type "Quote" in "Content Blocks" will be skipped: their entry type isn\'t allowed in that field in this environment.';
        $this->assertContains($warning, $this->dolly()->preview($payload)->roots[0]->warnings);

        $result = $this->dolly()->import($payload);
        $this->assertContains($warning, $result->roots[0]->warnings);
        $blocks = $this->importedEntry($result->roots[0]->elementId)->getFieldValue('contentBlocks')->status(null)->all();
        $this->assertSame(['First', 'Last'], array_map(fn(Entry $block) => $block->getFieldValue('body'), $blocks));
    }

    public function testASkippedRequiredFieldInANestedEntryDoesNotFailTheSave(): void
    {
        $body = Schema::plainText('body');
        $text = Schema::blockType('text', [Schema::required($body)]);
        $page = Schema::entryType('page', [Schema::matrix('contentBlocks', [$text])]);
        $pages = Schema::section('pages', [$page]);
        $source = Schema::entry($pages, $page, [
            'title' => 'With blocks',
            'contentBlocks' => ['new1' => ['type' => 'text', 'fields' => ['body' => 'First']]],
        ]);
        $payload = $this->dolly()->export([$source]);

        Schema::changeFieldType($body->uid, Number::class);

        $this->assertSame(['Content Blocks › Body'], $this->dolly()->preview($payload)->roots[0]->incompleteFields);

        $result = $this->dolly()->import($payload);
        $this->assertSame('created', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertSame(['Content Blocks › Body'], $result->roots[0]->incompleteFields);
        $blocks = $this->importedEntry($result->roots[0]->elementId)->getFieldValue('contentBlocks')->status(null)->all();
        $this->assertCount(1, $blocks);
        $this->assertNull($blocks[0]->getFieldValue('body'));
    }

    public function testAMissingSectionBlocksOnlyTheRootElementsInIt(): void
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $event = Schema::entryType('event');
        $events = Schema::section('events', [$event]);
        $story = Schema::entry($news, $article, ['title' => 'Story']);
        $party = Schema::entry($events, $event, ['title' => 'Party']);
        $payload = $this->dolly()->export([$party, $story]);

        Schema::deleteSection($events);

        $blocked = 'The section "Events" doesn\'t exist in this environment. Root elements in it can\'t be imported.';
        $plan = $this->dolly()->preview($payload);
        $this->assertSame('missing', $plan->drift->get('sections', $events->uid)?->state);
        $this->assertSame([$blocked, null], [$plan->roots[0]->blockedBy, $plan->roots[1]->blockedBy]);
        $this->assertSame(['Party', 'Story'], [$plan->roots[0]->title, $plan->roots[1]->title]);

        $result = $this->dolly()->import($payload);
        $this->assertSame(['failed', 'created'], [$result->roots[0]->status, $result->roots[1]->status]);
        $this->assertSame([$blocked], $result->roots[0]->errors);
        $this->assertSame('Story', $this->importedEntry($result->roots[1]->elementId)->title);
        $this->assertTrue($result->hasFailures());
    }

    public function testAPayloadFromADifferentCraftMajorIsRefused(): void
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $envelope = $this->envelope($this->dolly()->export([Schema::entry($news, $article, ['title' => 'Old'])]));
        $envelope['craftVersion'] = '4.12.3';
        $payload = 'dolly:v1:' . base64_encode(gzencode(json_encode($envelope)));

        $major = explode('.', Craft::$app->getVersion())[0];
        $refusal = "This Payload was exported from Craft 4, but this environment runs Craft $major. Payloads can't move between Craft major versions.";
        $this->assertSame($refusal, $this->dolly()->preview($payload)->refusal);
        $result = $this->dolly()->import($payload);
        $this->assertSame($refusal, $result->refusal);
        $this->assertSame([], $result->roots);
        $this->assertSame([], Plugin::getInstance()->getImportLog()->getEntries());
    }

    public function testPreviewWritesNothing(): void
    {
        $article = Schema::entryType('article', [Schema::plainText('summary')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Not yet', 'summary' => 'Text']);
        $payload = $this->dolly()->export([$source]);
        Craft::$app->getElements()->deleteElement($source, true);

        $plan = $this->dolly()->preview($payload);

        $this->assertFalse($plan->roots[0]->isBlocked());
        $this->assertNull(Entry::find()->uid($source->uid)->status(null)->trashed(null)->one());
        $this->assertSame(0, (int)Entry::find()->section('news')->status(null)->trashed(null)->count());
        $this->assertSame([], Plugin::getInstance()->getImportLog()->getEntries());
    }

    private function importedEntry(?int $id): Entry
    {
        $this->assertNotNull($id, 'The Import result has no element ID');
        $entry = Entry::find()->id($id)->status(null)->one();
        $this->assertNotNull($entry, "No entry with ID $id");

        return $entry;
    }

    private function dolly(): Dolly
    {
        return Plugin::getInstance()->getDolly();
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(string $payload): array
    {
        return json_decode(gzdecode(base64_decode(substr($payload, strlen('dolly:v1:')), true)), true, 512, JSON_THROW_ON_ERROR);
    }
}
