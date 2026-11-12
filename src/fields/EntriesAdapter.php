<?php

namespace simplygoodwork\craftportage\fields;

use craft\base\FieldInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\ImportContext;

/**
 * Entries relation field: the related entries travel as References (ADR-0002), in order.
 */
final class EntriesAdapter implements FieldAdapter
{
    /**
     * @return list<string> Reference UIDs
     */
    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        if ($value instanceof ElementQueryInterface) {
            $value = (clone $value)->status(null)->all();
        }

        $uids = [];
        foreach (is_iterable($value) ? $value : [] as $entry) {
            if ($entry instanceof Entry) {
                $uids[] = $context->addReference($entry);
            }
        }

        return $uids;
    }

    /**
     * @return list<int> IDs of the resolved entries; Unresolved references are skipped and reported
     */
    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        $ids = [];
        foreach (is_array($portable) ? $portable : [] as $uid) {
            $id = is_string($uid) ? $context->resolveReference($uid, $field) : null;
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
