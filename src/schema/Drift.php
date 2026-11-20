<?php

namespace simplygoodwork\craftdolly\schema;

/**
 * The Drift of one Schema reference between the Source and Target environments.
 */
final class Drift
{
    public const MATCHED = 'matched';
    public const DRIFTED = 'drifted';
    public const TYPE_CHANGED = 'type-changed';
    public const MISSING = 'missing';

    public function __construct(
        public readonly string $kind,
        public readonly string $uid,
        public readonly string $name,
        public readonly string $state,
        /** What the Drift means for the Import, or null when matched */
        public readonly ?string $message,
    ) {
    }

    /**
     * Whether values that depend on this Schema reference are skipped.
     */
    public function isSkipped(): bool
    {
        return $this->state === self::MISSING || $this->state === self::TYPE_CHANGED;
    }

    /**
     * What the Drift means for the Import, for the Preview.
     */
    public function outcome(): string
    {
        return $this->message ?? 'Imported as is.';
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'uid' => $this->uid,
            'name' => $this->name,
            'state' => $this->state,
            'message' => $this->message,
        ];
    }
}
