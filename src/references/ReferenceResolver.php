<?php

namespace simplygoodwork\craftportage\references;

use craft\helpers\StringHelper;

/**
 * Matches References to elements in the Target environment. Only by UID for now.
 */
final class ReferenceResolver
{
    /**
     * @param array<string, mixed> $reference
     */
    public function resolve(array $reference): Resolution
    {
        $class = ElementTypes::classFor($reference['elementType'] ?? null);
        $uid = $reference['uid'] ?? null;

        if (!$class || !is_string($uid) || !StringHelper::isUUID($uid)) {
            return Resolution::unresolved();
        }

        // Trashed elements are excluded, so a Reference to one is unresolved.
        $id = $class::find()
            ->uid($uid)
            ->status(null)
            ->site('*')
            ->unique()
            ->ids()[0] ?? null;

        return $id ? Resolution::matched((int)$id, Resolution::RULE_UID) : Resolution::unresolved();
    }
}
