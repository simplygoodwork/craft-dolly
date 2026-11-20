<?php

namespace simplygoodwork\craftdolly\fields;

use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use simplygoodwork\craftdolly\export\ExportContext;

/**
 * What the adapters of fields that hold Owned content (Matrix, Neo) share.
 */
final class NestedElements
{
    /**
     * Exports the nested elements of a field value that are of the given class, in order, with their content.
     *
     * @param class-string<ElementInterface> $class
     * @return list<array<string, mixed>>
     */
    public static function export(string $class, mixed $value, ExportContext $context): array
    {
        if ($value instanceof ElementQueryInterface) {
            $value = (clone $value)->status(null)->all();
        }

        $nested = [];
        foreach (is_iterable($value) ? $value : [] as $element) {
            if ($element instanceof $class) {
                $nested[] = $context->exportNested($element);
            }
        }

        return $nested;
    }

    /**
     * Returns the serialized form of nested elements keyed by UID, which Matrix and Neo accept for `setFieldValue()`:
     * `[$key => ['uid:…' => […]], 'sortOrder' => ['…']]`. Keying by UID matches the elements the first site's save
     * created, so each site's content goes to the same elements.
     *
     * @param array<string, array<string, mixed>> $byUid the data of each nested element, by its UID in the Target
     * environment, in order
     * @return array<string, mixed>
     */
    public static function serialized(string $key, array $byUid): array
    {
        if (!$byUid) {
            return [];
        }

        return [
            $key => array_combine(array_map(fn(string $uid) => "uid:$uid", array_keys($byUid)), $byUid),
            'sortOrder' => array_keys($byUid),
        ];
    }
}
