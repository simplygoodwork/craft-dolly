<?php

namespace simplygoodwork\craftportage\services;

use Craft;
use craft\elements\Entry;
use simplygoodwork\craftportage\export\Exporter;
use simplygoodwork\craftportage\fields\FieldAdapterRegistry;
use simplygoodwork\craftportage\import\Importer;
use simplygoodwork\craftportage\import\ImportPlan;
use simplygoodwork\craftportage\import\ImportPlanner;
use simplygoodwork\craftportage\import\ImportResult;
use simplygoodwork\craftportage\payload\InvalidPayloadException;
use simplygoodwork\craftportage\payload\PayloadCodec;
use simplygoodwork\craftportage\Plugin;
use yii\base\Component;

/**
 * The core service: Export, Preview and Import of Payload text. The CLI, the control panel and the tests all go through here.
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
     * Previews the Import of Payload text: the Drift of every Schema reference and what will happen to every Root
     * element. Writes nothing. A Payload that can't be decoded is refused.
     */
    public function preview(string $payload): ImportPlan
    {
        try {
            $decoded = $this->codec()->decode($payload);
        } catch (InvalidPayloadException $e) {
            return ImportPlan::refused($e->getMessage());
        }

        return (new ImportPlanner($this->adapters()))->plan($decoded);
    }

    /**
     * Imports Payload text by executing its Preview, and records the Import in the Import log. A refused Payload
     * imports nothing and isn't logged.
     */
    public function import(string $payload): ImportResult
    {
        $plan = $this->preview($payload);
        if ($plan->isRefused()) {
            return ImportResult::refused((string)$plan->refusal);
        }

        $roots = (new Importer())->import($plan);
        $logId = Plugin::getInstance()->getImportLog()->record((string)$plan->payloadIdentity, $plan->source, $roots);

        return new ImportResult($roots, payloadIdentity: $plan->payloadIdentity, logId: $logId);
    }

    private function codec(): PayloadCodec
    {
        return new PayloadCodec(Craft::$app->getVersion());
    }

    private function adapters(): FieldAdapterRegistry
    {
        return $this->adapters ??= new FieldAdapterRegistry();
    }
}
