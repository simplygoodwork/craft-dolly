<?php

namespace simplygoodwork\craftportage\fields;

use craft\base\FieldInterface;
use craft\fields\BaseOptionsField;
use craft\fields\Checkboxes;
use craft\fields\MultiSelect;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\ImportContext;

/**
 * Dropdown, radio buttons, checkboxes, multi-select and button group: the selected option values travel, and a value
 * the Target field no longer has as an option is dropped with a warning.
 */
final class OptionsFieldAdapter implements FieldAdapter
{
    public function __construct(private readonly SerializedValueAdapter $serialized = new SerializedValueAdapter())
    {
    }

    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        return $this->serialized->export($field, $value, $context);
    }

    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        if (!$field instanceof BaseOptionsField) {
            return null;
        }

        $multi = $field instanceof Checkboxes || $field instanceof MultiSelect;
        if ($portable === null || $portable === []) {
            return $multi ? [] : null;
        }

        $available = [];
        foreach ($field->options as $option) {
            if (!isset($option['optgroup'])) {
                $available[] = (string)$option['value'];
            }
        }

        $kept = [];
        $dropped = [];
        foreach (is_array($portable) ? $portable : [$portable] as $selected) {
            if (!is_scalar($selected)) {
                continue;
            }
            // An empty selection isn't an option that went away.
            if ((string)$selected === '' && !in_array('', $available, true)) {
                continue;
            }
            if (in_array((string)$selected, $available, true)) {
                $kept[] = (string)$selected;
            } else {
                $dropped[] = (string)$selected;
            }
        }

        if ($dropped) {
            $context->plan->warn(sprintf(
                'The field "%s" no longer has the %s %s in this environment. %s will be dropped.',
                $field->name,
                count($dropped) === 1 ? 'option' : 'options',
                implode(', ', array_map(fn(string $value) => "\"$value\"", $dropped)),
                count($dropped) === 1 ? 'That value' : 'Those values',
            ));
        }

        return $multi ? $kept : ($kept[0] ?? null);
    }
}
