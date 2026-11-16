<?php

namespace simplygoodwork\craftportage\references;

use craft\base\ElementInterface;

/**
 * How Portage handles References to one element type: its element class, its Natural key, the element source a
 * Reference of the type is limited to, and whether and how it's Stubbed.
 *
 * A definition is registered in the `ElementTypeRegistry` under a short name, which is what the Payload carries. The
 * Payload never names the class: the Target environment's registry maps the short name back to it.
 *
 * Only the element class and the Natural key are required. By default a Reference of the type isn't limited to an
 * element source, and is never Stubbed.
 */
abstract class ElementTypeDefinition
{
    /**
     * @return class-string<ElementInterface>
     */
    abstract public function elementClass(): string;

    /**
     * Returns the Natural key of an element of the type, which the Reference carries: JSON-safe identifying attributes
     * that mean the same in another environment, such as a section UID and a slug. Returns null if it has none.
     *
     * @return array<string, mixed>|null
     */
    abstract public function naturalKey(ElementInterface $element): ?array;

    /**
     * Returns the ID of the one element matching a Natural key from `naturalKey()`, or null if none or several do, so
     * a Reference is never related to the wrong element. The key comes from the Payload: check every value's type.
     *
     * @param array<mixed> $naturalKey
     */
    abstract public function find(array $naturalKey): ?int;

    /**
     * Returns the element source a Reference is limited to, from its Natural key: the key of one of the element type's
     * sources, such as `section:<uid>`. A replacement for an Unresolved reference must be in it, and the Preview's
     * element selector only shows it. Returns null when the Reference isn't limited, or its source doesn't exist here.
     *
     * @param array<mixed> $naturalKey
     */
    public function sourceFor(array $naturalKey): ?string
    {
        return null;
    }

    /**
     * Returns whether an element belongs to an element source from `sourceFor()`.
     */
    public function isInSource(ElementInterface $element, string $source): bool
    {
        return false;
    }

    /**
     * What an element source of the type is called in messages: "section", "category group", "volume".
     */
    public function sourceName(): string
    {
        return 'source';
    }

    /**
     * Returns whether an Unresolved reference of the type may be Stubbed.
     */
    public function canBeStubbed(): bool
    {
        return false;
    }

    /**
     * Returns the unsaved Stub for an Unresolved reference, from its Natural key and title, in the element source the
     * key names. Returns null if it can't be created here, such as when that source doesn't exist. `Stubs` disables it
     * and gives it its UID; the Importer saves it.
     *
     * Only called when `canBeStubbed()` is true.
     *
     * @param array<mixed> $naturalKey
     */
    public function stub(array $naturalKey, ?string $title): ?ElementInterface
    {
        return null;
    }

    /**
     * Says why the user running the Import may not save a Stub from `stub()`, completing "can't be Stubbed: …".
     */
    public function whyStubForbidden(ElementInterface $stub): string
    {
        return sprintf('you don\'t have permission to create %s', $stub::pluralLowerDisplayName());
    }
}
