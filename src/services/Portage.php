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
     * element, and the Resolution of every Reference with the choice for each Unresolved reference. Writes nothing.
     * A Payload that can't be decoded, or a choice that can't be carried out, is refused.
     *
     * @param array<string, string|int> $choices for Unresolved references, by Reference UID: "skip" (the default),
     * "stub", or the ID of a replacement element
     * @param array<string, array{mode?: string, section?: string}> $rootChoices for Root elements, by Root element UID:
     * the `mode` ("create", the default, or "overwrite" the matching entry) and the target `section` (a UID or handle;
     * the default is the Payload's own section)
     */
    public function preview(string $payload, array $choices = [], array $rootChoices = []): ImportPlan
    {
        try {
            $decoded = $this->codec()->decode($payload);
        } catch (InvalidPayloadException $e) {
            return ImportPlan::refused($e->getMessage());
        }

        return (new ImportPlanner($this->adapters()))->plan($decoded, $choices, $rootChoices);
    }

    /**
     * Imports Payload text by executing its Preview, and records the Import in the Import log. A refused Payload
     * imports nothing and isn't logged.
     *
     * @param array<string, string|int> $choices for Unresolved references, as for `preview()`
     * @param array<string, array{mode?: string, section?: string}> $rootChoices for Root elements, as for `preview()`
     */
    public function import(string $payload, array $choices = [], array $rootChoices = []): ImportResult
    {
        $plan = $this->preview($payload, $choices, $rootChoices);
        if ($plan->isRefused()) {
            return ImportResult::refused((string)$plan->refusal);
        }

        $roots = (new Importer())->import($plan);
        $logId = Plugin::getInstance()->getImportLog()->record((string)$plan->payloadIdentity, $plan->source, $roots, $plan->choices());

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
