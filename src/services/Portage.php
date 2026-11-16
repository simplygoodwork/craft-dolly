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
use simplygoodwork\craftportage\import\RootPlan;
use simplygoodwork\craftportage\import\RootResult;
use simplygoodwork\craftportage\jobs\ImportJob;
use simplygoodwork\craftportage\payload\InvalidPayloadException;
use simplygoodwork\craftportage\payload\PayloadCodec;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\references\ElementTypeRegistry;
use Throwable;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * The core service: Export, Preview and Import of Payload text. The CLI, the control panel and the tests all go through here.
 */
class Portage extends Component
{
    private ?FieldAdapterRegistry $adapters = null;

    private ?ElementTypeRegistry $elementTypes = null;

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
     * @param bool $queue lets a large Import (see `ImportPlan::shouldQueue()`) run in the queue instead: the Import log
     * entry is recorded as queued, a job is pushed with the Payload and exactly these choices, and the result says
     * it was queued. The control panel asks for this; the CLI and block paste never do, so they run inline
     */
    public function import(string $payload, array $choices = [], array $rootChoices = [], ?BlockTarget $target = null, bool $queue = false): ImportResult
    {
        $plan = $this->preview($payload, $choices, $rootChoices, $target);
        if ($plan->isRefused()) {
            return ImportResult::refused((string)$plan->refusal);
        }

        if ($queue && $plan->shouldQueue()) {
            return $this->enqueue($plan, $payload, $choices, $rootChoices);
        }

        $roots = (new Importer(adapters: $this->adapters()))->import($plan);
        $logId = Plugin::getInstance()->getImportLog()->record((string)$plan->payloadIdentity, $plan->source, $roots, $plan->choices());

        return new ImportResult($roots, payloadIdentity: $plan->payloadIdentity, logId: $logId);
    }

    /**
     * Runs an Import the queue was given, for its job: plans the Payload again with the same choices, which must be what
     * the user previewed, and imports one Root element at a time, with its result in the Import log as soon as it's done.
     * A Root element that fails doesn't stop the others. The Import log entry ends up completed, or failed if the Import
     * couldn't run at all. Runs as the user who queued it.
     *
     * Only the queue job calls this, once the user who queued the Import is the current user.
     *
     * @param string $fingerprint the fingerprint of the plan that was queued
     * @param (callable(int, int): void)|null $progress called with the number of Root elements done and the total, as each is done
     * @param array<string, string|int|array<string, string>> $choices as for `preview()`
     * @param array<string, array{mode?: string, section?: string}> $rootChoices as for `preview()`
     */
    public function runQueuedImport(int $logId, string $payload, array $choices, array $rootChoices, string $fingerprint, ?callable $progress = null): void
    {
        $log = Plugin::getInstance()->getImportLog();
        if (!$log->start($logId)) {
            // A retry of a job that already ran.
            return;
        }

        try {
            $plan = $this->preview($payload, $choices, $rootChoices);
            if ($plan->isRefused()) {
                $log->fail($logId, (string)$plan->refusal);
                return;
            }
            if (!hash_equals($fingerprint, $plan->fingerprint())) {
                $log->fail($logId, 'This environment changed after the Import was queued, so the Import no longer does what the Preview showed. Nothing was imported. Preview the Payload again.');
                return;
            }

            $indexes = array_flip(array_map('spl_object_id', $plan->roots));
            $total = count($plan->roots);
            $done = 0;
            $roots = (new Importer(adapters: $this->adapters()))->import(
                $plan,
                function(RootPlan $root, RootResult $result) use ($log, $logId, $indexes, $total, &$done, $progress): void {
                    $log->recordRoot($logId, $indexes[spl_object_id($root)], $result);
                    if ($progress !== null) {
                        $progress(++$done, $total);
                    }
                },
            );
            $log->complete($logId, $roots);
        } catch (Throwable $e) {
            Craft::$app->getErrorHandler()->logException($e);
            $log->fail($logId, 'The Import stopped unexpectedly. Root elements without a result weren\'t imported. The details are in the Craft logs.');
        }
    }

    /**
     * @param array<string, string|int|array<string, string>> $choices
     * @param array<string, array{mode?: string, section?: string}> $rootChoices
     */
    private function enqueue(ImportPlan $plan, string $payload, array $choices, array $rootChoices): ImportResult
    {
        $logId = Plugin::getInstance()->getImportLog()->recordQueued(
            (string)$plan->payloadIdentity,
            $plan->source,
            array_map(RootResult::notImported(...), $plan->roots),
            $plan->choices(),
        );

        try {
            Craft::$app->getQueue()->push(new ImportJob([
                'logId' => $logId,
                'userId' => (int)Craft::$app->getUser()->getId(),
                'payload' => $payload,
                'choices' => $choices,
                'rootChoices' => $rootChoices,
                'fingerprint' => $plan->fingerprint(),
            ]));
        } catch (Throwable $e) {
            Craft::$app->getErrorHandler()->logException($e);
            $reason = 'Craft couldn\'t add the Import to the queue, so nothing was imported. The details are in the Craft logs.';
            $log = Plugin::getInstance()->getImportLog();
            $log->start($logId);
            $log->fail($logId, $reason);

            return ImportResult::refused($reason);
        }

        return new ImportResult(payloadIdentity: $plan->payloadIdentity, logId: $logId, queued: true);
    }

    /**
     * @throws InvalidConfigException if the Shared key's env var isn't set in this environment
     */
    private function codec(): PayloadCodec
    {
        $settings = Plugin::getInstance()->getSettings();

        return new PayloadCodec(Craft::$app->getVersion(), $settings->resolveSharedKey(), $settings->maxPayloadSize);
    }

    /**
     * The element types a Payload may carry, built (and its registration event triggered) on first use.
     *
     * @throws InvalidConfigException if a registered element type isn't valid
     */
    public function getElementTypes(): ElementTypeRegistry
    {
        return $this->elementTypes ??= new ElementTypeRegistry();
    }

    private function adapters(): FieldAdapterRegistry
    {
        return $this->adapters ??= new FieldAdapterRegistry();
    }
}
