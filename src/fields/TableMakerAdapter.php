<?php

namespace simplygoodwork\craftdolly\fields;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\helpers\Json;
use simplygoodwork\craftdolly\export\ExportContext;
use simplygoodwork\craftdolly\import\ImportContext;
use verbb\tablemaker\models\TableMakerData;

/**
 * TableMaker fields: the columns, rows and caption travel as TableMaker stores them.
 *
 * A rich text cell can link to an entry, category or asset by its ID, as a link fragment (`<a href="…#entry:12@1:url">`)
 * or a reference tag (`{entry:12:url}`). Both are Embedded references: their IDs become References on Export and
 * resolve on Import. A link that doesn't resolve is removed and its text kept, with a warning.
 */
final class TableMakerAdapter implements CompletesTokens
{
    public function __construct(
        private readonly SerializedValueAdapter $serialized = new SerializedValueAdapter(),
        private readonly RefTags $refTags = new RefTags(),
    ) {
    }

    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        return $this->mapRichTextCells(
            $this->serialized->export($field, $value, $context),
            fn(string $html) => $this->refTags->export($this->refTags->exportLinkFragments($html, $context), $context),
        );
    }

    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        return $this->mapRichTextCells(
            $this->serialized->import($field, $portable, $context),
            fn(string $html) => $this->refTags->import($this->refTags->importLinkFragments($html, $field, $context), $field, $context),
        );
    }

    /**
     * Completes the links and reference tags to Stubs and fetched assets in rich text cells. TableMaker stores each
     * cell as a JSON-encoded string, which keeps the tokens and the IDs that replace them as they are.
     */
    public function completeTokens(mixed $value, ElementInterface $element, callable $render, array $linkTokens): ?array
    {
        if (!$value instanceof TableMakerData) {
            return null;
        }

        $storage = $value->toStorage();

        return $render($storage) ? $storage : null;
    }

    /**
     * Applies a translation to the HTML of each rich text cell of a stored table value. TableMaker 5.1+ stores each
     * cell JSON-encoded (`cellEncoding: json-v1`), so a cell is decoded first and encoded again only if it changed.
     *
     * @param callable(string): string $translate
     */
    private function mapRichTextCells(mixed $table, callable $translate): mixed
    {
        if (!is_array($table) || !is_array($table['columns'] ?? null) || !is_array($table['rows'] ?? null)) {
            return $table;
        }

        $richText = [];
        foreach ($table['columns'] as $id => $column) {
            if (is_array($column) && ($column['type'] ?? null) === 'richtext') {
                $richText[] = $id;
            }
        }
        if (!$richText) {
            return $table;
        }

        $jsonEncoded = ($table['cellEncoding'] ?? null) === 'json-v1';
        foreach ($table['rows'] as $rowId => $row) {
            foreach ($richText as $id) {
                $cell = is_array($row) ? ($row[$id] ?? null) : null;
                $html = $jsonEncoded && is_string($cell) ? Json::decodeIfJson($cell) : $cell;
                if (!is_string($html)) {
                    continue;
                }

                $translated = $translate($html);
                if ($translated !== $html) {
                    $table['rows'][$rowId][$id] = $jsonEncoded ? Json::encode($translated, 0) : $translated;
                }
            }
        }

        return $table;
    }
}
