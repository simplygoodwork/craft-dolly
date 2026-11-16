<?php

namespace simplygoodwork\craftportage\import;

/**
 * Values that only one element may take in the Target environment, such as a Retour short link, claimed across all the
 * Root elements of one Import. The first Root element to claim a value gets it.
 */
final class ValueClaims
{
    /**
     * @var array<string, array{0: int|RootPlan, 1: RootPlan}> value => the element taking it (the ID of an existing
     * entry, or the plan of a Root element creating one), and the plan of the Root element that claimed it first
     */
    private array $claims = [];

    /**
     * Claims a value for an element, and returns null if it's free or already that element's, or else the plan of the
     * Root element that claimed it for another one.
     *
     * @param int|RootPlan $element the ID of the existing entry the value goes to, or the plan of the Root element that
     * creates the element
     */
    public function claim(string $value, int|RootPlan $element, RootPlan $plan): ?RootPlan
    {
        $claim = $this->claims[$value] ??= [$element, $plan];

        return $claim[0] === $element ? null : $claim[1];
    }
}
