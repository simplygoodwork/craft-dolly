<?php

namespace simplygoodwork\craftportage\import;

use craft\elements\Entry;
use craft\models\Section;
use simplygoodwork\craftportage\references\Resolution;

/**
 * What an Import will do with one Root element: the unsaved entry to create, or why the Root element is blocked,
 * plus everything that will be skipped or imported imperfectly.
 */
final class RootPlan
{
    /**
     * @var list<SitePlan> the entry's content for each of its other sites, saved after the entry
     */
    public array $sites = [];

    /**
     * @var string|null why the Root element can't be imported, or null if it can
     */
    public ?string $blockedBy = null;

    /**
     * @var Entry|null the entry the Import will save, fully populated but unsaved
     */
    public ?Entry $entry = null;

    /**
     * @var list<string> things that will be skipped or changed without blocking the Root element
     */
    public array $warnings = [];

    /**
     * @var list<array{uid: string, elementType: string|null, title: string|null, field: string}>
     */
    public array $unresolvedReferences = [];

    /**
     * @var list<string> names of required fields that will be left empty
     */
    public array $incompleteFields = [];

    /**
     * @var array<string, ReferencePlan> References the Root element relates to that will be Stubbed, by UID
     */
    public array $stubs = [];

    public const MODE_CREATE = 'create';
    public const MODE_OVERWRITE = 'overwrite';

    /**
     * @var Resolution|null the existing entry this Root element matches in the Target environment (by UID, then by
     * Natural key in the target section), or null if none does
     */
    public ?Resolution $match = null;

    /**
     * @var Entry|null the matched entry, loaded
     */
    public ?Entry $matchedEntry = null;

    /**
     * @var list<string> the Import modes the user can choose: "create", plus "overwrite" when the Root element matches an
     * entry that it can overwrite
     */
    public array $availableModes = [self::MODE_CREATE];

    /**
     * @var string the Import mode: "create" a new entry (the default) or "overwrite" the matched entry
     */
    public string $mode = self::MODE_CREATE;

    /**
     * @var string|null why a matched entry can't be overwritten, or null if it can (or nothing matched)
     */
    public ?string $overwriteUnavailable = null;

    /**
     * @var Section|null the Target section: the Payload's own section, or the one the user chose
     */
    public ?Section $section = null;

    /**
     * @var OverwritePlan|null the draft the Import will create on the matched entry, when the mode is "overwrite"
     */
    public ?OverwritePlan $overwrite = null;

    /**
     * @var Placement|null where a new entry in a Structure section will go, or null when it isn't one
     */
    public ?Placement $placement = null;

    public function __construct(
        public readonly ?string $sourceUid,
        public readonly ?string $title = null,
    ) {
    }

    public function block(string $reason): void
    {
        $this->blockedBy = $reason;
        $this->entry = null;
        $this->sites = [];
        $this->overwrite = null;
        $this->placement = null;
        $this->availableModes = [self::MODE_CREATE];
        $this->mode = self::MODE_CREATE;
    }

    /**
     * Names the Root element in a message: by its title, or else by its UID.
     */
    public function label(): string
    {
        return $this->title !== null ? "the Root element \"$this->title\"" : "the Root element $this->sourceUid";
    }

    public function isBlocked(): bool
    {
        return $this->blockedBy !== null;
    }

    /**
     * Adds a warning, once.
     */
    public function warn(string $warning): void
    {
        if (!in_array($warning, $this->warnings, true)) {
            $this->warnings[] = $warning;
        }
    }

    /**
     * Adds an Unresolved reference that will be skipped, once for each field.
     */
    public function addUnresolvedReference(string $uid, ?string $elementType, ?string $title, string $field): void
    {
        foreach ($this->unresolvedReferences as $reference) {
            if ($reference['uid'] === $uid && $reference['field'] === $field) {
                return;
            }
        }

        $this->unresolvedReferences[] = [
            'uid' => $uid,
            'elementType' => $elementType,
            'title' => $title,
            'field' => $field,
        ];
    }
}
