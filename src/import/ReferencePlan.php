<?php

namespace simplygoodwork\craftportage\import;

use craft\base\ElementInterface;
use simplygoodwork\craftportage\references\ElementTypes;
use simplygoodwork\craftportage\references\Resolution;

/**
 * What an Import will do with one Reference: its Resolution in the Target environment and, for an Unresolved
 * reference, the user's choice: skip it (the default), Stub it, or relate a replacement element instead.
 */
final class ReferencePlan
{
    public const CHOICE_SKIP = 'skip';
    public const CHOICE_STUB = 'stub';
    public const CHOICE_REPLACE = 'replace';

    /**
     * @var string|null what happens to an Unresolved reference, or null when the Reference is resolved
     */
    public ?string $choice = null;

    /**
     * @var list<string> the choices the user can make for an Unresolved reference
     */
    public array $availableChoices = [];

    /**
     * @var ElementInterface|null the unsaved Stub the Import will create, when the choice is to Stub
     */
    public ?ElementInterface $stub = null;

    /**
     * @var ElementInterface|null the element related instead, when the choice is a replacement
     */
    public ?ElementInterface $replacement = null;

    private ?ElementInterface $matched = null;

    /**
     * @param array<string, mixed> $reference the Reference as carried in the Payload
     */
    public function __construct(
        public readonly string $uid,
        public readonly ?string $elementType,
        public readonly ?string $title,
        public readonly Resolution $resolution,
        public readonly array $reference = [],
    ) {
    }

    /**
     * Returns the element a relation to this Reference will point at: the matched element, the replacement, or the
     * (possibly unsaved) Stub. Returns null when the Reference will be skipped.
     */
    public function target(): ?ElementInterface
    {
        if ($this->resolution->elementId !== null) {
            $class = ElementTypes::classFor($this->elementType);

            return $this->matched ??= $class ? $class::find()->id($this->resolution->elementId)->status(null)->site('*')->unique()->one() : null;
        }

        return $this->replacement ?? $this->stub;
    }

    /**
     * A name for the Reference in messages: its title, or its UID.
     */
    public function name(): string
    {
        return $this->title !== null ? "\"$this->title\"" : $this->uid;
    }
}
