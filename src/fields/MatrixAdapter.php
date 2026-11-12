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
     * Returns Matrix's serialized form (`['new1' => ['type' => …, 'fields' => …]]`), with source UIDs where they're free.
     */
    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        /** @var Matrix $field */
        $entryTypes = ArrayHelper::index($field->getEntryTypes(), 'uid');
        $value = [];

        foreach (is_array($portable) ? $portable : [] as $nested) {
            if (!is_array($nested)) {
                continue;
            }

            $entryType = $entryTypes[$nested['type'] ?? null] ?? null;
            if (!$entryType) {
                $context->result->warn("A block in \"$field->name\" was skipped: its entry type isn't allowed in this environment.");
                continue;
            }

            $data = [
                'type' => $entryType->handle,
                'enabled' => (bool)($nested['enabled'] ?? true),
                'fields' => $context->fieldValues($entryType->getFieldLayout(), is_array($nested['fields'] ?? null) ? $nested['fields'] : []),
            ];
            if ($entryType->hasTitleField && is_string($nested['title'] ?? null)) {
                $data['title'] = $nested['title'];
            }
            if ($entryType->showSlugField && is_string($nested['slug'] ?? null)) {
                $data['slug'] = $nested['slug'];
            }
            $uid = $context->claimUid($nested['uid'] ?? null);
            if ($uid) {
                $data['uid'] = $uid;
            }

            $value['new' . (count($value) + 1)] = $data;
        }

        return $value;
    }
}
