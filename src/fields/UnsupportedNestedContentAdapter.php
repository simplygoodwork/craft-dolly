<?php

namespace simplygoodwork\craftdolly\fields;

use craft\base\FieldInterface;
use simplygoodwork\craftdolly\export\ExportContext;
use simplygoodwork\craftdolly\import\ImportContext;

/**
 * The Field adapter for a field that holds Owned content (a Content Block, Addresses) and has no adapter of its own.
 * Craft's raw serialization of nested content would carry the source IDs of every element it relates to past
 * Resolution, so its values are skipped, with a warning.
 */
final class UnsupportedNestedContentAdapter implements RequiresOwner
{
    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        return null;
    }

    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        $context->plan->warn(sprintf('The field "%s" holds nested content Dolly can\'t move yet. Its values will be skipped.', $field->name));

        return null;
    }
}
