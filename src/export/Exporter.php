<?php

namespace simplygoodwork\craftportage\export;

use Craft;
use craft\elements\Entry;
use DateTimeInterface;
use simplygoodwork\craftportage\fields\FieldAdapterRegistry;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\schema\SchemaManifest;

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
     * @param Entry[] $roots a draft is exported with its own content, as the canonical entry it belongs to
     * @param list<int>|null $siteIds the sites to export content from, or null for every site the entries exist in
     * @return array<string, mixed>
     */
    public function export(array $roots, ?array $siteIds = null): array
    {
        $context = new ExportContext(
            $this->adapters,
            array_fill_keys(array_map(fn(Entry $entry) => ExportContext::rootUid($entry), $roots), true),
            $siteIds,
        );
        $rootData = array_map(fn(Entry $entry) => $context->exportRoot($entry), array_values($roots));

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
