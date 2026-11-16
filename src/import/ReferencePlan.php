<?php

namespace simplygoodwork\craftportage\import;

use Craft;
use craft\base\ElementInterface;
use simplygoodwork\craftportage\references\ElementTypes;
use simplygoodwork\craftportage\references\Resolution;

/**
 * What an Import will do with one Reference: its Resolution in the Target environment and, for an Unresolved
 * reference, the user's choice: skip it (the default), Stub it, relate a replacement element instead or, for an asset,
 * fetch its file from the source URL or upload a file. Assets are never Stubbed.
 */
final class ReferencePlan
{
    public const CHOICE_SKIP = 'skip';
    public const CHOICE_STUB = 'stub';
    public const CHOICE_REPLACE = 'replace';
    public const CHOICE_FETCH = 'fetch';
    public const CHOICE_UPLOAD = 'upload';

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
     * @var string|null the choice the user has started but not finished ("replace" with no element picked, "upload"
     * with no file), which is planned as skip until it is complete
     */
    public ?string $pending = null;

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
            return $this->matched ??= ElementTypes::definition($this->elementType)
                ?->query()
                ->id($this->resolution->elementId)
                ->status(null)
                ->site('*')
                ->unique()
                ->one();
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
            self::CHOICE_UPLOAD => $this->fetch
                ? "Unresolved: the uploaded file will be saved into {$this->fetch->location()}"
                : 'Unresolved: an uploaded file will be saved',
            self::CHOICE_FETCH => $this->fetch
                ? "Unresolved: will be fetched from {$this->fetch->source->url} into {$this->fetch->location()}"
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
     * @return array{uid: string, elementType: string|null, title: string|null, choice: string|null, replacementId: int|null, replacementTitle: string|null, location?: string, filename?: string}
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
            ...($this->fetch ? ['location' => $this->fetch->location()] : []),
            ...($this->fetch?->source->isUpload() ? ['filename' => $this->fetch->asset->getFilename()] : []),
        ];
    }

    /**
     * The label of each choice in the Preview's select.
     *
     * @return array<string, string>
     */
    public static function choiceLabels(): array
    {
        return [
            self::CHOICE_SKIP => Craft::t('portage', 'Skip it'),
            self::CHOICE_STUB => Craft::t('portage', 'Create a Stub'),
            self::CHOICE_FETCH => Craft::t('portage', 'Fetch it from the source URL'),
            self::CHOICE_UPLOAD => Craft::t('portage', 'Upload a file'),
            self::CHOICE_REPLACE => Craft::t('portage', 'Pick a replacement'),
        ];
    }

    /**
     * What each choice did, as a phrase for the Import log.
     *
     * @return array<string, string>
     */
    public static function loggedOutcomes(): array
    {
        return [
            self::CHOICE_SKIP => Craft::t('portage', 'skipped'),
            self::CHOICE_STUB => Craft::t('portage', 'a Stub was created'),
            self::CHOICE_FETCH => Craft::t('portage', 'fetched from its source URL'),
            self::CHOICE_UPLOAD => Craft::t('portage', 'created from an uploaded file'),
            self::CHOICE_REPLACE => Craft::t('portage', 'replaced'),
        ];
    }

    private function replacementName(): string
    {
        $replacement = $this->replacement;

        return $replacement?->title !== null ? "\"$replacement->title\"" : '#' . ($replacement->id ?? '?');
    }

    /**
     * What the Reference's element type is called in messages and the Preview: "entry", "form".
     */
    public function typeName(): string
    {
        return ElementTypes::displayName($this->elementType);
    }

    /**
     * A name for the Reference in messages: its title, or its UID.
     */
    public function name(): string
    {
        return $this->title !== null ? "\"$this->title\"" : $this->uid;
    }
}
