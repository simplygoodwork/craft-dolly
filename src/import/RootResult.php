<?php

namespace simplygoodwork\craftportage\import;

/**
 * The outcome of importing one Root element.
 */
final class RootResult
{
    public const STATUS_CREATED = 'created';
    public const STATUS_OVERWRITTEN = 'overwritten';
    public const STATUS_FAILED = 'failed';

    public string $status = self::STATUS_CREATED;

    /**
     * @var int|null the new entry, or for an overwrite the canonical entry
     */
    public ?int $elementId = null;

    /**
     * @var int|null the draft an overwrite created on the canonical entry
     */
    public ?int $draftId = null;

    /**
     * @var string the Import mode the Root element was imported in
     */
    public string $mode = RootPlan::MODE_CREATE;

    /**
     * @var string|null the UID of the Target section
     */
    public ?string $sectionUid = null;

    /**
     * @var list<string>
     */
    public array $errors = [];

    /**
     * @var list<string> things that were skipped or changed without failing the Root element
     */
    public array $warnings = [];

    /**
     * @var list<array{uid: string, elementType: string|null, title: string|null, field: string}>
     */
    public array $unresolvedReferences = [];

    /**
     * @var list<string> names of required fields left empty
     */
    public array $incompleteFields = [];

    /**
     * @var list<array{uid: string, elementType: string|null, title: string|null, elementId: int}> Stubs created for this
     * Root element's Unresolved references
     */
    public array $stubs = [];

    public function __construct(
        public readonly ?string $sourceUid,
        public readonly ?string $title = null,
    ) {
    }

    /**
     * Starts a result with everything the plan says will be skipped or imported imperfectly.
     */
    public static function fromPlan(RootPlan $plan): self
    {
        $result = new self($plan->sourceUid, $plan->title);
        $result->mode = $plan->mode;
        $result->sectionUid = $plan->section?->uid;
        $result->warnings = $plan->warnings;
        $result->unresolvedReferences = $plan->unresolvedReferences;
        $result->incompleteFields = $plan->incompleteFields;

        return $result;
    }

    public function fail(string $error): void
    {
        $this->status = self::STATUS_FAILED;
        $this->elementId = null;
        $this->draftId = null;
        $this->stubs = [];
        $this->errors[] = $error;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sourceUid' => $this->sourceUid,
            'title' => $this->title,
            'status' => $this->status,
            'elementId' => $this->elementId,
            'draftId' => $this->draftId,
            'mode' => $this->mode,
            'section' => $this->sectionUid,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'unresolvedReferences' => $this->unresolvedReferences,
            'incompleteFields' => $this->incompleteFields,
            'stubs' => $this->stubs,
        ];
    }
}
