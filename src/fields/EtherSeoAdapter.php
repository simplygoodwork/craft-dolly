<?php

namespace simplygoodwork\craftdolly\fields;

use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\Json;
use ether\seo\models\data\SeoData;
use ether\seo\models\data\SocialData;
use simplygoodwork\craftdolly\export\ExportContext;
use simplygoodwork\craftdolly\import\ImportContext;

/**
 * Ether's SEO field: an element's title and description overrides, keywords, score, social (Twitter and Facebook)
 * title, description and image, and its robots and canonical URL.
 *
 * Everything travels as it is, except the social images. They are asset IDs (an Embedded reference), so they travel as
 * References and resolve with the usual rules, Stub and fetch choices. Only images can be used: anything else is
 * cleared with a warning, as is an image that can't be resolved.
 *
 * Ether fills parts of the value from the environment's own settings each time it loads it: empty robots get the
 * global default robots, an empty social title or description gets the element's SEO title or description, and each
 * network's handle is always the site's Twitter handle or Facebook app ID. The robots and social texts are therefore
 * exported as the element stores them in the database, not as loaded, so the Target environment fills what the
 * element left empty with its own defaults. The handles are left out.
 *
 * The canonical URL moves as it is, so it can point at the Source environment.
 */
final class EtherSeoAdapter implements CompletesTokens
{
    /**
     * @return array{titleRaw: array<mixed>|string, descriptionRaw: string, keywords: array<mixed>, score: string, social: array<string, array{title: string, description: string, image: string|null}>, advanced: array{robots: list<string>, canonical: string|null}}|null
     * the value, with each social image replaced by its asset's Reference UID, or null for a value that isn't Ether's
     */
    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        if (!$value instanceof SeoData) {
            return null;
        }

        $stored = $this->storedValue($field, $context->element());

        $social = [];
        foreach ($value->social as $network => $data) {
            if (!is_string($network)) {
                continue;
            }
            if ($stored !== null) {
                $raw = $stored['social'][$network] ?? null;
                $raw = is_array($raw) ? $raw : [];
                $title = $raw['title'] ?? '';
                $description = $raw['description'] ?? '';
                $image = $raw['imageId'] ?? $raw['image'] ?? null;
            } else {
                $title = $data instanceof SocialData ? $data->title : '';
                $description = $data instanceof SocialData ? $data->description : '';
                $image = $data instanceof SocialData ? $data->imageId : null;
            }

            $id = $this->assetId($image);
            $asset = $id !== null ? Asset::find()->id($id)->status(null)->site('*')->unique()->one() : null;
            $social[$network] = [
                'title' => is_scalar($title) ? (string)$title : '',
                'description' => is_scalar($description) ? (string)$description : '',
                'image' => $asset ? $context->addReference($asset) : null,
            ];
        }

        $robots = $stored !== null ? ($stored['advanced']['robots'] ?? []) : ($value->advanced['robots'] ?? []);
        $canonical = $value->advanced['canonical'] ?? null;

