<?php

namespace simplygoodwork\craftdolly\import;

/**
 * The outcome of an Import: either a refusal (nothing was imported), a result per Root element, or for an Import that
 * was queued, only the Import log entry the job reports to.
 */
final class ImportResult
{
    /**
     * @param list<RootResult> $roots
     */
    public function __construct(
        public readonly array $roots = [],
        public readonly ?string $refusal = null,
        public readonly ?string $payloadIdentity = null,
        public readonly ?int $logId = null,
        public readonly bool $queued = false,
    ) {
    }

    public static function refused(string $reason): self
    {
        return new self(refusal: $reason);
    }

    public function hasFailures(): bool
    {
        foreach ($this->roots as $root) {
            if ($root->status === RootResult::STATUS_FAILED) {
                return true;
            }
        }

        return false;
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
