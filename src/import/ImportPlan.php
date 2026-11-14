<?php

namespace simplygoodwork\craftportage\import;

use simplygoodwork\craftportage\models\ImportLogEntry;
use simplygoodwork\craftportage\schema\Drift;
use simplygoodwork\craftportage\schema\DriftReport;

/**
 * The Preview of an Import: either a refusal, or the Drift of every Schema reference and a plan for every Root element.
 *
 * Import executes the same plan, so the Preview always shows what the Import will do.
 */
final class ImportPlan
{
    /**
     * An Import of more Root elements than this runs in the queue.
     */
    public const QUEUE_ROOT_THRESHOLD = 10;

    /**
     * @param list<RootPlan> $roots
     * @param array<string, mixed> $source the Payload's Source environment metadata
     * @param array<string, ReferencePlan> $references by Reference UID
     * @param list<ImportLogEntry> $earlierImports the Imports of this same Payload already in the Import log, newest first
     * @param list<string> $warnings about the whole Payload, which don't block the Import
     * @param array<string, mixed> $envelope the decoded Payload, for the Preview's "View as JSON"
     */
    public function __construct(
        public readonly DriftReport $drift = new DriftReport(),
        public readonly array $roots = [],
        public readonly array $references = [],
        public readonly ?string $refusal = null,
        public readonly ?string $payloadIdentity = null,
        public readonly array $source = [],
        public readonly array $earlierImports = [],
        public readonly array $warnings = [],
        public readonly array $envelope = [],
        public readonly ?string $refusalUid = null,
        public readonly ?BlockTarget $target = null,
    ) {
    }

    /**
     * @param string|null $choiceUid the Reference or Root element UID whose choice caused the refusal, if one did
     */
    public static function refused(string $reason, ?string $choiceUid = null): self
    {
        return new self(refusal: $reason, refusalUid: $choiceUid);
    }

    /**
     * A hash of what the Import will do: each Root element's mode, section and the entry an overwrite targets, the
     * element each resolved Reference matched, the Drift, and the choice for each
     * Unresolved reference with where its asset goes. Two plans with the same fingerprint import the same thing.
     */
    public function fingerprint(): string
    {
        $roots = array_map(fn(RootPlan $root) => [$root->sourceUid, $root->mode, $root->section?->uid, $root->blockedBy, $root->overwrite?->canonical->id], $this->roots);
        $matches = array_map(fn(ReferencePlan $reference) => [$reference->uid, $reference->resolution->rule, $reference->resolution->elementId], array_filter($this->references, fn(ReferencePlan $reference) => $reference->resolution->isResolved()));
        $drift = array_map(fn(Drift $drift) => [$drift->kind, $drift->uid, $drift->state], $this->drift->all());
        $references = array_map(fn(ReferencePlan $reference) => [
            $reference->uid,
            $reference->choice,
            $reference->replacement?->id,
            $reference->fetch?->location(),
            $reference->fetch?->source->describe(),
            $reference->fetch?->source->uploadPath,
        ], array_filter($this->references, fn(ReferencePlan $reference) => !$reference->resolution->isResolved()));

        $target = $this->target ? [$this->target->owner->id, $this->target->field->uid] : null;

        return hash('sha256', json_encode([$this->payloadIdentity, $roots, array_values($references), $target, array_values($matches), $drift], JSON_THROW_ON_ERROR));
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }

    /**
     * Whether a control panel Import of this plan runs in the queue: more than the threshold of Root elements, or any
     * asset Fetch from a source URL. Pasting blocks into a draft never does, since the editor needs the blocks in
     * its draft when the paste is done. The CLI doesn't ask.
     */
    public function shouldQueue(): bool
    {
        if ($this->isRefused() || $this->target !== null) {
            return false;
        }
        if (count($this->roots) > self::QUEUE_ROOT_THRESHOLD) {
            return true;
        }
        foreach ($this->roots as $root) {
            foreach ($root->isBlocked() ? [] : $root->fetches as $reference) {
                if ($reference->fetch && !$reference->fetch->source->isUpload()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The choice for each Unresolved reference, as the Import log records it.
     *
     * @return list<array<string, mixed>>
     */
    public function choices(): array
    {
        $unresolved = array_filter($this->references, fn(ReferencePlan $reference) => !$reference->resolution->isResolved());

        return array_values(array_map(fn(ReferencePlan $reference) => $reference->toLogArray(), $unresolved));
    }

    /**
     * Whether the Import would import anything: the Payload isn't refused and at least one Root element isn't blocked.
     */
    public function hasImportableRoots(): bool
    {
        foreach ($this->isRefused() ? [] : $this->roots as $root) {
            if (!$root->isBlocked()) {
                return true;
            }
        }

        return false;
    }
}
