<?php

namespace simplygoodwork\craftportage\fields;

use Craft;
use craft\base\FieldInterface;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;
use craft\services\Elements;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\ImportContext;
use simplygoodwork\craftportage\import\PendingRefTag;
use simplygoodwork\craftportage\references\ElementTypes;

/**
 * The shared translator for Embedded references written as Craft reference tags (`{type:id@site:attr||fallback}`),
 * for every Field adapter whose values carry text.
 *
 * On Export, a tag's element ID becomes a Reference UID, and a numeric site becomes the site's UID. On Import, the
 * Reference resolves with the same rules and choices as a relation Reference.
 */
final class RefTags
{
    private const ID_MARKER = "\0id\0";

    /**
     * Replaces the element IDs in the text's reference tags with Reference UIDs, recording the References.
     *
     * The tag names the element type by its handle (`{entry:…}`), never a class. Tags that don't name an element by
     * ID (such as `{entry:news/hello}`), tags whose element doesn't exist and tags for element types Portage doesn't
     * support are left as they are.
     */
    public function export(string $text, ExportContext $context): string
    {
        return $this->replaceTags($text, function(array $tag) use ($context): ?string {
            if (!ctype_digit($tag['ref'])) {
                return null;
            }
            $class = Craft::$app->getElements()->getElementTypeByRefHandle($tag['elementType']);
            $element = $class ? Craft::$app->getElements()->getElementById((int)$tag['ref'], $class, '*') : null;
            if (!$element || ElementTypes::nameOf($element) === null) {
                return null;
            }

            if (ctype_digit($tag['site'])) {
                $tag['site'] = $context->siteUid((int)$tag['site']) ?? $tag['site'];
            }

            return $this->build((string)ElementTypes::nameOf($element), $context->addReference($element), $tag);
        });
    }