        return [
            'titleRaw' => $this->plain($value->titleRaw),
            'descriptionRaw' => is_scalar($value->descriptionRaw) ? (string)$value->descriptionRaw : '',
            // Ether makes sure the keywords are an array when it loads the value.
            'keywords' => $this->plain($value->keywords),
            'score' => (string)$value->score,
            'social' => $social,
            'advanced' => [
                'robots' => $this->robots($robots),
                'canonical' => is_scalar($canonical) && $canonical !== '' ? (string)$canonical : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null input for the field's `normalizeValue()`, with each social image's Reference
     * UID replaced by the ID of the asset it resolves to, or a token for the ID of a fetched asset; or null for no value
     */
    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        if (!is_array($portable)) {
            return null;
        }

        $social = [];
        foreach (is_array($portable['social'] ?? null) ? $portable['social'] : [] as $network => $data) {
            if (!is_string($network) || !is_array($data)) {
                continue;
            }
            $image = $data['image'] ?? null;
            $social[$network] = [
                'title' => is_string($data['title'] ?? null) ? $data['title'] : '',
                'description' => is_string($data['description'] ?? null) ? $data['description'] : '',
                'imageId' => is_string($image) && $image !== '' ? $this->importImage($field, $network, $image, $context) : null,
            ];
        }

        $advanced = is_array($portable['advanced'] ?? null) ? $portable['advanced'] : [];
        $titleRaw = $portable['titleRaw'] ?? [];

        return [
            'titleRaw' => is_array($titleRaw) || is_string($titleRaw) ? $titleRaw : [],
            'descriptionRaw' => is_string($portable['descriptionRaw'] ?? null) ? $portable['descriptionRaw'] : '',
            'keywords' => is_array($portable['keywords'] ?? null) ? $portable['keywords'] : [],
            'score' => is_string($portable['score'] ?? null) ? $portable['score'] : '',
            'social' => $social,
            'advanced' => [
                'robots' => $this->robots($advanced['robots'] ?? []),
                'canonical' => is_string($advanced['canonical'] ?? null) ? $advanced['canonical'] : null,
            ],
        ];
    }

    /**
     * Replaces the token of a fetched asset in a social image. An image whose fetch failed has no asset left to use, so
     * it's cleared; the fetch failure is already reported.
     *
     * @return array<string, mixed>|null
     */
    public function completeTokens(mixed $value, ElementInterface $element, callable $render, array $linkTokens): ?array
    {
        if (!$value instanceof SeoData) {
            return null;
        }

        $social = [];
        foreach ($value->social as $network => $data) {
            $social[$network] = $data instanceof SocialData
                ? ['title' => $data->title, 'description' => $data->description, 'imageId' => $data->imageId]
                : $data;
        }
        if (!$render($social)) {
            return null;
        }
        foreach ($social as $network => $data) {
            if (is_array($data) && is_string($data['imageId'] ?? null)) {
                $social[$network]['imageId'] = ctype_digit($data['imageId']) ? (int)$data['imageId'] : null;
            }
        }

        return [
            'titleRaw' => $value->titleRaw,
            'descriptionRaw' => $value->descriptionRaw,
            'keywords' => $value->keywords,
            'score' => $value->score,
            'social' => $social,
            'advanced' => $value->advanced,
        ];
    }

    /**
     * Returns the field's value as the element stores it in the database, as an array (empty when it stores none), or
     * null if that can't be known: the element isn't saved, or its value has changed since it was loaded.
     *
     * @return array<mixed>|null
     */
    private function storedValue(FieldInterface $field, ?ElementInterface $element): ?array
    {
        $uid = $field->layoutElement?->uid;
        if (!$element || !$element->id || !$uid || $element->isFieldDirty($field->handle)) {
            return null;
        }

        $content = (new Query())
            ->select('content')
            ->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $element->id, 'siteId' => $element->siteId])
            ->scalar();
        if ($content === false) {
            return null;
        }

        $content = is_string($content) ? Json::decodeIfJson($content) : $content;
        $stored = is_array($content) ? $content[$uid] ?? null : null;
        $stored = is_string($stored) ? Json::decodeIfJson($stored) : $stored;

        return is_array($stored) ? $stored : [];
    }

    /**
     * Returns the asset ID a social image holds. Ether has stored it as an ID, a list of `{id}`, and a whole asset.
     */
    private function assetId(mixed $image): ?int
    {
        if ($image instanceof Asset) {
            return $image->id;
        }
        if (is_array($image)) {
            $image = array_key_exists(0, $image) ? $image[0] : $image['id'] ?? null;
            if (is_array($image)) {
                $image = $image['id'] ?? null;
            }
        }
        if (is_int($image)) {
            return $image ?: null;
        }

        return is_string($image) && ctype_digit($image) && $image !== '0' ? (int)$image : null;
    }

    /**
     * Returns the robots as a list of their non-empty values. Ether drops the empty ones without renumbering the rest.
     *
     * @return list<string>
     */
    private function robots(mixed $robots): array
    {
        if (!is_array($robots)) {
            return [];
        }

        return array_values(array_filter($robots, fn($robot) => is_string($robot) && $robot !== ''));
    }

    /**
     * Returns a value as plain data, through JSON, so nothing but arrays and scalars is left.
     */
    private function plain(mixed $value): mixed
    {
        return Json::decode(Json::encode($value));
    }

    /**
     * Resolves the Reference a social image holds: the ID of its asset in the Target environment, or a token for the
     * ID of a fetched asset. Returns null, after warning, if the image can't be used: it doesn't resolve, or it isn't
     * an image.
     */
    private function importImage(FieldInterface $field, string $network, string $uid, ImportContext $context): int|string|null
    {
        $usage = sprintf('used as the %s image', ucfirst($network));
        $id = $context->embeddedId(
            $uid,
            Asset::class,
            $field,
            sprintf('The %s image in the field "%s" doesn\'t point at an asset the Payload carries. The image will be cleared.', ucfirst($network), $field->name),
            $usage,
            'The image will be cleared.',
            [Asset::KIND_IMAGE],
        );

        $target = $id !== null ? $context->reference($uid)?->target() : null;
        if ($target instanceof Asset && $target->id !== null && $target->kind !== Asset::KIND_IMAGE) {
            $context->plan->warn(sprintf('The asset "%s" %s in the field "%s" isn\'t an image. The image will be cleared.', $target->title ?? $target->getFilename(), $usage, $field->name));
            return null;
        }

        return $id;
    }
}
