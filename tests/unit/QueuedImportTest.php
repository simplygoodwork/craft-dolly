<?php

namespace simplygoodwork\craftdolly\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\events\ModelEvent;
use craft\fields\Assets;
use craft\models\EntryType;
use craft\models\Section;
use craft\web\UploadedFile;
use simplygoodwork\craftdolly\import\StagedUploads;
use simplygoodwork\craftdolly\models\ImportLogEntry;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\services\Dolly;
use simplygoodwork\craftdolly\tests\support\FileServer;
use simplygoodwork\craftdolly\tests\support\Schema;
use yii\base\Event;
use yii\queue\Queue;

/**
 * A control panel Import of more than 10 Root elements, or with an asset Fetch, runs in the queue and reports to its
 * Import log entry. The CLI and block paste never queue.
 */
class QueuedImportTest extends Unit
{
    /**
     * @var list<string>
     */
    private array $uploadedFiles = [];

    protected function _before(): void
    {
        $admin = User::find()->admin()->one();
        $this->assertNotNull($admin);
        Craft::$app->getUser()->setIdentity($admin);
    }

    protected function _after(): void
    {
        foreach ($this->uploadedFiles as $file) {
            @unlink($file);
        }
    }

    public function testAnImportOfTenRootElementsRunsInlineEvenWhenTheQueueIsAllowed(): void
    {
        [$section, $type] = $this->newsSection();
        $payload = $this->dolly()->export($this->entries($section, $type, 10));

        $result = $this->dolly()->import($payload, queue: true);

        $this->assertFalse($result->queued);
        $this->assertCount(10, $result->roots);
        $this->assertSame(ImportLogEntry::STATUS_COMPLETED, $this->log((int)$result->logId)->status);
        $this->assertSame(0, $this->waitingJobs());
    }

