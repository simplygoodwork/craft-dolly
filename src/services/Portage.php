<?php

namespace simplygoodwork\craftportage\services;

use craft\elements\Entry;
use simplygoodwork\craftportage\export\Exporter;
use simplygoodwork\craftportage\fields\FieldAdapterRegistry;
use simplygoodwork\craftportage\import\Importer;
use simplygoodwork\craftportage\import\ImportResult;
use simplygoodwork\craftportage\payload\InvalidPayloadException;
use simplygoodwork\craftportage\payload\PayloadCodec;
use simplygoodwork\craftportage\Plugin;
use yii\base\Component;

/**
 * The core service: Payload text in and out. The CLI, the control panel and the tests all go through here.
 */
class Portage extends Component
{
    private ?FieldAdapterRegistry $adapters = null;

    /**
     * Exports Root elements as Payload text.
     *
     * @param Entry[] $roots
     */
    public function export(array $roots): string
    {
        return $this->codec()->encode((new Exporter($this->adapters()))->export($roots));
    }

    /**
     * Imports Payload text. A Payload that can't be decoded is refused and nothing is imported.
     */
    public function import(string $payload): ImportResult
    {
        try {
            $decoded = $this->codec()->decode($payload);
        } catch (InvalidPayloadException $e) {
            return ImportResult::refused($e->getMessage());
        }

        $roots = (new Importer($this->adapters()))->import($decoded->envelope);
        $source = is_array($decoded->envelope['source'] ?? null) ? $decoded->envelope['source'] : [];
        $logId = Plugin::getInstance()->getImportLog()->record($decoded->identity, $source, $roots);

        return new ImportResult($roots, payloadIdentity: $decoded->identity, logId: $logId);
    }

    private function codec(): PayloadCodec
    {
        return new PayloadCodec();
    }

    private function adapters(): FieldAdapterRegistry
    {
        return $this->adapters ??= new FieldAdapterRegistry();
    }
}
