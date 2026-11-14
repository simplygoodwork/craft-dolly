<?php

namespace simplygoodwork\craftportage\fields;

use craft\base\FieldInterface;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\ImportContext;
use Throwable;

/**
 * The Field adapter for a field type with no adapter of its own: Craft's serialize and normalize, with a warning in the
 * Preview, since the value may contain IDs that mean something else in the Target environment. A field that can't be
 * serialized is left out of the Payload rather than failing the Export.
 */
final class FallbackAdapter implements FieldAdapter
{
    public function __construct(private readonly SerializedValueAdapter $serialized = new SerializedValueAdapter())
    {
    }

    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        try {
            return $this->serialized->export($field, $value, $context);
        } catch (Throwable) {
            return null;
        }
    }

    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        // A field with no value has nothing to warn about.
        if ($portable !== null && $portable !== '' && $portable !== []) {
            $context->plan->warn(sprintf(
                'The field "%s" is a %s field, which Portage has no adapter for. Its value will be imported as it is, and any IDs in it may point at different elements in this environment.',
                $field->name,
                $field::displayName(),
            ));
        }

        return $this->serialized->import($field, $portable, $context);
    }
}
