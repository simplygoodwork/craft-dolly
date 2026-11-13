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
            'data after the gzip stream' => ['portage:v1:' . base64_encode(gzencode('{"roots": []}') . 'smuggled'), 'could not be decompressed'],
            'bad JSON' => ['portage:v1:' . base64_encode(gzencode('{"roots": [')), 'not valid JSON'],
            'JSON without roots' => ['portage:v1:' . base64_encode(gzencode('{"formatVersion": 1}')), 'no Root elements'],
        ];
    }

    /**
     * @dataProvider envelopesNamingAnUnsupportedElementType
     * @param callable(array<string, mixed>): array<string, mixed> $tamper
     */
    public function testRefusesAnEnvelopeNamingAnElementTypeOutsideTheWhitelist(callable $tamper): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft']);
        $article = Schema::entryType('article', [Schema::field(Entries::class, 'relatedTopics')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Hello', 'relatedTopics' => [$craft->id]]);
        $envelope = $tamper($this->decode(Plugin::getInstance()->getPortage()->export([$source])));

        $result = Plugin::getInstance()->getPortage()->import($this->encode($envelope));

        $this->assertSame('The Payload names an element type Portage doesn\'t support: "craft\\elements\\GlobalSet". It can\'t be imported.', $result->refusal);
        $this->assertSame([], $result->roots);
    }

    /**
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function envelopesNamingAnUnsupportedElementType(): array
    {
        return [
            'a Root element' => [function(array $envelope) {
                $envelope['roots'][0]['elementType'] = 'craft\\elements\\GlobalSet';
                return $envelope;
            }],
            'a Reference' => [function(array $envelope) {
                $uid = array_key_first($envelope['references']);
                $envelope['references'][$uid]['elementType'] = 'craft\\elements\\GlobalSet';
                return $envelope;
            }],
        ];
    }

    /**
     * @dataProvider malformedEnvelopes
     * @param callable(array<string, mixed>): array<string, mixed> $tamper
     */
    public function testRefusesAMalformedEnvelope(callable $tamper, string $refusal): void
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Hello']);
        $envelope = $tamper($this->decode(Plugin::getInstance()->getPortage()->export([$source])));

        $result = Plugin::getInstance()->getPortage()->import($this->encode($envelope));

        $this->assertSame($refusal, $result->refusal);
        $this->assertSame([], $result->roots);
    }

    /**
     * @return array<string, array{callable(array<string, mixed>): array<string, mixed>, string}>
     */
    public static function malformedEnvelopes(): array
    {
        $set = fn(string $key, mixed $value) => function(array $envelope) use ($key, $value) {
            $envelope[$key] = $value;
            return $envelope;
        };
        $unset = fn(string $key) => function(array $envelope) use ($key) {
            unset($envelope[$key]);
            return $envelope;
        };

        return [
            'a newer format version' => [$set('formatVersion', 2), "This Payload uses format version 2, which this version of Portage can't read. Update Portage in this environment to import it."],
            'no format version' => [$unset('formatVersion'), "The Payload is damaged: it doesn't say which format version it uses."],
            'a string format version' => [$set('formatVersion', '1'), "The Payload is damaged: it doesn't say which format version it uses."],
            'Root elements keyed by name' => [fn(array $envelope) => [...$envelope, 'roots' => ['first' => $envelope['roots'][0]]], 'The Payload is damaged: its Root elements are malformed.'],
            'a Root element that isn\'t an object' => [fn(array $envelope) => [...$envelope, 'roots' => [...$envelope['roots'], 'entry']], 'The Payload is damaged: its Root elements are malformed.'],
            'references that aren\'t an object' => [$set('references', 'none'), 'The Payload is damaged: its "references" are malformed.'],
            'a Reference that isn\'t an object' => [$set('references', ['some-uid' => 'entry']), 'The Payload is damaged: its "references" are malformed.'],
            'schema that isn\'t an object' => [$set('schema', 42), 'The Payload is damaged: its "schema" is malformed.'],
            'source that isn\'t an object' => [$set('source', 'prod'), 'The Payload is damaged: its "source" is malformed.'],
        ];
    }

    public function testRefusesAPayloadLargerThanTheLimit(): void
    {
        $payload = 'portage:v1:' . str_repeat('A', 2000);

        $result = Schema::withPortageSettings(['maxPayloadSize' => 1000], fn() => Plugin::getInstance()->getPortage()->import($payload));

        $this->assertSame('This Payload is too large: this environment accepts Payloads of up to 1000 bytes. Raise the "maxPayloadSize" setting in config/portage.php to import it.', $result->refusal);
        $this->assertSame([], $result->roots);
    }

    public function testRefusesAGzipBombWithoutDecompressingItInFull(): void
    {
        // 256 MB of spaces, compressed to a few hundred KB, built without holding it all in memory.
        $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]);
        $gzipped = '';
        $chunk = str_repeat(' ', 1024 * 1024);
        for ($i = 0; $i < 256; $i++) {
            $gzipped .= deflate_add($deflate, $chunk, ZLIB_NO_FLUSH);
        }
        $gzipped .= deflate_add($deflate, '', ZLIB_FINISH);
        $payload = 'portage:v1:' . base64_encode($gzipped);
        unset($chunk, $gzipped);

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $result = Schema::withPortageSettings(['maxPayloadSize' => 1024 * 1024], fn() => Plugin::getInstance()->getPortage()->import($payload));

        $this->assertSame('This Payload is too large: this environment accepts Payloads of up to 1048576 bytes. Raise the "maxPayloadSize" setting in config/portage.php to import it.', $result->refusal);
        $this->assertLessThan(16 * 1024 * 1024, memory_get_peak_usage() - $before);
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
     * @param array<string, mixed> $envelope
     */
    private function encode(array $envelope): string
    {
        return 'portage:v1:' . base64_encode(gzencode(json_encode($envelope, JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $payload): array
    {
        return json_decode(gzdecode(base64_decode(substr($payload, strlen('portage:v1:')), true)), true, 512, JSON_THROW_ON_ERROR);
    }
}
