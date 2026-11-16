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
        $definition = ElementTypes::definition($reference['elementType'] ?? null);
        if (!$definition) {
            return Resolution::unresolved();
        }

        $uid = $reference['uid'] ?? null;
        if (is_string($uid) && StringHelper::isUUID($uid)) {
            // Trashed elements are excluded, so a Reference to one is unresolved.
            $id = $definition->query()
                ->uid($uid)
                ->status(null)
                ->site('*')
                ->unique()
                ->ids()[0] ?? null;

            if ($id) {
                return Resolution::matched((int)$id, Resolution::RULE_UID);
            }
        }

        $naturalKey = $reference['naturalKey'] ?? null;
        $id = is_array($naturalKey) ? $definition->find($naturalKey) : null;

        return $id ? Resolution::matched($id, Resolution::RULE_NATURAL_KEY) : Resolution::unresolved();
    }
}
