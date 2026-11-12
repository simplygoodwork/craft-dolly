<?php

namespace simplygoodwork\craftportage\fields;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\db\ElementQueryInterface;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\ImportContext;

/**
 * Relation fields (Entries, Categories, Tags, Users): the related elements travel as References (ADR-0002), in order.
 */
final class RelationFieldAdapter implements FieldAdapter
{
    /**
     * @param class-string<ElementInterface> $elementType the element type the field relates to
     */
    public function __construct(private readonly string $elementType)
    {
    }

    /**
     * @return list<string> Reference UIDs
     */
    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        if ($value instanceof ElementQueryInterface) {
            $value = (clone $value)->status(null)->all();
        }

        $uids = [];
        foreach (is_iterable($value) ? $value : [] as $element) {
            if ($element instanceof $this->elementType) {
                $uids[] = $context->addReference($element);
            }
        }

        return $uids;
    }

    /**
     * Returns the related elements, which may include Stubs and fetched assets that the Importer saves before the
     * owner, so their IDs are only read when the relation is saved. Skipped Unresolved references are reported.
     */
    public function import(FieldInterface $field, mixed $portable, ImportContext $context): RelatedElements
    {
        $elements = [];
        foreach (is_array($portable) ? $portable : [] as $uid) {
            $element = is_string($uid) ? $context->resolveReference($uid, $field) : null;
            if ($element !== null) {
                $elements[] = $element;
            }
        }

        $related = new RelatedElements($elements);
        $context->plan->relations[] = $related;

        return $related;
    }
}
