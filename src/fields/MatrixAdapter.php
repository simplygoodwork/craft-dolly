<?php

namespace simplygoodwork\craftportage\fields;

use craft\base\FieldInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fields\Matrix;
use craft\helpers\ArrayHelper;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\ImportContext;

/**
 * Matrix: its nested entries are Owned content and travel with full content, recursively.
 */
final class MatrixAdapter implements FieldAdapter
{
    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        if ($value instanceof ElementQueryInterface) {
            $value = (clone $value)->status(null)->all();
        }

        $nested = [];
        foreach (is_iterable($value) ? $value : [] as $entry) {
            if ($entry instanceof Entry) {
                $nested[] = $context->exportNested($entry);
            }
        }

        return $nested;
    }

    /**
     * Returns Matrix's serialized form keyed by UID (`['entries' => ['uid:…' => […]], 'sortOrder' => […]]`), for the
     * site being planned. Keying by UID matches the nested entries the first site's save created, so each site's
     * content goes to the same nested entries.
     */
    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        /** @var Matrix $field */
        $entryTypes = ArrayHelper::index($field->getEntryTypes(), 'uid');
        $entries = [];

        foreach (is_array($portable) ? $portable : [] as $nested) {
            if (!is_array($nested)) {
                continue;
            }

            $entryType = $context->allowedEntryType($field, $entryTypes, $nested['type'] ?? null, 'Blocks', 'their');
            if (!$entryType) {
                continue;
            }

            $data = ['type' => $entryType->handle, ...$context->nestedEntryData($entryType, $nested)];

            $entries[$context->nestedUid($nested['uid'] ?? null)] = $data;
        }

        if (!$entries) {
            return [];
        }

        return [
            'entries' => array_combine(array_map(fn(string $uid) => "uid:$uid", array_keys($entries)), $entries),
            'sortOrder' => array_keys($entries),
        ];
    }
}
