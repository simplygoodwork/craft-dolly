<?php

namespace simplygoodwork\craftportage\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\helpers\ArrayHelper;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\ImportContext;
use simplygoodwork\craftportage\references\ElementTypes;
use verbb\hyper\base\ElementLink;
use verbb\hyper\base\Link;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links\Site as SiteLink;
use verbb\hyper\models\LinkCollection;

/**
 * Verbb Hyper: any number of links in a field, each of a link type configured on the field.
 *
 * A link names its link type by the UID of its field layout, a Schema reference with its own Fingerprint and Drift,
 * never by class. A link to an element (an Embedded reference) holds the element as a Reference and resolves with the
 * usual rules, Stub and replacement choices. The values of the custom fields in a link type's field layout travel
 * with the link, through their own Field adapters.
 */
final class HyperAdapter implements CompletesTokens
{
    /**
     * The plain settings of a link, copied as they are.
     */
    private const ATTRIBUTES = ['newWindow', 'linkText', 'ariaLabel', 'urlSuffix', 'linkTitle', 'classes', 'customAttributes'];

    /**
     * @return list<array<string, mixed>>|null the links with the tokens of Stubs and fetched assets replaced. A link to
     * an asset whose fetch failed has no element left to link to, so it's removed; the fetch failure is already
     * reported.
     */
    public function completeTokens(mixed $value, ElementInterface $element, callable $render): ?array
    {
        if (!$value instanceof LinkCollection) {
            return null;
        }

        $serialized = $value->serializeValues($element);

        return $render($serialized) ? array_values(array_filter($serialized, fn(array $link) => ($link['linkValue'] ?? null) !== [''])) : null;
    }

    /**
     * @return list<array<string, mixed>> the links, in order. A link to an element that no longer exists is left out. A
     * link to a type of element Portage doesn't support is exported without its element, as unsupported, since its ID
     * means nothing in another environment.
     */
    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        $links = [];
        foreach ($value instanceof LinkCollection ? $value->getLinks() : [] as $link) {
            if ($link instanceof Link && $link->layoutUid && ($exported = $this->exportLink($link, $context)) !== null) {
                $links[] = $exported;
            }
        }

