<?php

namespace simplygoodwork\craftdolly\fields;

use craft\base\FieldInterface;
use simplygoodwork\craftdolly\export\ExportContext;
use simplygoodwork\craftdolly\import\ImportContext;

/**
 * Preparse fields: the value isn't carried. Preparse renders it from the field's Twig template every time the element
 * is saved, so the Target environment recalculates it from its own content. The Preview says so, for a field that's in
 * the Target element's field layout.
 */
final class PreparseAdapter implements FieldAdapter
{
    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        return null;
    }

    public function import(FieldInterface $field, mixed $portable, ImportContext $context): SkippedValue
    {
        $context->plan->warn(sprintf(
            'The value of the field "%s" will be recalculated in this environment from the field\'s template.',
            $field->name,
        ));

        return SkippedValue::Skipped;
    }
}
