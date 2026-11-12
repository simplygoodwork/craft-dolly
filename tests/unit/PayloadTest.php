<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\fields\Entries;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\tests\support\Schema;

/**
 * The Payload text format and envelope, and refusal of Payloads that can't be decoded.
 */
class PayloadTest extends Unit
{
    public function testPayloadIsPrefixedGzippedBase64JsonWithTheEnvelope(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft']);
        $summary = Schema::plainText('summary');
        $related = Schema::field(Entries::class, 'relatedTopics');
        $article = Schema::entryType('article', [$summary, $related]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, [
            'title' => 'Hello',
            'summary' => 'Text',
            'relatedTopics' => [$craft->id],
        ]);

        $payload = Plugin::getInstance()->getPortage()->export([$source]);

        $this->assertStringStartsWith('portage:v1:', $payload);
        $envelope = $this->decode($payload);

        $this->assertSame(1, $envelope['formatVersion']);
        $this->assertSame(Craft::$app->getVersion(), $envelope['craftVersion']);
        $this->assertSame('1.0.0', $envelope['pluginVersion']);
        $this->assertSame(['siteUrl', 'environment', 'exportedAt', 'exportedBy'], array_keys($envelope['source']));
        $this->assertSame(Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), $envelope['source']['siteUrl']);
        $this->assertNotFalse(strtotime($envelope['source']['exportedAt']));

        $site = Craft::$app->getSites()->getPrimarySite();
        $this->assertSame([$site->uid], array_keys($envelope['schema']['sites']));
        $this->assertSame([$news->uid], array_keys($envelope['schema']['sections']));
        $this->assertSame([$article->uid], array_keys($envelope['schema']['entryTypes']));
        $this->assertEqualsCanonicalizing([$summary->uid, $related->uid], array_keys($envelope['schema']['fields']));

        $this->assertCount(1, $envelope['roots']);
        $this->assertSame($source->uid, $envelope['roots'][0]['uid']);
        $this->assertSame('Hello', $envelope['roots'][0]['sites'][$site->uid]['title']);

        $this->assertSame([$craft->uid], array_keys($envelope['references']));
        $this->assertSame('entry', $envelope['references'][$craft->uid]['elementType']);
        $this->assertSame('Craft', $envelope['references'][$craft->uid]['title']);
    }

    /**
     * @dataProvider malformedPayloads
     */
    public function testRefusesAMalformedPayloadWithAClearMessage(string $payload, string $message): void
    {
        $result = Plugin::getInstance()->getPortage()->import($payload);

        $this->assertTrue($result->isRefused());
        $this->assertStringContainsString($message, (string)$result->refusal);
        $this->assertSame([], $result->roots);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedPayloads(): array
    {
        return [
            'bad prefix' => ['portage:v9:' . base64_encode(gzencode('{"roots":[]}')), 'should start with "portage:v1:"'],
            'not a Payload at all' => ['hello', 'should start with "portage:v1:"'],
            'bad base64' => ['portage:v1:not*base64!', 'not valid base64'],
            'bad gzip' => ['portage:v1:' . base64_encode('plain bytes'), 'could not be decompressed'],
            'bad JSON' => ['portage:v1:' . base64_encode(gzencode('{"roots": [')), 'not valid JSON'],
            'JSON without roots' => ['portage:v1:' . base64_encode(gzencode('{"formatVersion": 1}')), 'no Root elements'],
        ];
    }

    public function testAcceptsAPayloadWithSurroundingWhitespace(): void
    {
        $entryType = Schema::entryType('article');
        $section = Schema::section('news', [$entryType]);
        $source = Schema::entry($section, $entryType, ['title' => 'Pasted']);

        $payload = Plugin::getInstance()->getPortage()->export([$source]);
        $result = Plugin::getInstance()->getPortage()->import("\n  $payload \n");

        $this->assertFalse($result->isRefused());
        $this->assertNotNull($result->roots[0]->elementId);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $payload): array
    {
        return json_decode(gzdecode(base64_decode(substr($payload, strlen('portage:v1:')), true)), true, 512, JSON_THROW_ON_ERROR);
    }
}
