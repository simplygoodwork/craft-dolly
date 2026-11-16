<?php

namespace simplygoodwork\craftportage\fields;

use craft\base\ElementInterface;
use craft\elements\ElementCollection;

/**
 * A relation field's value on Import: the related elements, which may include Stubs and fetched assets that the
 * Importer saves before the owner.
 *
 * An asset whose fetch failed is never saved, and Craft can't save a relation to an element without an ID, so the
 * Importer drops it from the value before saving the owner, skipping the relation.
 *
 * It declares no `@extends` generics, since `ElementCollection` has two template types from Craft 5.2 and one before.
 */
final class RelatedElements extends ElementCollection
{
    /**
     * Removes the related elements that weren't saved.
     */
    public function dropUnsaved(): void
    {
        $this->items = array_values(array_filter($this->items, fn(ElementInterface $element) => $element->id !== null));
    }
}
