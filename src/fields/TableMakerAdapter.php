<?php

namespace simplygoodwork\craftportage\fields;

use craft\base\FieldInterface;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\ImportContext;

/**
 * TableMaker fields: the columns, rows and caption travel as TableMaker stores them.
 *
 * A rich text cell can link to an entry, category or asset by its ID (a `#entry:12@1:url` fragment in a link, or a
 * reference tag), which Portage doesn't translate. Those cells are imported as they are, with a warning that the links
 * may point at different elements in the Target environment.
 */
final class TableMakerAdapter implements FieldAdapter
{
    private const ELEMENT_LINK = '/(?:#|%23|\{)(?:entry|category|asset):\d/';

    public function __construct(private readonly SerializedValueAdapter $serialized = new SerializedValueAdapter())
    {
    }

    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        return $this->serialized->export($field, $value, $context);
    }

    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        if ($this->linksToElements($portable)) {
            $context->plan->warn(sprintf(
                'The field "%s" has rich text cells that link to entries, categories or assets, which Portage can\'t translate. They will be imported as they are, and the links may point at different elements in this environment.',
                $field->name,
            ));
        }

        return $this->serialized->import($field, $portable, $context);
    }

    private function linksToElements(mixed $portable): bool
    {
        if (!is_array($portable) || !is_array($portable['columns'] ?? null) || !is_array($portable['rows'] ?? null)) {
            return false;
        }

        $richText = [];
        foreach ($portable['columns'] as $id => $column) {
            if (is_array($column) && ($column['type'] ?? null) === 'richtext') {
                $richText[] = (string)$id;
            }
        }

        foreach ($portable['rows'] as $row) {
            foreach ($richText as $id) {
                $cell = is_array($row) ? ($row[$id] ?? null) : null;
                if (is_string($cell) && preg_match(self::ELEMENT_LINK, $cell)) {
                    return true;
                }
            }
        }

        return false;
    }
}
