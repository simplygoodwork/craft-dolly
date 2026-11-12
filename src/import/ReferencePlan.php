<?php

namespace simplygoodwork\craftportage\import;

use Craft;
use craft\base\ElementInterface;
use simplygoodwork\craftportage\references\ElementTypes;
use simplygoodwork\craftportage\references\Resolution;

/**
 * What an Import will do with one Reference: its Resolution in the Target environment and, for an Unresolved
 * reference, the user's choice: skip it (the default), Stub it, relate a replacement element instead or, for an asset,
 * fetch its file from the source URL. Assets are never Stubbed.
 */
final class ReferencePlan
{
    public const CHOICE_SKIP = 'skip';
    public const CHOICE_STUB = 'stub';
    public const CHOICE_REPLACE = 'replace';
    public const CHOICE_FETCH = 'fetch';

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

    /**
     * @var AssetFetch|null how the asset will be fetched, when the choice is to fetch it
     */
    public ?AssetFetch $fetch = null;

    /**
     * @var string|null why an unresolved asset can't be fetched, or null if it can (or it isn't an asset)
     */
    public ?string $fetchUnavailable = null;

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
     * (possibly unsaved) Stub or fetched asset. Returns null when the Reference will be skipped.
     */
    public function target(): ?ElementInterface
    {
        if ($this->resolution->elementId !== null) {
            $class = ElementTypes::classFor($this->elementType);

            return $this->matched ??= $class ? $class::find()->id($this->resolution->elementId)->status(null)->site('*')->unique()->one() : null;
        }

        return $this->replacement ?? $this->stub ?? $this->fetch?->asset;
    }

    /**
     * What the Import will do with the Reference, for the Preview: which rule matched it, or for an Unresolved
     * reference, what happens to it.
     */
    public function outcome(): string
    {
        if ($this->resolution->isResolved()) {
            return $this->resolution->rule === Resolution::RULE_NATURAL_KEY ? 'Matched by Natural key' : 'Matched by UID';
        }

        return match ($this->choice) {
            self::CHOICE_STUB => 'Unresolved: a Stub will be created',
            self::CHOICE_REPLACE => sprintf('Unresolved: replaced with %s', $this->replacementName()),
            self::CHOICE_FETCH => $this->fetch
                ? "Unresolved: will be fetched from {$this->fetch->url} into {$this->fetch->location()}"
                : 'Unresolved: will be fetched',
            default => match (true) {
                $this->elementType === 'user' => sprintf(
                    'Unresolved: skipped in user fields. As an author, %s will be credited instead.',
                    ($importer = Craft::$app->getUser()->getIdentity()) ? "\"{$importer->getName()}\"" : 'nobody',
                ),
                $this->fetchUnavailable !== null => "Unresolved: will be skipped. $this->fetchUnavailable",
                default => 'Unresolved: will be skipped',
            },
        };
    }

    /**
     * The choice for an Unresolved reference, as the Import log records it.
     *
     * @return array{uid: string, elementType: string|null, title: string|null, choice: string|null, replacementId: int|null, replacementTitle: string|null}
     */
    public function toLogArray(): array
    {
        return [
            'uid' => $this->uid,
            'elementType' => $this->elementType,
            'title' => $this->title,
            'choice' => $this->choice,
            'replacementId' => $this->replacement?->id,
            'replacementTitle' => $this->replacement ? $this->replacementName() : null,
        ];
    }

    private function replacementName(): string
    {
        $replacement = $this->replacement;

        return $replacement?->title !== null ? "\"$replacement->title\"" : '#' . ($replacement->id ?? '?');
    }

    /**
     * A name for the Reference in messages: its title, or its UID.
     */
    public function name(): string
    {
        return $this->title !== null ? "\"$this->title\"" : $this->uid;
    }
}
