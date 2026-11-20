<?php

namespace simplygoodwork\craftdolly\references;

/**
 * The outcome of matching a Reference in the Target environment, and the rule that matched.
 */
final class Resolution
{
    public const RULE_UID = 'uid';
    public const RULE_NATURAL_KEY = 'naturalKey';

    private function __construct(
        public readonly ?int $elementId,
        public readonly ?string $rule,
    ) {
    }

    public static function matched(int $elementId, string $rule): self
    {
        return new self($elementId, $rule);
    }

    public static function unresolved(): self
    {
        return new self(null, null);
    }

    public function isResolved(): bool
    {
        return $this->elementId !== null;
    }
}
