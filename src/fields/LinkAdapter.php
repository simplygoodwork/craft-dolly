<?php

namespace simplygoodwork\craftportage\fields;

use Craft;
use craft\base\FieldInterface;
use craft\fields\data\LinkData;
use craft\fields\Link;
use craft\fields\linktypes\BaseElementLinkType;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\ImportContext;
use simplygoodwork\craftportage\references\ElementTypes;

/**
 * Craft's Link field: URL, email, phone and other links that don't point at an element travel as they are, along with
 * the link's label and advanced attributes. A link to an element (an Embedded reference) is translated as a reference
 * tag. A link to an element that doesn't exist in the Target environment, or whose type isn't allowed there, is cleared
 * with a warning.
 */
final class LinkAdapter implements FieldAdapter
{
    private const ATTRIBUTES = ['urlSuffix', 'target', 'title', 'class', 'id', 'rel', 'ariaLabel', 'download'];

    public function __construct(private readonly RefTags $refTags = new RefTags())
    {
    }

    /**
     * @return array<string, mixed>|null the link's `serialize()` form, with the reference tag translated. A link to a
     * type of element Portage doesn't support is left out, since its ID means nothing in another environment.
     */
    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        if (!$field instanceof Link || !$value instanceof LinkData) {
            return null;
        }

        $link = $value->serialize();
        $type = $link['type'] ?? null;
        if ($this->linksToElement($field, $type)) {
            if (!$this->isSupportedElementType($type) || !is_string($link['value'] ?? null)) {
                return null;
            }
            $link['value'] = $this->refTags->export($link['value'], $context);
        }

        return $link;
    }

    /**
     * @return array<string, mixed>|null input for the field's `normalizeValue()`, or null for no link
     */
    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        if (!$field instanceof Link || !is_array($portable) || !is_string($portable['value'] ?? null) || !is_string($portable['type'] ?? null)) {
            return null;
        }

        // Checked before any Reference is resolved, so a discarded link creates no Stub and asks for no choice.
        $type = $portable['type'];
        if (!isset($field->getLinkTypes()[$type])) {
            $context->plan->warn(sprintf('The field "%s" doesn\'t allow %s links in this environment. The link will be cleared.', $field->name, $type));
            return null;
        }

        if ($this->linksToElement($field, $type)) {
            if (!$this->isSupportedElementType($type)) {
                $context->plan->warn(sprintf('The field "%s" links to a %s, which Portage can\'t move between environments. The link will be cleared.', $field->name, $type));
                return null;
            }
            $value = $this->refTags->importValue($portable['value'], $field, $context);
            if ($value === null) {
                return null;
            }
            $portable['value'] = $value;
        }

        $this->warnAboutDisabledAttributes($field, $portable, $context);

        return $portable;
    }

    /**
     * Warns about the label and attributes the Target field doesn't enable, which Craft drops when it normalizes the value.
     *
     * @param array<string, mixed> $portable
     */
    private function warnAboutDisabledAttributes(Link $field, array $portable, ImportContext $context): void
    {
        $skipped = [];
        if (!empty($portable['label']) && !$field->showLabelField) {
            $skipped[] = 'label';
        }
        foreach (self::ATTRIBUTES as $attribute) {
            if (!empty($portable[$attribute]) && !in_array($attribute, $field->advancedFields, true)) {
                $skipped[] = $attribute;
            }
        }
        if (!empty($portable['filename']) && !in_array('download', $field->advancedFields, true)) {
            $skipped[] = 'filename';
        }

        if ($skipped) {
            $context->plan->warn(sprintf(
                'The field "%s" doesn\'t allow %s in this environment. %s will be skipped.',
                $field->name,
                implode(', ', $skipped),
                count($skipped) === 1 ? 'It' : 'They',
            ));
        }
    }

    /**
     * Returns whether a link type the field enables points at an element, as opposed to a URL, email address or phone
     * number. It asks the field rather than `Link::types()`, which is private before Craft 5.10.
     */
    private function linksToElement(Link $field, mixed $typeId): bool
    {
        return is_string($typeId) && ($field->getLinkTypes()[$typeId] ?? null) instanceof BaseElementLinkType;
    }

    /**
     * Returns whether Portage can move links of an element link type: the type's ID is its element type's reference
     * handle, and that element type is registered.
     */
    private function isSupportedElementType(mixed $typeId): bool
    {
        $class = is_string($typeId) ? Craft::$app->getElements()->getElementTypeByRefHandle($typeId) : null;

        return $class !== null && ElementTypes::supports($class);
    }
}
