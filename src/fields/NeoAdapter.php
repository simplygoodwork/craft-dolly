<?php

namespace simplygoodwork\craftportage\fields;

use benf\neo\elements\Block;
use benf\neo\Field as NeoField;
use craft\base\FieldInterface;
use craft\helpers\ArrayHelper;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\ImportContext;

/**
 * Neo: its blocks are Owned content and travel with full content, in order, each with its level in the hierarchy.
 *
 * Block type level rules (top level, allowed child block types) aren't checked on Import. Neo doesn't enforce them on
 * save, so a drifted block type can produce a nesting that the Target environment's settings wouldn't allow.
 */
final class NeoAdapter implements FieldAdapter
{
    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        return NestedElements::export(Block::class, $value, $context);
    }

    /**
     * Returns Neo's serialized form keyed by UID (`['blocks' => ['uid:…' => […]], 'sortOrder' => […]]`), for the site
     * being planned. Keying by UID matches the blocks the first site's save created, so each site's content goes to
     * the same blocks.
     *
     * A block whose block type isn't in the field is skipped along with its child blocks (see the glossary): the blocks
     * that follow it and are nested deeper than it.
     */
    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        /** @var NeoField $field */
        $blockTypes = ArrayHelper::index($field->getBlockTypes(), 'uid');
        $blocks = [];
        $skippedLevel = null;

        foreach (is_array($portable) ? $portable : [] as $nested) {
            if (!is_array($nested)) {
                continue;
            }

            $level = max(1, (int)($nested['level'] ?? 1));
            if ($skippedLevel !== null) {
                if ($level > $skippedLevel) {
                    continue;
                }
                $skippedLevel = null;
            }

            $blockType = $context->allowedBlockType($field, $blockTypes, $nested['type'] ?? null);
            if (!$blockType) {
                $skippedLevel = $level;
                continue;
            }

            // Neo blocks have no title or slug, so only their field values are used.
            $content = $context->content($nested);
            $blocks[$context->nestedUid($nested['uid'] ?? null)] = [
                'type' => $blockType->handle,
                'enabled' => (bool)($nested['enabled'] ?? true),
                'level' => $level,
                'fields' => $context->fieldValues($blockType, $content['fields']),
            ];
        }

        return NestedElements::serialized('blocks', $blocks);
    }
}
