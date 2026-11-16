<?php

namespace simplygoodwork\craftportage\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use presseddigital\linkit\base\ElementLink;
use presseddigital\linkit\base\Link;
use presseddigital\linkit\fields\LinkitField;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\ImportContext;
use simplygoodwork\craftportage\references\ElementTypes;

/**
 * Pressed Digital's Linkit field: one link, of one of the link types enabled on the field.
 *
 * A link names its link type by Linkit's handle for it (`entry`, `url`, `email`…), never by class: on Import the handle
 * is matched to the link types the Target field enables. URL, email, phone and social links travel as they are, along
 * with the link's custom text and target. A link to an element (an Embedded reference, stored as the element's ID)
 * holds the element as a Reference and resolves with the usual rules, Stub and replacement choices. A link to a type of
 * element Portage doesn't support (a Commerce product) is cleared with a warning.
 */
final class LinkitAdapter implements CompletesTokens
{
    /**
     * @return array{type: string, value?: string, customText?: string|null, target?: bool|null, unsupported?: true}|null
     * the link, or null for no link or a link to an element that no longer exists. A link to a type of element Portage
     * doesn't support is exported without its element, as unsupported, since its ID means nothing in another
     * environment.
     */
    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        if (!$value instanceof Link || $value->value === null || $value->value === '') {
            return null;
        }

        $type = $value->getTypeHandle();
        if ($value instanceof ElementLink) {
            $elementClass = $value::elementType();
            if ($elementClass === null || !ElementTypes::supports($elementClass)) {
                return ['type' => $type, 'unsupported' => true];
            }

            $element = ctype_digit($value->value) ? Craft::$app->getElements()->getElementById((int)$value->value, $elementClass, '*') : null;
            if (!$element) {
                return null;
            }
            $linkValue = $context->addReference($element);
        } else {
            $linkValue = $value->value;
        }

        return [
            'type' => $type,
            'value' => $linkValue,
            'customText' => $value->customText,
            'target' => $value->target,
        ];
    }

    /**
     * @return array{type: class-string<Link>, value: string, customText: string|null, target: bool|null}|null input
     * for the field's `normalizeValue()`, or null for no link
     */
    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        if (!$field instanceof LinkitField || !is_array($portable) || !is_string($portable['type'] ?? null)) {
            return null;
        }

        // Checked before any Reference is resolved, so a discarded link creates no Stub and asks for no choice.
        $handle = $portable['type'];
        $class = $this->enabledLinkType($field, $handle);
        if ($class === null) {
            $context->plan->warn(sprintf('The field "%s" doesn\'t allow %s links in this environment. The link will be cleared.', $field->name, $handle));
            return null;
        }

        $isElementLink = is_subclass_of($class, ElementLink::class);
        $elementClass = $isElementLink ? $class::elementType() : null;
        if ($isElementLink && ($elementClass === null || !ElementTypes::supports($elementClass) || !empty($portable['unsupported']))) {
            $context->plan->warn(sprintf('The field "%s" links to a %s, which Portage can\'t move between environments. The link will be cleared.', $field->name, $handle));
            return null;
        }
        if (!is_string($portable['value'] ?? null) || $portable['value'] === '') {
            return null;
        }

        $value = $portable['value'];
        if ($elementClass !== null) {
            $id = $context->embeddedId(
                $value,
                $elementClass,
                $field,
                sprintf('A %s link in the field "%s" doesn\'t point at an element the Payload carries. The link will be cleared.', $handle, $field->name),
                'linked',
                'The link will be cleared.',
            );
            if ($id === null) {
                return null;
            }
            $value = (string)$id;
        }

        return [
            'type' => $class,
            'value' => $value,
            'customText' => is_string($portable['customText'] ?? null) ? $portable['customText'] : null,
            'target' => is_bool($portable['target'] ?? null) ? $portable['target'] : null,
        ];
    }

    /**
     * Replaces the token of a Stub or fetched asset in a link to an element. A link to an asset whose fetch failed has
     * no element left to link to, so its value is emptied and Linkit clears it; the fetch failure is already reported.
     *
     * @return array{type: string, value: string|null, customText: string|null, target: bool|null}|null
     */
    public function completeTokens(mixed $value, ElementInterface $element, callable $render): ?array
    {
        if (!$value instanceof Link) {
            return null;
        }

        $link = [
            'type' => $value->getType(),
            'value' => $value->value,
            'customText' => $value->customText,
            'target' => $value->target,
        ];

        return $render($link) ? $link : null;
    }

    /**
     * Returns the class of the link type the field enables with Linkit's handle for it, or null if it enables none.
     * The handle is matched against the classes in the Target field's settings; the Payload never names the class.
     *
     * @return class-string<Link>|null
     */
    private function enabledLinkType(LinkitField $field, string $handle): ?string
    {
        foreach ($field->types as $class => $settings) {
            if (!is_string($class) || empty($settings['enabled']) || !is_subclass_of($class, Link::class)) {
                continue;
            }
            $parts = explode('\\', $class);
            if (strtolower((string)array_pop($parts)) === $handle) {
                return $class;
            }
        }

        return null;
    }
}