    public function testAnImportOfMoreThanTenRootElementsIsQueuedWithTheLogEntryPending(): void
    {
        [$section, $type] = $this->newsSection();
        $entries = $this->entries($section, $type, 11);
        $payload = $this->dolly()->export($entries);
        $this->deleteAll($entries);

        $result = $this->dolly()->import($payload, queue: true);

        $this->assertTrue($result->queued);
        $this->assertSame([], $result->roots);
        $this->assertSame(1, $this->waitingJobs());
        $log = $this->log((int)$result->logId);
        $this->assertSame(ImportLogEntry::STATUS_QUEUED, $log->status);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $log->payloadIdentity);
        $this->assertCount(11, $log->results);
        $this->assertSame(['not-imported'], array_values(array_unique(array_column($log->results, 'status'))));
        $this->assertSame(0, (int)Entry::find()->section('news')->status(null)->count(), 'Nothing is imported before the job runs');
    }

    public function testTheJobImportsEveryRootElementAndCompletesTheLogEntry(): void
    {
        [$section, $type] = $this->newsSection();
        $entries = $this->entries($section, $type, 11);
        $uids = array_map(fn(Entry $entry) => $entry->uid, $entries);
        $payload = $this->dolly()->export($entries);
        $this->deleteAll($entries);

        $result = $this->dolly()->import($payload, queue: true);
        $this->runQueue();

        $log = $this->log((int)$result->logId);
        $this->assertSame(ImportLogEntry::STATUS_COMPLETED, $log->status);
        $this->assertNull($log->error);
        $this->assertSame($uids, array_column($log->results, 'sourceUid'));
        $this->assertSame(array_fill(0, 11, 'created'), array_column($log->results, 'status'));
        $this->assertSame(11, (int)Entry::find()->section('news')->status(null)->count());
        $first = Entry::find()->uid($uids[0])->status(null)->one();
        $this->assertNotNull($first);
        $this->assertSame([$first->id, 'Entry 1', false], [$log->results[0]['elementId'], $first->title, $first->enabled]);
    }

    public function testTheJobRunsAsTheUserWhoQueuedItAndTheirIdentityIsRestored(): void
    {
        $editor = Schema::user('editor', ['admin' => true]);
        Craft::$app->getUser()->setIdentity($editor);
        [$section, $type] = $this->newsSection();
        $entries = $this->entries($section, $type, 11);
        $payload = $this->dolly()->export($entries);
        $this->deleteAll($entries);

        $result = $this->dolly()->import($payload, queue: true);
        $other = User::find()->admin()->id('not ' . $editor->id)->one();
        $this->assertNotNull($other);
        Craft::$app->getUser()->setIdentity($other);
        $this->runQueue();

        $this->assertSame($editor->id, $this->log((int)$result->logId)->userId);
        $this->assertSame($editor->id, Entry::find()->section('news')->status(null)->one()?->authorId);
        $this->assertSame($other->id, Craft::$app->getUser()->getId());
    }

    public function testOneRootElementFailingDoesNotStopTheOthers(): void
    {
        [$news, $article] = $this->newsSection();
        $page = Schema::entryType('page');
        $pages = Schema::section('pages', [$page]);
        $good = $this->entries($news, $article, 6);
        $doomed = [];
        foreach (range(1, 5) as $i) {
            $doomed[] = Schema::entry($pages, $page, ['title' => "Doomed $i"]);
        }
        $payload = $this->dolly()->export([...$good, ...$doomed]);
        $this->deleteAll([...$good, ...$doomed]);
        Schema::deleteSection($pages);

        $result = $this->dolly()->import($payload, queue: true);
        $this->runQueue();

        $log = $this->log((int)$result->logId);
        $this->assertSame(ImportLogEntry::STATUS_COMPLETED, $log->status);
        $this->assertSame(
            [...array_fill(0, 6, 'created'), ...array_fill(0, 5, 'failed')],
            array_column($log->results, 'status'),
        );
        $this->assertSame(6, (int)Entry::find()->section('news')->status(null)->count());
        $this->assertSame('The section "Pages" doesn\'t exist in this environment. Root elements in it can\'t be imported.', $log->results[6]['errors'][0] ?? null);
    }

    public function testAnUnexpectedFailureFailsTheLogEntryAndMarksTheUnimportedRootElements(): void
    {
        [$section, $type] = $this->newsSection();
        $entries = $this->entries($section, $type, 11);
        $payload = $this->dolly()->export($entries);
        $this->deleteAll($entries);

        $result = $this->dolly()->import($payload, queue: true);
        Schema::deleteSection($section);
        $this->runQueue();

        $log = $this->log((int)$result->logId);
        $this->assertSame(ImportLogEntry::STATUS_FAILED, $log->status);
        $this->assertStringContainsString('This environment changed after the Import was queued', (string)$log->error);
        $this->assertSame(array_fill(0, 11, 'failed'), array_column($log->results, 'status'));
        $this->assertSame($log->error, $log->results[0]['errors'][0]);
    }

    public function testRootChoicesArriveInTheJobExactlyAsPreviewed(): void
    {
        [$news, $article] = $this->newsSection();
        $entries = $this->entries($news, $article, 11);
        $payload = $this->dolly()->export($entries);
        $target = $entries[3];
        $targetUid = $target->uid;
        $others = array_filter($entries, fn(Entry $entry) => $entry->id !== $target->id);
        $this->deleteAll($others);

        $result = $this->dolly()->import($payload, rootChoices: [$targetUid => ['mode' => 'overwrite']], queue: true);
        $this->assertTrue($result->queued);
        $this->runQueue();

        $log = $this->log((int)$result->logId);
        $this->assertSame(ImportLogEntry::STATUS_COMPLETED, $log->status);
        $this->assertSame('overwritten', $log->results[3]['status']);
        $this->assertSame($target->id, $log->results[3]['elementId']);
        $this->assertNotNull($log->results[3]['draftId']);
        $this->assertSame(
            [...array_fill(0, 3, 'created'), 'overwritten', ...array_fill(0, 7, 'created')],
            array_column($log->results, 'status'),
        );
    }

    public function testAnAssetFetchQueuesTheImportAndTheJobFetchesIt(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files');
        $photo = Schema::asset($photos, 'photo.png', 'blog', ['title' => 'Red square']);
        $assetUid = $photo->uid;
        $article = Schema::entryType('article', [Schema::field(Assets::class, 'images')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Pictured', 'images' => [$photo->id]]);
        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($photo);

        // Without a fetch, a single Root element runs inline.
        $inline = $this->dolly()->import($payload, queue: true);
        $this->assertFalse($inline->queued);
        Schema::deleteElement($this->entryById($inline->roots[0]->elementId));

        $result = $this->dolly()->import($payload, [$assetUid => 'fetch'], queue: true);

        $this->assertTrue($result->queued);
        $this->assertNull(Asset::find()->uid($assetUid)->one(), 'The asset is fetched by the job');
        $this->runQueue();

        $log = $this->log((int)$result->logId);
        $this->assertSame(ImportLogEntry::STATUS_COMPLETED, $log->status);
        $fetched = Asset::find()->uid($assetUid)->one();
        $this->assertNotNull($fetched);
        $this->assertSame([$fetched->id], $this->entryById($log->results[0]['elementId'])->getFieldValue('images')->ids());
        $this->assertSame($fetched->id, $log->results[0]['fetchedAssets'][0]['elementId']);
        $this->assertSame('fetch', $log->choices[0]['choice']);
    }

    public function testAStagedUploadIsCarriedIntoTheJobAndDiscardedWhenItIsDone(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files');
        $archive = Schema::volume('archive');
        $photo = Schema::asset($photos, 'photo.png', '', ['title' => 'Red square']);
        $assetUid = $photo->uid;
        $article = Schema::entryType('article', [Schema::field(Assets::class, 'images')]);
        $news = Schema::section('news', [$article]);
        $entries = [Schema::entry($news, $article, ['title' => 'Pictured', 'images' => [$photo->id]])];
        foreach (range(2, 11) as $i) {
            $entries[] = Schema::entry($news, $article, ['title' => "Plain $i"]);
        }
        $payload = $this->dolly()->export($entries);
        $this->deleteAll($entries);
        Schema::deleteElement($photo);
        $upload = $this->stageUpload('red.png');
        $file = $upload['path'];
        $choice = ['choice' => 'upload', 'path' => $file, 'filename' => 'red.png', 'volume' => 'archive', 'folder' => 'uploads/2026'];

        $result = $this->dolly()->import($payload, [$assetUid => $choice], queue: true);

        $this->assertTrue($result->queued);
        $this->assertFileExists($file, 'The upload has to stay until the job has run');
        $this->runQueue();

        $this->assertSame(ImportLogEntry::STATUS_COMPLETED, $this->log((int)$result->logId)->status);
        $created = Asset::find()->uid($assetUid)->one();
        $this->assertNotNull($created);
        $this->assertSame(['archive', 'uploads/2026/', 'red.png'], [$created->getVolume()->handle, $created->getFolder()->path, $created->getFilename()]);
        $this->assertSame($archive->id, $created->volumeId);
        $this->assertSame('Red square', $created->title);
        $this->assertFileDoesNotExist($file, 'The job discards the upload when it is done');
    }

    public function testAFileOutsideTheUsersStagingDirectoryIsNeverDiscarded(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files');
        $photo = Schema::asset($photos, 'photo.png', '', ['title' => 'Red square']);
        $assetUid = $photo->uid;
        $article = Schema::entryType('article', [Schema::field(Assets::class, 'images')]);
        $news = Schema::section('news', [$article]);
        $entries = [Schema::entry($news, $article, ['title' => 'Pictured', 'images' => [$photo->id]])];
        foreach (range(2, 11) as $i) {
            $entries[] = Schema::entry($news, $article, ['title' => "Plain $i"]);
        }
        $payload = $this->dolly()->export($entries);
        $this->deleteAll($entries);
        Schema::deleteElement($photo);
        $file = sys_get_temp_dir() . '/dolly-test-' . uniqid() . '-red.png';
        copy(codecept_data_dir('files/photo.png'), $file);
        $this->uploadedFiles[] = $file;

        $result = $this->dolly()->import($payload, [$assetUid => ['choice' => 'upload', 'path' => $file, 'filename' => 'red.png']], queue: true);
        $this->runQueue();

        $this->assertSame(ImportLogEntry::STATUS_COMPLETED, $this->log((int)$result->logId)->status);
        $this->assertNotNull(Asset::find()->uid($assetUid)->one());
        $this->assertFileExists($file, 'A file the Import was given from elsewhere is not Dolly\'s to delete');
    }

    public function testARootElementFailingInsideItsTransactionDoesNotStopTheOthers(): void
    {
        [$section, $type] = $this->newsSection();
        $entries = $this->entries($section, $type, 11);
        $payload = $this->dolly()->export($entries);
        $this->deleteAll($entries);
        $refuse = function(ModelEvent $event): void {
            if ($event->sender instanceof Entry && $event->sender->title === 'Entry 4') {
                $event->isValid = false;
            }
        };
        Event::on(Entry::class, Entry::EVENT_BEFORE_SAVE, $refuse);

        try {
            $result = $this->dolly()->import($payload, queue: true);
            $this->runQueue();
        } finally {
            Event::off(Entry::class, Entry::EVENT_BEFORE_SAVE, $refuse);
        }

        $log = $this->log((int)$result->logId);
        $this->assertSame(ImportLogEntry::STATUS_COMPLETED, $log->status);
        $this->assertSame(
            [...array_fill(0, 3, 'created'), 'failed', ...array_fill(0, 7, 'created')],
            array_column($log->results, 'status'),
        );
        $this->assertSame(10, (int)Entry::find()->section('news')->status(null)->count());
        $this->assertNull($log->results[3]['elementId']);
    }

    public function testTheLogEntryIsInProgressWithTheResultsSoFarWhileTheJobRuns(): void
    {
        [$section, $type] = $this->newsSection();
        $entries = $this->entries($section, $type, 11);
        $payload = $this->dolly()->export($entries);
        $this->deleteAll($entries);
        $result = $this->dolly()->import($payload, queue: true);
        $seen = null;
        $watch = function(Event $event) use ($result, &$seen): void {
            if ($seen === null && $event->sender instanceof Entry && $event->sender->title === 'Entry 3') {
                $log = $this->log((int)$result->logId);
                $seen = [$log->status, array_column($log->results, 'status')];
            }
        };
        Event::on(Entry::class, Entry::EVENT_BEFORE_SAVE, $watch);

        try {
            $this->runQueue();
        } finally {
            Event::off(Entry::class, Entry::EVENT_BEFORE_SAVE, $watch);
        }

        $this->assertSame(
            [ImportLogEntry::STATUS_IN_PROGRESS, ['created', 'created', ...array_fill(0, 9, 'not-imported')]],
            $seen,
        );
    }

    public function testAnImportWhoseUserLostTheImportPermissionIsNotRun(): void
    {
        $editor = Schema::user('editor');
        Schema::grantPermissions($editor, ['accessCp', 'dolly-importContent']);
        Craft::$app->getUser()->setIdentity($editor);
        [$section, $type] = $this->newsSection();
        $entries = $this->entries($section, $type, 11);
        $payload = $this->dolly()->export($entries);
        $this->deleteAll($entries);

        $result = $this->dolly()->import($payload, queue: true);
        $this->assertTrue($result->queued);
        Schema::grantPermissions($editor, ['accessCp']);
        $this->runQueue();

        $log = $this->log((int)$result->logId);
        $this->assertSame(ImportLogEntry::STATUS_FAILED, $log->status);
        $this->assertSame('The user who queued the Import can no longer import content. Nothing was imported.', $log->error);
        $this->assertSame(array_fill(0, 11, 'failed'), array_column($log->results, 'status'));
        $this->assertSame(0, (int)Entry::find()->section('news')->status(null)->count());
    }

    public function testAnImportWhoseUserIsNoLongerActiveIsNotRun(): void
    {
        $editor = Schema::user('editor', ['admin' => true]);
        Craft::$app->getUser()->setIdentity($editor);
        [$section, $type] = $this->newsSection();
        $entries = $this->entries($section, $type, 11);
        $payload = $this->dolly()->export($entries);
        $this->deleteAll($entries);

        $result = $this->dolly()->import($payload, queue: true);
        Craft::$app->getUsers()->suspendUser($editor);
        $this->runQueue();

        $log = $this->log((int)$result->logId);
        $this->assertSame(ImportLogEntry::STATUS_FAILED, $log->status);
        $this->assertSame('The user who queued the Import is no longer an active user. Nothing was imported.', $log->error);
        $this->assertSame(0, (int)Entry::find()->section('news')->status(null)->count());
    }

    public function testAnUnexpectedErrorFailsTheLogEntryWithoutShowingItsDetails(): void
    {
        [$section, $type] = $this->newsSection();
        $entries = $this->entries($section, $type, 11);
        $payload = $this->dolly()->export($entries);
        $this->deleteAll($entries);
        $fingerprint = $this->dolly()->preview($payload)->fingerprint();
        $logId = Plugin::getInstance()->getImportLog()->recordQueued('0f', [], []);

        $this->dolly()->runQueuedImport($logId, $payload, [], [], $fingerprint, function(): void {
            throw new \RuntimeException('SQLSTATE[HY000]: secret details');
        });

        $log = $this->log($logId);
        $this->assertSame(ImportLogEntry::STATUS_FAILED, $log->status);
        $this->assertSame('The Import stopped unexpectedly. Root elements without a result weren\'t imported. The details are in the Craft logs.', $log->error);
    }

    public function testAnImportInProgressThatWentQuietForTooLongFailsInsteadOfStayingInProgress(): void
    {
        [$section, $type] = $this->newsSection();
        $payload = $this->dolly()->export($this->entries($section, $type, 11));
        $result = $this->dolly()->import($payload, queue: true);
        $log = Plugin::getInstance()->getImportLog();
        $this->assertTrue($log->start((int)$result->logId));
        $this->assertSame(ImportLogEntry::STATUS_IN_PROGRESS, $this->log((int)$result->logId)->status, 'A running job is left alone');

        Craft::$app->getDb()->createCommand()->update('{{%dolly_importlog}}', ['dateUpdated' => gmdate('Y-m-d H:i:s', time() - 7200)], ['id' => $result->logId])->execute();

        $entry = $this->log((int)$result->logId);
        $this->assertSame(ImportLogEntry::STATUS_FAILED, $entry->status);
        $this->assertSame('The Import stopped before it finished. Root elements without a result weren\'t imported.', $entry->error);
        $this->assertSame(array_fill(0, 11, 'failed'), array_column($entry->results, 'status'));
    }

    public function testTheJobRefusesToRunIfAResolvedReferenceNowMatchesAnotherElement(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $kept = Schema::entry($topics, $topic, ['title' => 'Kept']);
        $article = Schema::entryType('article', [Schema::field(\craft\fields\Entries::class, 'relatedTopics')]);
        $news = Schema::section('news', [$article]);
        $entries = [Schema::entry($news, $article, ['title' => 'Related', 'relatedTopics' => [$kept->id]])];
        foreach (range(2, 11) as $i) {
            $entries[] = Schema::entry($news, $article, ['title' => "Plain $i"]);
        }
        $payload = $this->dolly()->export($entries);
        $this->deleteAll($entries);
        Schema::changeUid($kept);

        $result = $this->dolly()->import($payload, queue: true);
        Schema::deleteElement($kept);
        Schema::entry($topics, $topic, ['title' => 'Kept']);
        $this->runQueue();

        $log = $this->log((int)$result->logId);
        $this->assertSame(ImportLogEntry::STATUS_FAILED, $log->status);
        $this->assertStringContainsString('This environment changed after the Import was queued', (string)$log->error);
        $this->assertSame(0, (int)Entry::find()->section('news')->status(null)->count());
    }

    public function testAQueuePushThatFailsFailsTheLogEntryAndRefusesInsteadOfLeavingItQueued(): void
    {
        [$section, $type] = $this->newsSection();
        $payload = $this->dolly()->export($this->entries($section, $type, 11));
        $refuse = function(): void {
            throw new \RuntimeException('The queue is down');
        };
        Event::on(Queue::class, Queue::EVENT_BEFORE_PUSH, $refuse);

        try {
            $result = $this->dolly()->import($payload, queue: true);
        } finally {
            Event::off(Queue::class, Queue::EVENT_BEFORE_PUSH, $refuse);
        }

        $reason = 'Craft couldn\'t add the Import to the queue, so nothing was imported. The details are in the Craft logs.';
        $this->assertSame($reason, $result->refusal);
        $entries = Plugin::getInstance()->getImportLog()->getEntries();
        $this->assertCount(1, $entries);
        $this->assertSame([ImportLogEntry::STATUS_FAILED, $reason], [$entries[0]->status, $entries[0]->error]);
    }

    public function testAnEncryptedPayloadGoesThroughTheQueue(): void
    {
        [$section, $type] = $this->newsSection();
        $entries = $this->entries($section, $type, 11);
        $payload = Schema::withSharedKey('correct horse battery staple', fn() => $this->dolly()->export($entries));
        $this->assertStringStartsWith('dolly:v1e:', $payload);
        $this->deleteAll($entries);

        $result = Schema::withSharedKey('correct horse battery staple', function() use ($payload) {
            $result = $this->dolly()->import($payload, queue: true);
            $this->runQueue();

            return $result;
        });

        $this->assertTrue($result->queued);
        $this->assertSame(ImportLogEntry::STATUS_COMPLETED, $this->log((int)$result->logId)->status);
        $this->assertSame(11, (int)Entry::find()->section('news')->status(null)->count());
    }

    public function testTheDuplicateWarningSaysQueuedForAQueuedImportAndIgnoresAFailedOne(): void
    {
        [$section, $type] = $this->newsSection();
        $entries = $this->entries($section, $type, 11);
        $payload = $this->dolly()->export($entries);
        $this->deleteAll($entries);

        $queued = $this->dolly()->import($payload, queue: true);
        $warnings = $this->dolly()->preview($payload)->warnings;
        $this->assertCount(1, $warnings);
        $this->assertMatchesRegularExpression("/^This Payload is already queued for import here \\(Import #$queued->logId, queued on /", $warnings[0]);

        Plugin::getInstance()->getImportLog()->start((int)$queued->logId);
        Plugin::getInstance()->getImportLog()->fail((int)$queued->logId, 'Nothing was imported.');
        $this->assertSame([], $this->dolly()->preview($payload)->warnings);
    }

    public function testTheProgressOfTheJobIsReportedAfterEachRootElement(): void
    {
        [$section, $type] = $this->newsSection();
        $entries = $this->entries($section, $type, 11);
        $payload = $this->dolly()->export($entries);
        $this->deleteAll($entries);
        $fingerprint = $this->dolly()->preview($payload)->fingerprint();
        $logId = Plugin::getInstance()->getImportLog()->recordQueued('0f', [], []);
        $reported = [];

        $this->dolly()->runQueuedImport($logId, $payload, [], [], $fingerprint, function(int $done, int $total) use (&$reported): void {
            $reported[] = "$done/$total";
        });

        $this->assertSame(['1/11', '2/11', '3/11', '4/11', '5/11', '6/11', '7/11', '8/11', '9/11', '10/11', '11/11'], $reported);
    }

    public function testWithoutAskingForTheQueueEveryImportRunsInline(): void
    {
        [$section, $type] = $this->newsSection();
        $payload = $this->dolly()->export($this->entries($section, $type, 12));

        $result = $this->dolly()->import($payload);

        $this->assertFalse($result->queued);
        $this->assertCount(12, $result->roots);
        $this->assertSame(0, $this->waitingJobs());
    }

    /**
     * @return array{Section, EntryType}
     */
    private function newsSection(): array
    {
        $type = Schema::entryType('article');

        return [Schema::section('news', [$type]), $type];
    }

    /**
     * @return list<Entry>
     */
    private function entries(Section $section, EntryType $type, int $count): array
    {
        return array_map(fn(int $i) => Schema::entry($section, $type, ['title' => "Entry $i"]), range(1, $count));
    }

    /**
     * @param iterable<Entry> $entries
     */
    private function deleteAll(iterable $entries): void
    {
        foreach ($entries as $entry) {
            Schema::deleteElement($entry);
        }
    }

    /**
     * Keeps a file the way the utility does when a user uploads it for an unresolved asset.
     *
     * @return array{token: string, path: string, filename: string}
     */
    private function stageUpload(string $name): array
    {
        $temp = sys_get_temp_dir() . '/dolly-test-' . uniqid() . '-' . $name;
        copy(codecept_data_dir('files/photo.png'), $temp);
        $this->uploadedFiles[] = $temp;
        $staged = (new StagedUploads((int)Craft::$app->getUser()->getId()))->stage(new UploadedFile(['name' => $name, 'tempName' => $temp, 'error' => UPLOAD_ERR_OK, 'size' => (int)filesize($temp)]));
        $this->assertNotNull($staged);

        return $staged;
    }

    private function log(int $id): ImportLogEntry
    {
        $entry = Plugin::getInstance()->getImportLog()->getEntryById($id);
        $this->assertNotNull($entry, "No Import log entry $id");

        return $entry;
    }

    private function entryById(?int $id): Entry
    {
        $entry = Entry::find()->id($id)->status(null)->one();
        $this->assertNotNull($entry, "No entry $id");

        return $entry;
    }

    private function waitingJobs(): int
    {
        // Craft queues jobs of its own as it saves elements, so count only Dolly's.
        return (int)(new Query())->from(Table::QUEUE)->where(['like', 'job', 'ImportJob'])->count();
    }

    private function runQueue(): void
    {
        Craft::$app->getQueue()->run();
    }

    private function dolly(): Dolly
    {
        return Plugin::getInstance()->getDolly();
    }
}
