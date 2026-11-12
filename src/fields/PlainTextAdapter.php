<?php

namespace simplygoodwork\craftportage\fields;

use craft\base\FieldInterface;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\ImportContext;

/**
 * Plain text: the value is already portable.
 */
final class PlainTextAdapter implements FieldAdapter
{
    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        return $value === null ? null : (string)$value;
    }

    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        return is_scalar($portable) ? (string)$portable : null;
    }
}
