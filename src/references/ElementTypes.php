<?php

namespace simplygoodwork\craftportage\references;

use craft\base\ElementInterface;
use simplygoodwork\craftportage\Plugin;

/**
 * The element types a Payload may name, from the `ElementTypeRegistry`. The Payload never decides which PHP class is
 * used: it names a type by its registered short name.
 */
final class ElementTypes
{
    /**
     * The element type of a Neo block that is a Root element. Only Root elements are blocks: a block is never a
     * Reference, so it isn't a registered element type.
     */
    public const NEO_BLOCK = 'neoBlock';

    /**
     * The registry of this request's Portage service.
     */
    public static function registry(): ElementTypeRegistry
    {
        return Plugin::getInstance()->getPortage()->getElementTypes();
    }

    /**
     * Returns the definition registered under a short name, which may come from a Payload, or null if there's none.
     */
    public static function definition(mixed $name): ?ElementTypeDefinition
    {
        return self::registry()->get($name);
    }

    /**
     * Returns the definition of an element's type, matched on its exact class, or null if it isn't registered.
     */
    public static function definitionOf(ElementInterface $element): ?ElementTypeDefinition
    {
        return self::definition(self::nameOf($element));
    }

    /**
     * @return class-string<ElementInterface>|null
     */
    public static function classFor(mixed $name): ?string
    {
        return self::definition($name)?->elementClass();
    }

    /**
     * What an element of the type is called in messages: its element class's lower display name in this environment
     * ("entry", "asset", "form"), never the short name the Payload carries. "element" when the type isn't registered.
     */
    public static function displayName(mixed $name): string
    {
        $class = self::classFor($name);

        return $class ? $class::lowerDisplayName() : 'element';
    }

    /**
     * Returns whether a Root element is a Matrix or Neo block (pasted into a field) rather than a whole entry: a Neo
     * block, or a nested entry, which names the field it was exported from.
     *
     * @param array<mixed> $root
     */
    public static function isBlockRoot(array $root): bool
    {
        return ($root['elementType'] ?? null) === self::NEO_BLOCK || is_string($root['field'] ?? null);
    }

    /**
     * Returns whether elements of the class can be Referenced.
     */
    public static function supports(string $class): bool
    {
        return self::registry()->nameOfClass($class) !== null;
    }

    public static function nameOf(ElementInterface $element): ?string
    {
        return self::registry()->nameOfClass($element::class);
    }

    /**
     * Returns the element source (`section:<uid>`, `group:<uid>`, `taggroup:<uid>` or `volume:<uid>` for the core
     * types) a Reference of the type is limited to, from its Natural key, or null when it isn't limited: users, or a
     * section, group or volume that doesn't exist in this environment.
     *
     * @param array<mixed> $naturalKey
     */
    public static function sourceFor(?string $type, array $naturalKey): ?string
    {
        return self::definition($type)?->sourceFor($naturalKey);
    }

    /**
     * Returns whether an element belongs to an element source from `sourceFor()`.
     */
    public static function isInSource(ElementInterface $element, string $source): bool
    {
        return self::definitionOf($element)?->isInSource($element, $source) ?? false;
    }
}
