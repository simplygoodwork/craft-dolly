<?php

namespace simplygoodwork\craftportage\fields;

use craft\base\FieldInterface;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\ImportContext;

/**
 * SimpleMap (Maps) fields: the location (latitude, longitude, zoom, address, its parts and what3words) travels as it is.
 *
 * The field's serialized value also carries the IDs of SimpleMap's own record, its owner, the owner's site and the
 * field, and the distance from a location search. They mean nothing in the Target environment, so they're left out.
 * SimpleMap writes its own record for the element whenever the element is saved, so the Import's element save makes
 * it again from the location.
 */
final class SimpleMapAdapter implements FieldAdapter
{
    private const SOURCE_ONLY_KEYS = ['id', 'ownerId', 'ownerSiteId', 'fieldId', 'distance'];

    public function __construct(private readonly SerializedValueAdapter $serialized = new SerializedValueAdapter())
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function export(FieldInterface $field, mixed $value, ExportContext $context): ?array
    {
        $serialized = $this->serialized->export($field, $value, $context);
        if (!is_array($serialized)) {
            return null;
        }

        return array_diff_key($serialized, array_flip(self::SOURCE_ONLY_KEYS));
    }

    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        return is_array($portable) ? array_diff_key($portable, array_flip(self::SOURCE_ONLY_KEYS)) : null;
    }
}
