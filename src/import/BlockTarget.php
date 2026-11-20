<?php

namespace simplygoodwork\craftdolly\import;

use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fields\Matrix;

/**
 * Where pasted Matrix or Neo blocks go: a Matrix or Neo field of an entry's draft. The blocks are added to the draft
 * as normal edits, after the blocks it already has.
 */
final class BlockTarget
{
    private const NEO_FIELD = 'benf\neo\Field';

    /**
     * @param Entry $owner the draft that holds the field (a provisional or a named draft)
     * @param FieldInterface $field the Matrix or Neo field in the owner's field layout
     */
    public function __construct(
        public readonly Entry $owner,
        public readonly FieldInterface $field,
    ) {
    }

    /**
     * Returns whether blocks can be copied from and pasted into a field: a Matrix or Neo field.
     */
    public static function supports(?FieldInterface $field): bool
    {
        return $field instanceof Matrix || ($field !== null && $field::class === self::NEO_FIELD);
    }

    /**
     * Returns whether the field is a Neo field, which only takes blocks copied from itself.
     */
    public function isNeo(): bool
    {
        return $this->field::class === self::NEO_FIELD;
    }

    /**
     * Names the field and where it is, to say where blocks go.
     */
    public function label(): string
    {
        return "the field \"{$this->field->name}\"";
    }
}
