<?php

namespace simplygoodwork\craftportage\services;

use benf\neo\elements\Block;
use Craft;
use craft\elements\Entry;
use simplygoodwork\craftportage\export\Exporter;
use simplygoodwork\craftportage\fields\FieldAdapterRegistry;
use simplygoodwork\craftportage\import\BlockTarget;
use simplygoodwork\craftportage\import\Importer;
use simplygoodwork\craftportage\import\ImportPlan;
use simplygoodwork\craftportage\import\ImportPlanner;
use simplygoodwork\craftportage\import\ImportResult;
use simplygoodwork\craftportage\payload\InvalidPayloadException;
use simplygoodwork\craftportage\payload\PayloadCodec;
use simplygoodwork\craftportage\Plugin;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * The core service: Export, Preview and Import of Payload text. The CLI, the control panel and the tests all go through here.
 */
class Portage extends Component
{
    private ?FieldAdapterRegistry $adapters = null;

    /**
     * Exports Root elements as Payload text.
     *
     * @param array<Entry|Block> $roots whole entries, or Matrix and Neo blocks to paste into a field
     * @param list<int>|null $siteIds limits the Payload to the content of these sites, for a user who can't see the others;
     * null exports every site the entries exist in
     * @throws InvalidConfigException if the Shared key's env var isn't set in this environment
     */
    public function export(array $roots, ?array $siteIds = null): string
    {
        return $this->codec()->encode((new Exporter($this->adapters()))->export($roots, $siteIds));
    }

    /**
     * Previews the Import of Payload text: the Drift of every Schema reference and what will happen to every Root
     * element, and the Resolution of every Reference with the choice for each Unresolved reference. Writes nothing.
     * A Payload that can't be decoded, or a choice that can't be carried out, is refused.
     *
     * @param array<string, string|int|array<string, string>> $choices for Unresolved references, by Reference UID: "skip" (the default),
     * "stub", the ID of a replacement element, or for an asset "fetch" (from its source URL into its own volume and
     * folder) or `['choice' => 'fetch', 'volume' => volume UID or handle, 'folder' => folder path]`
     * @param array<string, array{mode?: string, section?: string}> $rootChoices for Root elements, by Root element UID:
     * the `mode` ("create", the default, or "overwrite" the matching entry) and the target `section` (a UID or handle;
     * the default is the Payload's own section)
     * @param BlockTarget|null $target the Matrix or Neo field of a draft that a Payload of blocks is pasted into. A Payload of
     * blocks is refused without one, and a Payload of entries with one
     */
    public function preview(string $payload, array $choices = [], array $rootChoices = [], ?BlockTarget $target = null): ImportPlan
    {
        try {
            $decoded = $this->codec()->decode($payload);
        } catch (InvalidPayloadException|InvalidConfigException $e) {
            return ImportPlan::refused($e->getMessage());
        }

        return (new ImportPlanner($this->adapters()))->plan($decoded, $choices, $rootChoices, $target);
    }

    /**
     * Imports Payload text by executing its Preview, and records the Import in the Import log. A refused Payload
     * imports nothing and isn't logged.
     *
     * @param array<string, string|int|array<string, string>> $choices for Unresolved references, as for `preview()`
     * @param array<string, array{mode?: string, section?: string}> $rootChoices for Root elements, as for `preview()`
     * @param BlockTarget|null $target the field of a draft that blocks are pasted into, as for `preview()`: the blocks are
     * added to the draft as normal edits, after the blocks it already has
     */
    public function import(string $payload, array $choices = [], array $rootChoices = [], ?BlockTarget $target = null): ImportResult
    {
        $plan = $this->preview($payload, $choices, $rootChoices, $target);
        if ($plan->isRefused()) {
            return ImportResult::refused((string)$plan->refusal);
        }

        $roots = (new Importer(adapters: $this->adapters()))->import($plan);
        $logId = Plugin::getInstance()->getImportLog()->record((string)$plan->payloadIdentity, $plan->source, $roots, $plan->choices());

        return new ImportResult($roots, payloadIdentity: $plan->payloadIdentity, logId: $logId);
    }

    /**
     * @throws InvalidConfigException if the Shared key's env var isn't set in this environment
     */
    private function codec(): PayloadCodec
    {
        $settings = Plugin::getInstance()->getSettings();

        return new PayloadCodec(Craft::$app->getVersion(), $settings->resolveSharedKey(), $settings->maxPayloadSize);
    }

    private function adapters(): FieldAdapterRegistry
    {
        return $this->adapters ??= new FieldAdapterRegistry();
    }
}
