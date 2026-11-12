<?php

namespace simplygoodwork\craftportage\import;

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
     */
    public function __construct(
        public readonly DriftReport $drift = new DriftReport(),
        public readonly array $roots = [],
        public readonly array $references = [],
        public readonly ?string $refusal = null,
        public readonly ?string $payloadIdentity = null,
        public readonly array $source = [],
    ) {
    }

    public static function refused(string $reason): self
    {
        return new self(refusal: $reason);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
