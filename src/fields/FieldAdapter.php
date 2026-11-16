<?php

namespace simplygoodwork\craftportage\fields;

use craft\base\FieldInterface;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\ImportContext;

/**
 * Translates one field type's values to portable form on Export and back to a Target environment value on Import.
 */
interface FieldAdapter
{
    /**
     * Returns the portable (JSON-safe) form of a field value. Related elements are added to the context as References.
     */
    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed;

    /**
     * Returns a value that can be passed to `setFieldValue()` in the Target environment, or `SkippedValue::Skipped` to
     * leave the field out of the values set (after warning why).
     */
    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed;
}
