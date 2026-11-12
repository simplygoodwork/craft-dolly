<?php

namespace simplygoodwork\craftportage\services;

use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use simplygoodwork\craftportage\import\RootResult;
use simplygoodwork\craftportage\models\ImportLogEntry;
use simplygoodwork\craftportage\records\ImportLogRecord;
use yii\base\Component;

/**
 * Records Imports in the Import log and reads them back.
 */
class ImportLog extends Component
{
    /**
     * @param array<string, mixed> $source
     * @param list<RootResult> $results
     * @return int the log entry ID
     */
    public function record(string $payloadIdentity, array $source, array $results): int
    {
        $record = new ImportLogRecord();
        $record->userId = Craft::$app->getUser()->getId();
        $record->payloadIdentity = $payloadIdentity;
        $record->source = $source;
        $record->results = array_map(fn(RootResult $result) => $result->toArray(), $results);
        $record->save(false);

        return $record->id;
    }

    /**
     * @return list<ImportLogEntry> newest first
     */
    public function getEntries(): array
    {
        $records = ImportLogRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->all();

        return array_values(array_map(fn(ImportLogRecord $record) => $this->entry($record), $records));
    }

    private function entry(ImportLogRecord $record): ImportLogEntry
    {
        return new ImportLogEntry(
            id: (int)$record->id,
            userId: $record->userId !== null ? (int)$record->userId : null,
            dateCreated: DateTimeHelper::toDateTime($record->dateCreated),
            payloadIdentity: $record->payloadIdentity,
            source: $this->decodeJson($record->source),
            results: array_values($this->decodeJson($record->results)),
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
