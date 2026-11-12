<?php

namespace simplygoodwork\craftportage\references;

use craft\helpers\StringHelper;

/**
 * Matches References to elements in the Target environment: by UID, then by Natural key.
 */
final class ReferenceResolver
{
    /**
     * @param array<string, mixed> $reference
     */
    public function resolve(array $reference): Resolution
    {
        $class = ElementTypes::classFor($reference['elementType'] ?? null);
        if (!$class) {
            return Resolution::unresolved();
        }

        $uid = $reference['uid'] ?? null;
        if (is_string($uid) && StringHelper::isUUID($uid)) {
            // Trashed elements are excluded, so a Reference to one is unresolved.
            $id = $class::find()
                ->uid($uid)
                ->status(null)
                ->site('*')
                ->unique()
                ->ids()[0] ?? null;

            if ($id) {
                return Resolution::matched((int)$id, Resolution::RULE_UID);
            }
        }

        $id = NaturalKey::find($class, $reference['naturalKey'] ?? null);

        return $id ? Resolution::matched($id, Resolution::RULE_NATURAL_KEY) : Resolution::unresolved();
    }
}
