<?php

namespace simplygoodwork\craftportage\fields;

use craft\base\ElementInterface;

/**
 * Marks a Field adapter whose value holds the tokens of Stubs and fetched assets (their IDs are only known once the
 * Importer has saved them) in a form the Importer can't walk itself. The adapter says how to replace them.
 */
interface CompletesTokens extends FieldAdapter
{
    /**
     * Replaces the tokens in a field value as its element is saved.
     *
     * @param callable(array<mixed>&): bool $render replaces the tokens in an array in place, returning whether any changed
     * @param array<string, string> $linkTokens the tokens links point at, by the link's UID, as the adapter recorded
     * them in `RootPlan::$linkTokens` while planning
     * @return mixed the input for `setFieldValue()` with the tokens replaced, or null to leave the value as it is (it
     * holds no tokens, or isn't this field's value)
     */
    public function completeTokens(mixed $value, ElementInterface $element, callable $render, array $linkTokens): mixed;
}
