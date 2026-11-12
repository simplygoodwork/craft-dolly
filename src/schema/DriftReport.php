<?php

namespace simplygoodwork\craftportage\schema;

/**
 * The Drift of every Schema reference in a Payload.
 */
final class DriftReport
{
    /**
     * @var array<string, array<string, Drift>> kind => UID => Drift
     */
    private array $drift = [];

    public function add(Drift $drift): void
    {
        $this->drift[$drift->kind][$drift->uid] = $drift;
    }

    /**
     * Returns the Drift of a Schema reference, or null if the Payload doesn't describe it.
     */
    public function get(string $kind, mixed $uid): ?Drift
    {
        return is_string($uid) ? ($this->drift[$kind][$uid] ?? null) : null;
    }

    /**
     * @return list<Drift>
     */
    public function all(): array
    {
        return array_merge(...array_values(array_map('array_values', $this->drift)));
    }
}