        return $links;
    }

    /**
     * Returns the links as a `LinkCollection`. A link whose link type isn't in the Target field (or is disabled there),
     * or whose element is an Unresolved reference, is skipped with a warning; the other links remain.
     */
    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        if (!$field instanceof HyperField) {
            return null;
        }

        /** @var array<string, Link> $linkTypes */
        $linkTypes = ArrayHelper::index(array_filter($field->getLinkTypes(), fn($linkType) => $linkType instanceof Link), 'layoutUid');

        $links = [];
        foreach (is_array($portable) ? $portable : [] as $link) {
            $linkType = is_array($link) ? $context->allowedLinkType($field, $linkTypes, $link['linkType'] ?? null) : null;
            if (!is_array($link) || !$linkType) {
                continue;
            }

            if (!$linkType->enabled) {
                $context->plan->warn(sprintf('The link type "%s" is disabled in the field "%s" in this environment. Its links will be skipped.', $linkType->label ?? $linkType->handle, $field->name));
                continue;
            }
            if (!empty($link['unsupported'])) {
                $context->plan->warn(sprintf('A %s link in the field "%s" points at a kind of element Portage can\'t move between environments. The link will be skipped.', $linkType->label ?? $linkType->handle, $field->name));
                continue;
            }

            $imported = $this->importLink($field, $link, $linkType, $context);
            if ($imported !== null) {
                $links[] = $imported;
            }
        }

        return new LinkCollection($field, $links);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exportLink(Link $link, ExportContext $context): ?array
    {
        $serialized = $link->getSerializedValues();
        $exported = ['linkType' => $context->recordLinkType((string)$link->layoutUid)];
        $exported += array_intersect_key($serialized, array_flip(self::ATTRIBUTES));

        if ($link instanceof ElementLink) {
            if (!ElementTypes::supports($link::elementType())) {
                return ['linkType' => $exported['linkType'], 'unsupported' => true];
            }

            $id = is_array($link->linkValue) ? ($link->linkValue[0] ?? null) : $link->linkValue;
            $element = is_numeric($id) ? Craft::$app->getElements()->getElementById((int)$id, $link::elementType(), '*') : null;
            if (!$element) {
                return null;
            }

            $exported['linkValue'] = $context->addReference($element);
            if ($link->linkSiteId !== null) {
                $exported['linkSiteId'] = $context->siteUid($link->linkSiteId);
            }
        } elseif (array_key_exists('linkValue', $serialized)) {
            $exported['linkValue'] = $serialized['linkValue'];
            if ($link instanceof SiteLink && is_string($serialized['linkValue'])) {
                // A Site link holds a site's UID, which is a Schema reference.
                $site = ArrayHelper::firstWhere(Craft::$app->getSites()->getAllSites(true), 'uid', $serialized['linkValue']);
                if ($site) {
                    $context->siteUid($site->id);
                }
            }
        }

        $fields = $context->exportFields($link);
        if ($fields) {
            $exported['fields'] = $fields;
        }

        return $exported;
    }

    /**
     * @param array<mixed> $portable
     */
    private function importLink(HyperField $field, array $portable, Link $linkType, ImportContext $context): ?Link
    {
        $values = ['handle' => $linkType->handle] + array_intersect_key($portable, array_flip(self::ATTRIBUTES));
        $subject = sprintf('of a link in the field "%s"', $field->name);

        $token = null;
        if ($linkType instanceof ElementLink) {
            $id = $this->importElement($field, $portable, $linkType, $context);
            if ($id === null) {
                return null;
            }
            $token = is_string($id) ? $id : null;
            $values['linkValue'] = [$token === null ? $id : null];

            if (is_string($portable['linkSiteId'] ?? null)) {
                $site = $context->site($portable['linkSiteId'], $subject, 'The link will use the site it is saved in.');
                if ($site) {
                    $values['linkSiteId'] = $site->id;
                }
            }
        } elseif ($linkType instanceof SiteLink) {
            $site = is_string($portable['linkValue'] ?? null) ? $context->site($portable['linkValue'], $subject, 'The link will be removed.') : null;
            if (!$site) {
                return null;
            }
            $values['linkValue'] = $site->uid;
        } elseif (isset($portable['linkValue']) && (is_scalar($portable['linkValue']) || is_array($portable['linkValue']))) {
            // Most link types hold a string. Embed holds the page's data as an array.
            $values['linkValue'] = $portable['linkValue'];
        }

        $fields = is_array($portable['fields'] ?? null) ? $portable['fields'] : [];
        $values['fields'] = array_map(fn($value) => $this->storedValue($value, $context), $context->fieldValues($linkType, $fields, ownerless: true));

        $link = clone $linkType;
        $link->field = $field;
        $link->setAttributes($values, false);
        if ($token !== null) {
            // Set after the attributes, which cast the ID of an element link to an integer.
            $link->linkValue = [$token];
        }

        return $link;
    }

    /**
     * Resolves the Reference a link to an element holds: the ID of its element in the Target environment, or a token
     * for the ID of a Stub or fetched asset. Returns null, after warning, if the link can't be imported.
     *
     * @param array<mixed> $portable
     */
    private function importElement(HyperField $field, array $portable, ElementLink $linkType, ImportContext $context): int|string|null
    {
        $uid = $portable['linkValue'] ?? null;

        return $context->embeddedId(
            is_string($uid) ? $uid : '',
            $linkType::elementType(),
            $field,
            sprintf('A %s link in the field "%s" doesn\'t point at a %s the Payload carries. The link will be skipped.', $linkType->label ?? $linkType->handle, $field->name, $linkType::displayName()),
            'linked',
            'The link will be removed.',
        );
    }

    /**
     * Returns a link type's custom field value as Hyper stores it, which is the form its field's input posts: relations
     * as a list of IDs, with a token for the ID of each Stub or fetched asset.
     */
    private function storedValue(mixed $value, ImportContext $context): mixed
    {
        if (!$value instanceof RelatedElements) {
            return $value;
        }

        return array_map(fn(ElementInterface $element) => $context->elementIdFor($element), $value->all());
    }
}
