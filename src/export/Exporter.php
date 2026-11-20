<?php

namespace simplygoodwork\craftdolly\export;

use benf\neo\elements\Block;
use Craft;
use craft\elements\Entry;
use DateTimeInterface;
use InvalidArgumentException;
use simplygoodwork\craftdolly\fields\FieldAdapterRegistry;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\schema\SchemaManifest;

/**
 * Builds a Payload envelope from Root elements.
 */
final class Exporter
{
    public const FORMAT_VERSION = 1;

    public function __construct(private readonly FieldAdapterRegistry $adapters)
    {
    }

    /**
     * @param array<Entry|Block> $roots a draft is exported with its own content, as the canonical entry it belongs to.
     * Roots are all entries, or all Matrix and Neo blocks (which are pasted into a field, never imported as entries)
     * @param list<int>|null $siteIds the sites to export content from, or null for every site the entries exist in
     * @return array<string, mixed>
     */
    public function export(array $roots, ?array $siteIds = null): array
    {
        $blocks = array_filter($roots, fn(Entry|Block $root) => ExportContext::isBlock($root));
        if ($blocks && count($blocks) !== count($roots)) {
            throw new InvalidArgumentException('A Payload holds whole entries or blocks, not both.');
        }
        if ($blocks) {
            $roots = $this->withoutNestedBlocks($roots);
        }

        $context = new ExportContext(
            $this->adapters,
            array_fill_keys(array_map(fn(Entry|Block $root) => ExportContext::rootUid($root), $roots), true),
            $siteIds,
        );
        $baseLevel = $blocks ? min(array_map(fn(Entry|Block $root) => $root instanceof Block ? (int)$root->level : 1, $roots)) : 1;
        $rootData = array_map(
            fn(Entry|Block $root) => $blocks ? $context->exportBlockRoot($root, $baseLevel) : $context->exportRoot($root),
            array_values($roots),
        );

        return [
            'formatVersion' => self::FORMAT_VERSION,
            'craftVersion' => Craft::$app->getVersion(),
            'pluginVersion' => Plugin::getInstance()->getVersion(),
            'source' => $this->source(),
            'schema' => (new SchemaManifest())->describe($context->schema()),
            'roots' => $rootData,
            'references' => $context->references(),
        ];
    }

    /**
     * Drops the Neo blocks that are nested under another Root element, which carries them as its child blocks.
     *
     * @param array<Entry|Block> $roots
     * @return array<Entry|Block>
     */
    private function withoutNestedBlocks(array $roots): array
    {
        $nestedIds = [];
        foreach ($roots as $root) {
            if ($root instanceof Block) {
                foreach (ExportContext::childBlocks($root) as $child) {
                    $nestedIds[$child->id] = true;
                }
            }
        }

        return array_values(array_filter($roots, fn(Entry|Block $root) => !isset($nestedIds[$root->id])));
    }

    /**
     * @return array<string, string|null>
     */
    private function source(): array
    {
        $primarySite = Craft::$app->getSites()->getPrimarySite();

        return [
            'siteUrl' => $primarySite->getBaseUrl(),
            'environment' => Craft::$app->env,
            'exportedAt' => (new \DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'exportedBy' => Craft::$app->getUser()->getIdentity()?->username,
        ];
    }
}
