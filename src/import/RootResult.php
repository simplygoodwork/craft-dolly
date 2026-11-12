<?php

namespace simplygoodwork\craftportage\import;

/**
 * The outcome of importing one Root element.
 */
final class RootResult
{
    public const STATUS_CREATED = 'created';
    public const STATUS_FAILED = 'failed';

    public string $status = self::STATUS_CREATED;

    public ?int $elementId = null;

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

    public function __construct(
        public readonly ?string $sourceUid,
    ) {
    }

    public function fail(string $error): void
    {
        $this->status = self::STATUS_FAILED;
        $this->elementId = null;
        $this->errors[] = $error;
    }

    public function addUnresolvedReference(string $uid, ?string $elementType, ?string $title, string $field): void
    {
        $this->unresolvedReferences[] = [
            'uid' => $uid,
            'elementType' => $elementType,
            'title' => $title,
            'field' => $field,
        ];
    }

    public function warn(string $warning): void
    {
        $this->warnings[] = $warning;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sourceUid' => $this->sourceUid,
            'status' => $this->status,
            'elementId' => $this->elementId,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'unresolvedReferences' => $this->unresolvedReferences,
        ];
    }
}