    /**
     * Replaces the Reference UIDs in the text's reference tags with the IDs of the elements they resolve to.
     *
     * An unresolved tag inside a link's opening tag (`<a href="{entry:…:url}">`) removes the link and keeps its text;
     * anywhere else, the tag is replaced with its fallback text, or removed. Both are reported as warnings.
     */
    public function import(string $text, FieldInterface $field, ImportContext $context): string
    {
        if (!str_contains($text, '{')) {
            return $text;
        }

        $parts = preg_split('/(<a\b[^>]*>.*?<\/a>)/is', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        foreach ($parts as $i => $part) {
            $parts[$i] = $i % 2 === 1
                ? $this->importLink($part, $field, $context)
                : $this->importTags($part, $field, $context);
        }

        return implode('', $parts);
    }

    /**
     * Replaces the Reference UIDs in a value made of reference tags (a Link field's `{entry:…:url}`) with the IDs of the
     * elements they resolve to. One unresolved tag clears the whole value: it returns null, after warning.
     */
    public function importValue(string $value, FieldInterface $field, ImportContext $context): ?string
    {
        return $this->importTagsOrNull($value, $field, $context, 'linked', 'The link will be cleared.');
    }

    /**
     * Translates the tags in a link, or removes the link and keeps its text if a tag in its opening tag is unresolved.
     */
    private function importLink(string $link, FieldInterface $field, ImportContext $context): string
    {
        if (!preg_match('/^(<a\b[^>]*>)(.*)<\/a>$/is', $link, $match)) {
            return $this->importTags($link, $field, $context);
        }

        $openingTag = $this->importTagsOrNull($match[1], $field, $context, 'linked', 'The link will be removed and its text kept.');
        $text = $this->importTags($match[2], $field, $context);

        return $openingTag === null ? $text : "$openingTag$text</a>";
    }

    /**
     * Translates every tag in the text, or returns null after warning if any is unresolved.
     *
     * @param string $usage how the field uses the tags, in a warning: "linked" or "referenced"
     * @param string $outcome what happens to an unresolved tag, in a warning
     */
    private function importTagsOrNull(string $text, FieldInterface $field, ImportContext $context, string $usage, string $outcome): ?string
    {
        $unresolved = false;
        $imported = $this->replaceTags($text, function(array $tag) use ($field, $context, $usage, $outcome, &$unresolved): ?string {
            $translated = $this->importTag($tag, $field, $context, $usage, $outcome);
            $unresolved = $unresolved || $translated === false;

            return $translated === false ? null : $translated;
        });

        return $unresolved ? null : $imported;
    }

    private function importTags(string $text, FieldInterface $field, ImportContext $context): string
    {
        return $this->replaceTags($text, function(array $tag) use ($field, $context): ?string {
            $translated = $this->importTag($tag, $field, $context, 'referenced', 'The reference tag will be replaced with its fallback text, or removed.');

            return $translated === false ? $tag['fallback'] : $translated;
        });
    }

    /**
     * @param array{elementType: string, ref: string, site: string, attr: string, fallback: string} $tag
     * @param string $usage how the field uses the tag, in a warning: "linked" or "referenced"
     * @param string $outcome what happens to an unresolved tag, in a warning
     * @return string|false|null the translated tag, false if it's unresolved, or null if it isn't an Embedded reference
     * (it doesn't name a whitelisted element by ID or by the UID of a Reference of its type) and stays as it is
     */
    private function importTag(array $tag, FieldInterface $field, ImportContext $context, string $usage, string $outcome): string|false|null
    {
        $ref = $tag['ref'];
        // Export names every whitelisted element type by its handle and translates its IDs, so an ID left in such a tag
        // named an element that didn't exist in the Source environment. Other element types aren't Embedded references.
        if (ctype_digit($ref)) {
            if (ElementTypes::classFor($tag['elementType']) === null) {
                return null;
            }
            $context->plan->warn(sprintf(
                'A reference tag in the field "%s" points at an element that didn\'t exist in the Source environment. %s',
                $field->name,
                $outcome,
            ));
            return false;
        }

        // A UID the Payload carries no Reference for was never translated by Export, so it isn't an Embedded reference.
        $reference = StringHelper::isUUID($ref) ? $context->reference($ref) : null;
        if (!$reference || $reference->elementType !== $tag['elementType']) {
            return null;
        }
        $name = "$reference->elementType {$reference->name()}";

        if (StringHelper::isUUID($tag['site'])) {
            $site = ArrayHelper::firstWhere(Craft::$app->getSites()->getAllSites(true), 'uid', $tag['site']);
            if (!$site) {
                $context->plan->warn(sprintf(
                    'The site "%s" in a reference tag to the %s in the field "%s" doesn\'t exist in this environment. %s',
                    $context->drift->get('sites', $tag['site'])->name ?? $tag['site'],
                    $name,
                    $field->name,
                    $outcome,
                ));
                return false;
            }
            $tag['site'] = (string)$site->id;
        }

        $target = $context->resolveReference($ref, $field);
        if (!$target) {
            $context->plan->warn(sprintf(
                'The %s %s in the field "%s" doesn\'t exist in this environment. %s',
                $name,
                $usage,
                $field->name,
                $outcome,
            ));
            return false;
        }

        if ($target->id !== null) {
            return $this->build((string)$reference->elementType, (string)$target->id, $tag);
        }

        // A Stub or fetched asset, saved by the Importer.
        [$before, $after] = explode(self::ID_MARKER, $this->build((string)$reference->elementType, self::ID_MARKER, $tag), 2);
        $pending = new PendingRefTag($target, $before, $after, $tag['fallback']);
        $context->plan->pendingRefTags[$pending->token] = $pending;

        return $pending->token;
    }

    /**
     * @param callable(array{elementType: string, ref: string, site: string, attr: string, fallback: string}): (string|null) $translate
     * returns the translated tag, or null to leave it as it is
     */
    private function replaceTags(string $text, callable $translate): string
    {
        if (!str_contains($text, '{')) {
            return $text;
        }

        return (string)preg_replace_callback(Elements::REF_TAG_PATTERN, function(array $match) use ($translate): string {
            $tag = [
                'elementType' => $match['elementType'],
                'ref' => $match['ref'],
                'site' => $match['site'] ?? '',
                'attr' => $match['attr'] ?? '',
                'fallback' => $match['fallback'] ?? '',
            ];

            return $translate($tag) ?? $match[0];
        }, $text);
    }

    /**
     * @param array{site: string, attr: string, fallback: string} $tag
     */
    private function build(string $elementType, string $ref, array $tag): string
    {
        return '{' . $elementType . ':' . $ref
            . ($tag['site'] !== '' ? '@' . $tag['site'] : '')
            . ($tag['attr'] !== '' ? ':' . $tag['attr'] : '')
            . ($tag['fallback'] !== '' ? '||' . $tag['fallback'] : '')
            . '}';
    }
}
