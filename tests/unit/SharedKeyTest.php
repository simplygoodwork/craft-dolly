<?php

namespace simplygoodwork\craftdolly\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\services\Dolly;
use simplygoodwork\craftdolly\tests\support\Schema;

/**
 * The Shared key: encrypted, authenticated Payloads, and refusal of Payloads that don't match the environment's key.
 */
class SharedKeyTest extends Unit
{
    public function testWithASharedKeyExportIsEncryptedAndImportsWithTheSameKey(): void
    {
        $source = $this->sourceEntry('Secret plans');

        $result = Schema::withSharedKey('correct horse battery staple', function() use ($source) {
            $payload = $this->dolly()->export([$source]);
            $this->assertStringStartsWith('dolly:v1e:', $payload);
            $this->assertStringNotContainsString(base64_encode(gzencode('Secret plans')), $payload);

            return $this->dolly()->import($payload);
        });

        $this->assertNull($result->refusal);
        $this->assertSame('Secret plans', Entry::find()->id($result->roots[0]->elementId)->status(null)->one()?->title);
    }

    public function testWithASharedKeyAnUnencryptedPayloadIsRefused(): void
    {
        $payload = $this->dolly()->export([$this->sourceEntry('Plain')]);

        $result = Schema::withSharedKey('correct horse battery staple', fn() => $this->dolly()->import($payload));

        $this->assertSame(
            "This Payload isn't encrypted, but this environment has a Shared key configured, so it only accepts Payloads encrypted with that key. Export it again from an environment with the same Shared key.",
            $result->refusal,
        );
        $this->assertSame([], $result->roots);
    }

    public function testAPayloadEncryptedWithADifferentSharedKeyIsRefused(): void
    {
        $source = $this->sourceEntry('Secret plans');
        $payload = Schema::withSharedKey('the source key', fn() => $this->dolly()->export([$source]));

        $result = Schema::withSharedKey('another key', fn() => $this->dolly()->import($payload));

        $this->assertSame(self::CANT_DECRYPT, $result->refusal);
        $this->assertSame([], $result->roots);
    }

    public function testATamperedEncryptedPayloadIsRefused(): void
    {
        $source = $this->sourceEntry('Secret plans');

        $result = Schema::withSharedKey('the key', function() use ($source) {
            $payload = $this->dolly()->export([$source]);
            $ciphertext = base64_decode(substr($payload, strlen('dolly:v1e:')), true);
            $ciphertext[-1] = chr(ord($ciphertext[-1]) ^ 1);

            return $this->dolly()->import('dolly:v1e:' . base64_encode($ciphertext));
        });

        $this->assertSame(self::CANT_DECRYPT, $result->refusal);
        $this->assertSame([], $result->roots);
    }

    public function testWithoutASharedKeyAnEncryptedPayloadIsRefused(): void
    {
        $source = $this->sourceEntry('Secret plans');
        $payload = Schema::withSharedKey('the source key', fn() => $this->dolly()->export([$source]));

        $result = Schema::withSharedKey(null, fn() => $this->dolly()->import($payload));

        $this->assertSame(
            "This Payload is encrypted, but this environment has no Shared key configured, so it can't be decrypted. Configure the same Shared key as the Source environment to import it.",
            $result->refusal,
        );
        $this->assertSame([], $result->roots);
    }

    public function testASharedKeyWhoseEnvVarIsNotSetRefusesImportAndExport(): void
    {
        $source = $this->sourceEntry('Secret plans');
        $plainPayload = $this->dolly()->export([$source]);

        Schema::withSharedKeyEnvVarUnset(function() use ($source, $plainPayload) {
            $message = 'The Shared key setting refers to the env var "DOLLY_MISSING_KEY", which isn\'t set in this environment. Set it to the same Shared key as every other environment.';

            $this->assertSame($message, $this->dolly()->import($plainPayload)->refusal);
            $this->expectExceptionMessage($message);
            $this->dolly()->export([$source]);
        });
    }

    public function testTheSharedKeySettingSavesAnEnvVarReferenceButNeverALiteral(): void
    {
        $plugin = Plugin::getInstance();
        $plugins = Craft::$app->getPlugins();
        $projectConfig = Craft::$app->getProjectConfig();

        $this->assertFalse($plugins->savePluginSettings($plugin, ['sharedKey' => 'correct horse battery staple']));
        $this->assertSame(
            ['Set the Shared key to an env var, such as $DOLLY_SHARED_KEY, so the key itself is never saved to project config.'],
            $plugin->getSettings()->getErrors('sharedKey'),
        );
        $this->assertNull($projectConfig->get('plugins.dolly.settings.sharedKey'));

        $this->assertTrue($plugins->savePluginSettings($plugin, ['sharedKey' => '$DOLLY_SHARED_KEY']));
        $this->assertSame('$DOLLY_SHARED_KEY', $projectConfig->get('plugins.dolly.settings.sharedKey'));

        $this->assertTrue($plugins->savePluginSettings($plugin, ['sharedKey' => '']));
        $this->assertSame('', (string)$projectConfig->get('plugins.dolly.settings.sharedKey'));
    }

    private const CANT_DECRYPT = "This Payload can't be decrypted: it was encrypted with a different Shared key, or it was changed after Export. Make sure this environment has the same Shared key as the Source environment, and that the Payload was copied in full.";

    private function sourceEntry(string $title): Entry
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);

        return Schema::entry($news, $article, ['title' => $title]);
    }

    private function dolly(): Dolly
    {
        return Plugin::getInstance()->getDolly();
    }
}
