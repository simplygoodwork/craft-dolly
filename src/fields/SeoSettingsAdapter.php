<?php

namespace simplygoodwork\craftportage\fields;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\helpers\Json;
use nystudio107\seomatic\models\MetaBundle;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\ImportContext;
use yii\base\Arrayable;

/**
 * SEOmatic's SEO Settings field: the overrides an element stores in the field (SEOmatic's `MetaBundle` model: its meta
 * global vars, site vars, sitemap vars and bundle settings) travel as they are, so a title, description, robots or any
 * other override, including its Twig and object templates, arrives verbatim.
 *
 * The asset IDs in the bundle settings' SEO, Facebook and Twitter image lists are Embedded references, resolved with the
 * usual rules, Stub and fetch choices. Only images can be used: a fetched file of another kind is cleared with a
 * warning. SEOmatic derives the image URLs and sizes in the meta global vars from those IDs each time it normalizes the
 * value, so they're left out of the Payload (they would carry source IDs) and rebuilt on Import. An image that can't be
 * resolved is cleared with a warning.
 *
 * Limits: an asset ID written by hand inside a Twig template is code, and moves as it is. So do the asset IDs in the
 * site vars' identity and creator images, which the field's own input doesn't edit. A setting that pulls its text or image
 * from another field of the element holds that field's handle, with no Drift check; Import warns when the Target
 * element has no such field.
 *
 * Section-level SEOmatic meta bundles belong to SEOmatic's own configuration and are never read or written.
 */
final class SeoSettingsAdapter implements FieldAdapter, CompletesTokens
{
    /**
     * The parts of the field's value that Portage moves. The rest of the bundle (its meta containers, redirects and
     * source details) is SEOmatic's own defaults, rebuilt when the value is normalized.
     */
    private const PARTS = ['metaGlobalVars', 'metaSiteVars', 'metaSitemapVars', 'metaBundleSettings'];

    /**
     * The three images, by the prefix of their settings: `seoImageIds` lists the assets, `seoImageSource` says where the
     * image comes from, and `seoImage`, `seoImageWidth` and `seoImageHeight` in the meta global vars are derived from them.
     */
    private const IMAGES = ['seo', 'og', 'twitter'];

    /**
     * The sources that make SEOmatic derive an image's URL and size, so they needn't travel.
     */
    private const DERIVED_SOURCES = ['fromAsset', 'sameAsSeo', 'fromField'];

    /**
     * The sources that pull a value from a field of the element, named by the `…Field` setting.
     */
    private const PULL_SOURCES = ['fromField', 'summaryFromField', 'keywordsFromField'];

    /**
     * @return array<string, array<mixed>> the parts of the value, with the image IDs replaced by Reference UIDs. An
     * image that no longer exists is left out.
     */
    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        if (!$value instanceof MetaBundle) {
            return [];
        }

        $parts = $this->partsOf($value);
        $ids = [];
        foreach (self::IMAGES as $image) {
            foreach ($parts['metaBundleSettings']["{$image}ImageIds"] ?? [] as $id) {
                if (is_numeric($id)) {
                    $ids[(int)$id] = true;
                }
            }
        }
        $assets = $ids ? Asset::find()->id(array_keys($ids))->status(null)->site('*')->unique()->indexBy('id')->all() : [];

        foreach (self::IMAGES as $image) {
            $settings = $parts['metaBundleSettings'];
            $imageIds = $settings["{$image}ImageIds"] ?? null;
            if (is_array($imageIds)) {
                $uids = [];
                foreach ($imageIds as $id) {
                    $asset = is_numeric($id) ? $assets[(int)$id] ?? null : null;
                    if ($asset) {
                        $uids[] = $context->addReference($asset);
                    }
                }
                $parts['metaBundleSettings']["{$image}ImageIds"] = $uids;
            }

            if (in_array($settings["{$image}ImageSource"] ?? null, self::DERIVED_SOURCES, true)) {
                // Blanked rather than left out, so the vars still hold something for SEOmatic to derive them into.
                foreach (['', 'Width', 'Height'] as $suffix) {
                    $parts['metaGlobalVars']["{$image}Image$suffix"] = '';
                }
            }
        }

