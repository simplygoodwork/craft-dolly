<?php

namespace simplygoodwork\craftdolly\import;

use Craft;
use craft\base\ElementInterface;
use simplygoodwork\craftdolly\references\ElementTypes;

/**
 * Builds unsaved Stubs for Unresolved references: disabled, minimal elements in the Reference's original section or
 * group, populated only from the Reference's identifying information, as its element type's definition builds them.
 * The Importer saves them.
 *
 * Users and assets are never Stubbed, nor is any other registered element type that doesn't allow it, and neither
 * are the Payload's own Root elements: a Stub would duplicate the Root element.
 */
final class Stubs
{
    /**
     * @param array<string, true> $rootUids source UIDs of the Payload's Root elements, which are never Stubbed
     */
    public function __construct(
        private readonly UidClaims $uids,
        private readonly array $rootUids = [],
    ) {
    }

    /**
     * Returns whether a Reference can be Stubbed in this environment: its element type allows Stubs, its section
     * or group exists here, it isn't one of the Payload's Root elements, and the user running the Import may create
     * elements there.
     */
    public function canStub(ReferencePlan $reference): bool
    {
        $stub = $this->candidate($reference);

        return $stub !== null && $this->isPermitted($stub);
    }

    /**
     * Returns why the user running the Import may not create the Reference's Stub, or null if they may (or it can't be
     * Stubbed for another reason).
     */
    public function whyForbidden(ReferencePlan $reference): ?string
    {
        $stub = $this->candidate($reference);
        if ($stub === null || $this->isPermitted($stub)) {
            return null;
        }

        return ElementTypes::definition($reference->elementType)?->whyStubForbidden($stub);
    }

    /**
     * Returns whether the Reference is one of the Payload's own Root elements.
     */
    public function isRoot(ReferencePlan $reference): bool
    {
        return isset($this->rootUids[$reference->uid]);
    }

    /**
     * Returns the unsaved Stub for a Reference, keeping the source UID when it's free (ADR-0003), or null if the
     * Reference can't be Stubbed.
     */
    public function build(ReferencePlan $reference): ?ElementInterface
    {
        $stub = $this->candidate($reference);
        if (!$stub || !$this->isPermitted($stub)) {
            return null;
        }

        $stub->uid = $this->uids->claim($reference->uid);

        return $stub;
    }

    /**
     * Returns the Reference's unsaved Stub without a UID, or null if it's a Root element or its section or group
     * doesn't exist here.
     */
    private function candidate(ReferencePlan $reference): ?ElementInterface
    {
        return $this->isRoot($reference) ? null : $this->unsaved($reference);
    }

    /**
     * Returns whether the user running the Import may save the Stub. With nobody logged in, Craft's permissions
     * aren't checked.
     */
    private function isPermitted(ElementInterface $stub): bool
    {
        $user = Craft::$app->getUser()->getIdentity();

        return !$user || Craft::$app->getElements()->canSave($stub, $user);
    }

    /**
     * Returns the Reference's Stub with its content but no UID, or null if its element type is never Stubbed or its
     * section or group doesn't exist here.
     */
    private function unsaved(ReferencePlan $reference): ?ElementInterface
    {
        $definition = ElementTypes::definition($reference->elementType);
        $key = $reference->reference['naturalKey'] ?? null;
        if (!$definition?->canBeStubbed() || !is_array($key)) {
            return null;
        }

        $stub = $definition->stub($key, $reference->title);
        if ($stub) {
            $stub->enabled = false;
        }

        return $stub;
    }
}
