<?php

namespace simplygoodwork\craftdolly\services;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use DateTime;
use simplygoodwork\craftdolly\import\RootResult;
use simplygoodwork\craftdolly\jobs\ImportJob;
use simplygoodwork\craftdolly\models\ImportLogEntry;
use simplygoodwork\craftdolly\records\ImportLogRecord;
use yii\base\Component;
use yii\db\ActiveQuery;

/**
 * Records Imports in the Import log and reads them back.
 */
class ImportLog extends Component
{
    /**
     * @param array<string, mixed> $source
     * @param list<RootResult> $results
     * @param list<array<string, mixed>> $choices the choice made for each Unresolved reference
     * @return int the log entry ID
     */
    public function record(string $payloadIdentity, array $source, array $results, array $choices = []): int
    {
        $record = new ImportLogRecord();
        $record->userId = Craft::$app->getUser()->getId();
        $record->payloadIdentity = $payloadIdentity;
        $record->source = $source;
        $record->results = array_map(fn(RootResult $result) => $result->toArray(), $results);
        $record->choices = $choices;
        $record->save(false);

        return $record->id;
    }

    /**
     * Records an Import that the queue will run, with every Root element not imported yet.
     *
     * @param array<string, mixed> $source
     * @param list<RootResult> $notImported
     * @param list<array<string, mixed>> $choices
     * @return int the log entry ID
     */
    public function recordQueued(string $payloadIdentity, array $source, array $notImported, array $choices = []): int
    {
        $record = new ImportLogRecord();
        $record->userId = Craft::$app->getUser()->getId();
        $record->payloadIdentity = $payloadIdentity;
        $record->source = $source;
        $record->results = array_map(fn(RootResult $result) => $result->toArray(), $notImported);
        $record->choices = $choices;
        $record->status = ImportLogEntry::STATUS_QUEUED;
        $record->save(false);

        return $record->id;
    }

    /**
     * Moves a queued Import to in progress.
     *
     * @return bool false if it isn't queued, because the job already started it
     */
    public function start(int $id): bool
    {
        return (bool)ImportLogRecord::updateAll(
            ['status' => ImportLogEntry::STATUS_IN_PROGRESS, 'dateUpdated' => Db::prepareDateForDb(new DateTime())],
            ['id' => $id, 'status' => ImportLogEntry::STATUS_QUEUED],
        );
    }

    /**
     * Replaces the result of the Root element at a position in the plan, as soon as it is imported.
     */
    public function recordRoot(int $id, int $index, RootResult $result): void
    {
        $this->update($id, function(ImportLogRecord $record) use ($index, $result): void {
            $results = $this->decodeJson($record->results);
            $results[$index] = $result->toArray();
            $record->results = array_values($results);
        });
    }

    /**
     * Completes an Import in progress: every Root element has its result.
     *
     * @param list<RootResult> $results
     */
    public function complete(int $id, array $results): void
    {
        $this->update($id, function(ImportLogRecord $record) use ($results): void {
            $record->results = array_map(fn(RootResult $result) => $result->toArray(), $results);
            $record->status = ImportLogEntry::STATUS_COMPLETED;
        });
    }

    /**
     * Fails an Import that couldn't run. Root elements that weren't imported are marked failed with the reason.
     */
    public function fail(int $id, string $error): void
    {
        $this->update($id, function(ImportLogRecord $record) use ($error): void {
            $record->results = RootResult::failNotImported($this->decodeJson($record->results), $error);
            $record->status = ImportLogEntry::STATUS_FAILED;
            $record->error = $error;
        });
    }

    /**
     * @param callable(ImportLogRecord): void $change
     */
    private function update(int $id, callable $change): void
    {
        $record = ImportLogRecord::findOne($id);
        if ($record) {
            $change($record);
            $record->save(false);
        }
    }

    /**
     * Fails an Import whose job went quiet for longer than a job can run: its worker died, and nothing else will ever
     * finish it.
     */
    private function failIfStalled(ImportLogRecord $record): ImportLogRecord
    {
        $touched = DateTimeHelper::toDateTime($record->dateUpdated);
        if ($record->status !== ImportLogEntry::STATUS_IN_PROGRESS || !$touched || $touched->getTimestamp() > time() - ImportJob::TTR) {
            return $record;
        }

        $this->fail((int)$record->id, 'The Import stopped before it finished. Root elements without a result weren\'t imported.');

        return ImportLogRecord::findOne($record->id) ?? $record;
    }

    /**
     * @return list<ImportLogEntry> newest first
     */
    public function getEntries(): array
    {
        return $this->entries(ImportLogRecord::find());
    }

    public function getEntryById(int $id): ?ImportLogEntry
    {
        $record = ImportLogRecord::findOne($id);

        return $record ? $this->entry($record) : null;
    }

    /**
     * @return list<ImportLogEntry> the Imports of a Payload, newest first
     */
    public function getEntriesForPayload(string $payloadIdentity): array
    {
        return $this->entries(ImportLogRecord::find()->where(['payloadIdentity' => $payloadIdentity]));
    }

    /**
     * @param ActiveQuery $query a query for `ImportLogRecord`s (not typed as generic, since `ActiveQuery` isn't generic in Yii 2.0.52 and earlier)
     * @return list<ImportLogEntry> newest first
     */
    private function entries(ActiveQuery $query): array
    {
        /** @var ImportLogRecord[] $records */
        $records = $query->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])->all();

        return array_values(array_map(fn(ImportLogRecord $record) => $this->entry($record), $records));
    }

    private function entry(ImportLogRecord $record): ImportLogEntry
    {
        $record = $this->failIfStalled($record);

        return new ImportLogEntry(
            id: (int)$record->id,
            userId: $record->userId !== null ? (int)$record->userId : null,
            dateCreated: DateTimeHelper::toDateTime($record->dateCreated),
            payloadIdentity: $record->payloadIdentity,
            source: $this->decodeJson($record->source),
            results: array_values($this->decodeJson($record->results)),
            choices: array_values($this->decodeJson($record->choices)),
            status: (string)$record->status,
            error: $record->error,
        );
    }

    /**
     * @return array<mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_string($value)) {
            $value = Json::decode($value);
        }

        return is_array($value) ? $value : [];
    }
}