        return $parts;
    }

    /**
     * @return array<string, array<mixed>> input for the field's `normalizeValue()`, with the image Reference UIDs
     * replaced by the IDs of the assets they resolve to, or a token for the ID of a fetched asset
     */
    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        $parts = [];
        foreach (self::PARTS as $part) {
            $parts[$part] = is_array($portable) && is_array($portable[$part] ?? null) ? $portable[$part] : [];
        }

        foreach (self::IMAGES as $image) {
            $uids = $parts['metaBundleSettings']["{$image}ImageIds"] ?? null;
            if (!is_array($uids)) {
                continue;
            }

            $ids = [];
            foreach ($uids as $uid) {
                $id = is_string($uid) ? $this->importImage($field, $uid, $context) : null;
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
            $parts['metaBundleSettings']["{$image}ImageIds"] = $ids;
        }
        $this->warnAboutMissingSourceFields($field, $parts['metaBundleSettings'], $context);

        return $parts;
    }

    /**
     * Replaces the tokens of fetched assets in the SEO images' IDs, and in the Twig SEOmatic derived from them, and drops
     * the IDs of images whose fetch failed, which have no asset left to use. The fetch failure is already reported.
     *
     * @return array<string, array<mixed>>|null
     */
    public function completeTokens(mixed $value, ElementInterface $element, callable $render): ?array
    {
        if (!$value instanceof MetaBundle) {
            return null;
        }

        $parts = $this->partsOf($value);
        if (!$render($parts)) {
            return null;
        }

        foreach (self::IMAGES as $image) {
            $ids = $parts['metaBundleSettings']["{$image}ImageIds"] ?? null;
            if (is_array($ids)) {
                $parts['metaBundleSettings']["{$image}ImageIds"] = array_values(array_map(
                    fn($id) => is_numeric($id) ? (int)$id : $id,
                    array_filter($ids, fn($id) => $id !== ''),
                ));
            }
        }

        return $parts;
    }

    /**
     * Returns the parts of a field value that Portage moves, as plain arrays.
     *
     * @return array<string, array<mixed>>
     */
    private function partsOf(MetaBundle $bundle): array
    {
        $parts = [];
        foreach (self::PARTS as $part) {
            $value = $bundle->$part;
            $array = $value instanceof Arrayable ? $value->toArray() : $value;
            // Through JSON, so that nothing but plain data is left.
            $decoded = is_array($array) ? Json::decode(Json::encode($array)) : [];
            $parts[$part] = is_array($decoded) ? $decoded : [];
        }

        return $parts;
    }

    /**
     * Resolves the Reference an image holds: the ID of its asset in the Target environment, or a token for the ID of a
     * fetched asset. Returns null, after warning, if the image can't be used: it doesn't resolve, or it isn't an image.
     */
    private function importImage(FieldInterface $field, string $uid, ImportContext $context): int|string|null
    {
        $id = $context->embeddedId(
            $uid,
            Asset::class,
            $field,
            sprintf('An SEO image in the field "%s" doesn\'t point at an asset the Payload carries. The image will be cleared.', $field->name),
            'used as an SEO image',
            'The image will be cleared.',
            [Asset::KIND_IMAGE],
        );

        $reference = $context->reference($uid);
        $target = $id !== null ? $reference?->target() : null;
        if ($target instanceof Asset && $target->id !== null && $target->kind !== Asset::KIND_IMAGE) {
            $context->plan->warn(sprintf('The asset "%s" used as an SEO image in the field "%s" isn\'t an image. The image will be cleared.', $target->title ?? $target->getFilename(), $field->name));
            return null;
        }

        return $id;
    }

    /**
     * Warns about each setting that pulls a value from a field of the element when the element has no such field in this
     * environment: SEOmatic would render nothing for it.
     *
     * @param array<mixed> $settings the bundle settings
     */
    private function warnAboutMissingSourceFields(FieldInterface $field, array $settings, ImportContext $context): void
    {
        foreach ($settings as $setting => $handle) {
            if (!is_string($handle) || !preg_match('/^(\w+)Field$/', (string)$setting, $match) || !preg_match('/^\w+$/', $handle)) {
                continue;
            }
            if (!in_array($settings["{$match[1]}Source"] ?? null, self::PULL_SOURCES, true) || $handle === 'title') {
                continue;
            }

            if ($context->layoutHasField($handle) === false) {
                $context->plan->warn(sprintf(
                    'The SEO setting "%s" in the field "%s" pulls from the field "%s", which this element doesn\'t have in this environment. It will pull nothing.',
                    $setting,
                    $field->name,
                    $handle,
                ));
            }
        }
    }
}
