<?php

namespace simplygoodwork\craftportage\import;

use craft\base\FieldInterface;
use craft\models\FieldLayout;
use simplygoodwork\craftportage\fields\FieldAdapterRegistry;
use simplygoodwork\craftportage\references\ReferenceResolver;

/**
 * State for importing one Root element. Field adapters receive it to rebuild Owned content and resolve References.
 */
final class ImportContext
{
    /**
     * @param array<string, mixed> $references the Payload's References, by UID
     */
    public function __construct(
        private readonly FieldAdapterRegistry $adapters,
        private readonly ReferenceResolver $resolver,
        private readonly array $references,
        private readonly UidClaims $uids,
        public readonly RootResult $result,
    ) {
    }

    /**
     * Returns the Target environment ID for a Reference, or null after reporting it as an Unresolved reference.
     */
    public function resolveReference(string $uid, FieldInterface $field): ?int
    {
        $reference = $this->references[$uid] ?? null;
        $resolution = is_array($reference) ? $this->resolver->resolve($reference) : null;

        if ($resolution?->isResolved()) {
            return $resolution->elementId;
        }

        $this->result->addUnresolvedReference(
            $uid,
            is_string($reference['elementType'] ?? null) ? $reference['elementType'] : null,
            is_string($reference['title'] ?? null) ? $reference['title'] : null,
            $field->name,
        );

        return null;
    }

    /**
     * Returns the source UID if it's free in the Target environment, or null so that Craft generates one (ADR-0003).
     */
    public function claimUid(mixed $sourceUid): ?string
    {
        return $this->uids->claim($sourceUid);
    }

    /**
     * Converts portable field values into `setFieldValues()` input for the given layout, keyed by handle.
     *
     * Values for fields that aren't in the layout, or whose type has no adapter, are left out.
     *
     * @param array<string, mixed> $portable field UID => portable value
     * @return array<string, mixed>
     */
    public function fieldValues(FieldLayout $layout, array $portable): array
    {
        $values = [];

        foreach ($layout->getCustomFields() as $field) {
            if (!array_key_exists($field->uid, $portable)) {
                continue;
            }
            $adapter = $this->adapters->adapterFor($field);
            if (!$adapter) {
                continue;
            }
            $values[$field->handle] = $adapter->import($field, $portable[$field->uid], $this);
        }

        return $values;
    }
}
