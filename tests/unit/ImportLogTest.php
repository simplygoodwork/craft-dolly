<?php

namespace simplygoodwork\craftdolly\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\User;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\tests\support\Schema;

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
        $payload = Plugin::getInstance()->getDolly()->export([$first, $second]);

        $result = Plugin::getInstance()->getDolly()->import($payload);

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
        $payload = Plugin::getInstance()->getDolly()->export([Schema::entry($section, $entryType, ['title' => 'Again'])]);
        $other = Plugin::getInstance()->getDolly()->export([Schema::entry($section, $entryType, ['title' => 'Other'])]);

        $first = Plugin::getInstance()->getDolly()->import($payload);
        $second = Plugin::getInstance()->getDolly()->import($payload);
        $third = Plugin::getInstance()->getDolly()->import($other);

        $this->assertCount(3, Plugin::getInstance()->getImportLog()->getEntries());
        $this->assertSame($first->payloadIdentity, $second->payloadIdentity);
        $this->assertNotSame($first->payloadIdentity, $third->payloadIdentity);
    }

    public function testRecordsTheChoiceMadeForEachUnresolvedReference(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $kept = Schema::entry($topics, $topic, ['title' => 'Kept']);
        $stubbed = Schema::entry($topics, $topic, ['title' => 'Stubbed']);
        $skipped = Schema::entry($topics, $topic, ['title' => 'Skipped']);
        $replaced = Schema::entry($topics, $topic, ['title' => 'Replaced']);
        $replacement = Schema::entry($topics, $topic, ['title' => 'Replacement']);
        $article = Schema::entryType('article', [Schema::field(\craft\fields\Entries::class, 'relatedTopics')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, [
            'title' => 'Related',
            'relatedTopics' => [$kept->id, $stubbed->id, $skipped->id, $replaced->id],
        ]);
        $payload = Plugin::getInstance()->getDolly()->export([$source]);
        $uids = [$stubbed->uid, $skipped->uid, $replaced->uid];
        Schema::deleteElement($stubbed);
        Schema::deleteElement($skipped);
        Schema::deleteElement($replaced);

        Plugin::getInstance()->getDolly()->import($payload, [$uids[0] => 'stub', $uids[2] => $replacement->id]);

        $this->assertSame(
            [
                ['uid' => $uids[0], 'elementType' => 'entry', 'title' => 'Stubbed', 'choice' => 'stub', 'replacementId' => null, 'replacementTitle' => null],
                ['uid' => $uids[1], 'elementType' => 'entry', 'title' => 'Skipped', 'choice' => 'skip', 'replacementId' => null, 'replacementTitle' => null],
                ['uid' => $uids[2], 'elementType' => 'entry', 'title' => 'Replaced', 'choice' => 'replace', 'replacementId' => $replacement->id, 'replacementTitle' => '"Replacement"'],
            ],
            Plugin::getInstance()->getImportLog()->getEntries()[0]->choices,
        );
    }

    public function testARefusedPayloadIsNotLogged(): void
    {
        $result = Plugin::getInstance()->getDolly()->import('not a payload');

        $this->assertTrue($result->isRefused());
        $this->assertNull($result->logId);
        $this->assertSame([], Plugin::getInstance()->getImportLog()->getEntries());
    }
}
