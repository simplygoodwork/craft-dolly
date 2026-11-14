<?php

namespace simplygoodwork\craftportage\fields;

use craft\base\FieldInterface;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\ImportContext;

/**
 * Plain text: portable apart from its Embedded references, which are translated as reference tags.
 */
final class PlainTextAdapter implements FieldAdapter
{
    public function __construct(private readonly RefTags $refTags = new RefTags())
    {
    }

    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        return $value === null ? null : $this->refTags->export((string)$value, $context);
    }

    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        return is_scalar($portable) ? $this->refTags->import((string)$portable, $field, $context) : null;
    }
}
