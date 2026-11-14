<?php

namespace simplygoodwork\craftportage\import;

use simplygoodwork\craftportage\models\ImportLogEntry;
use simplygoodwork\craftportage\schema\DriftReport;

/**
 * The Preview of an Import: either a refusal, or the Drift of every Schema reference and a plan for every Root element.
 *
 * Import executes the same plan, so the Preview always shows what the Import will do.
 */
final class ImportPlan
{
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
     * A hash of what the Import will do: each Root element's mode and section, and the choice for each Unresolved
     * reference with where its asset goes. Two plans with the same fingerprint import the same thing.
     */
    public function fingerprint(): string
    {
        $roots = array_map(fn(RootPlan $root) => [$root->sourceUid, $root->mode, $root->section?->uid, $root->blockedBy], $this->roots);
        $references = array_map(fn(ReferencePlan $reference) => [
            $reference->uid,
            $reference->choice,
            $reference->replacement?->id,
            $reference->fetch?->location(),
            $reference->fetch?->source->describe(),
            $reference->fetch?->source->uploadPath,
        ], array_filter($this->references, fn(ReferencePlan $reference) => !$reference->resolution->isResolved()));

        return hash('sha256', json_encode([$this->payloadIdentity, $roots, array_values($references)], JSON_THROW_ON_ERROR));
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
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
