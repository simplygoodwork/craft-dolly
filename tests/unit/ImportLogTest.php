<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\User;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\tests\support\Schema;

/**
 * Every Import is recorded in the Import log.
 */
class ImportLogTest extends Unit
{
    public function testRecordsOneEntryPerImportWithTheResultForEachRootElement(): void
    {
        $importer = User::find()->admin()->one();
        $this->assertNotNull($importer);
        Craft::$app->getUser()->setIdentity($importer);

        $entryType = Schema::entryType('article');
        $section = Schema::section('news', [$entryType]);
        $first = Schema::entry($section, $entryType, ['title' => 'First']);
        $second = Schema::entry($section, $entryType, ['title' => 'Second']);
        $payload = Plugin::getInstance()->getPortage()->export([$first, $second]);

        $result = Plugin::getInstance()->getPortage()->import($payload);

        $entries = Plugin::getInstance()->getImportLog()->getEntries();
        $this->assertCount(1, $entries);
        $logEntry = $entries[0];
        $this->assertSame($result->logId, $logEntry->id);
        $this->assertSame($importer->id, $logEntry->userId);
        $this->assertLessThan(60, abs(time() - $logEntry->dateCreated->getTimestamp()));
        $this->assertSame($result->payloadIdentity, $logEntry->payloadIdentity);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $logEntry->payloadIdentity);
        $this->assertSame($importer->username, $logEntry->source['exportedBy']);
        $this->assertSame(Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), $logEntry->source['siteUrl']);
        $this->assertSame(
            [
                [$first->uid, 'created', $result->roots[0]->elementId],
                [$second->uid, 'created', $result->roots[1]->elementId],
            ],
            array_map(fn(array $root) => [$root['sourceUid'], $root['status'], $root['elementId']], $logEntry->results),
        );
    }

    public function testTheSamePayloadHasTheSameIdentityEachTimeItIsImported(): void
    {
        $entryType = Schema::entryType('article');
        $section = Schema::section('news', [$entryType]);
        $payload = Plugin::getInstance()->getPortage()->export([Schema::entry($section, $entryType, ['title' => 'Again'])]);
        $other = Plugin::getInstance()->getPortage()->export([Schema::entry($section, $entryType, ['title' => 'Other'])]);

        $first = Plugin::getInstance()->getPortage()->import($payload);
        $second = Plugin::getInstance()->getPortage()->import($payload);
        $third = Plugin::getInstance()->getPortage()->import($other);

        $this->assertCount(3, Plugin::getInstance()->getImportLog()->getEntries());
        $this->assertSame($first->payloadIdentity, $second->payloadIdentity);
        $this->assertNotSame($first->payloadIdentity, $third->payloadIdentity);
    }

    public function testARefusedPayloadIsNotLogged(): void
    {
        $result = Plugin::getInstance()->getPortage()->import('not a payload');

        $this->assertTrue($result->isRefused());
        $this->assertNull($result->logId);
        $this->assertSame([], Plugin::getInstance()->getImportLog()->getEntries());
    }
}
