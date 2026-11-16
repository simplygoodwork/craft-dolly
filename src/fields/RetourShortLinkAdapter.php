<?php

namespace simplygoodwork\craftportage\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\helpers\ArrayHelper;
use craft\helpers\UrlHelper;
use nystudio107\retour\fields\ShortLink;
use nystudio107\retour\helpers\Text;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\ImportContext;

/**
 * Retour short links: the URL travels as it is.
 *
 * Saving an element with a short link makes Retour create a redirect from that URL to the element, keyed by the URL
 * and site. Retour refuses to validate a short link whose URL is already a redirect of another element or a manual
 * redirect, and a save that skips validation (a draft, once it's applied) takes that redirect over, rewriting the other
 * element's short link. So on Import a short link whose URL is already a redirect that isn't the existing entry's own
 * (the overwritten entry, or the entry blocks are pasted into) is skipped with a warning.
 *
 * A short link in a block makes a redirect to the entry at the top that owns it (Retour's root element), so that's the
 * entry whose own redirect it may be. And a short link that an earlier Root element of the same Import takes for
 * another entry is skipped with a warning too, or the later save would take over its redirect.
 */
final class RetourShortLinkAdapter implements FieldAdapter
{
    public function export(FieldInterface $field, mixed $value, ExportContext $context): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function import(FieldInterface $field, mixed $portable, ImportContext $context): string|SkippedValue|null
    {
        if (!$field instanceof ShortLink || !is_string($portable) || $portable === '') {
            return null;
        }

        $urls = $this->urls($field, $portable, $context);
        $redirect = $this->otherRedirect($urls, $context);
        if ($redirect === null) {
            $first = $context->claim('retour:' . $urls[1]);
            if ($first === null) {
                return $portable;
            }

            $context->plan->warn(sprintf(
                'The short link "%s" in the field "%s" is also the short link of %s in this Payload, which is imported first. That value will be skipped.',
                $portable,
                $field->name,
                $first->label(),
            ));

            return SkippedValue::Skipped;
        }

        $owner = $this->owner($redirect);
        $context->plan->warn(sprintf(
            'The short link "%s" in the field "%s" is already %s in this environment. That value will be skipped.',
            $portable,
            $field->name,
            $owner === null
                ? 'a redirect in Retour'
                : sprintf('the short link of the %s "%s"', $owner::lowerDisplayName(), $owner->title ?? $owner->id),
        ));

        return SkippedValue::Skipped;
    }

    /**
     * Returns the URLs a short link's redirect is looked up by: the URL as Retour's validation looks it up, and the URL
     * Retour would save (a full URL in the site being planned, for a field that matches full URLs).
     *
     * @return array{0: string, 1: string}
     */
    private function urls(ShortLink $field, string $url, ImportContext $context): array
    {
        $saved = $url;
        $siteId = ArrayHelper::firstWhere(Craft::$app->getSites()->getAllSites(true), 'uid', $context->siteUid)?->id;
        if ($field->redirectSrcMatch !== 'pathonly' && !str_starts_with($url, 'http') && $siteId !== null) {
            $saved = UrlHelper::siteUrl($url, null, null, $siteId);
        }
        // Retour saves the URL decoded and trimmed, with a leading slash unless it's a full URL.
        $saved = trim(str_replace(["\r", "\n"], '', urldecode($saved)));
        if (!UrlHelper::isFullUrl($saved)) {
            $saved = '/' . ltrim($saved, '/');
        }

        return [Text::cleanupText($url), $saved];
    }

    /**
     * Returns a redirect from one of the short link's URLs that doesn't belong to the existing entry the value goes to,
     * or null if there isn't one.
     *
     * @param array{0: string, 1: string} $urls
     * @return array<string, mixed>|null
     */
    private function otherRedirect(array $urls, ImportContext $context): ?array
    {
        $redirects = (new Query())
            ->from(['{{%retour_static_redirects}}'])
            ->where(['redirectSrcUrl' => array_values(array_unique($urls))])
            ->all();
        foreach ($redirects as $redirect) {
            $elementId = (int)($redirect['associatedElementId'] ?? 0);
            if ($elementId === 0 || $elementId !== $context->existingEntryId) {
                return $redirect;
            }
        }

        return null;
    }

    /**
     * Returns the element a redirect belongs to, or null for a manual redirect (or one whose element is gone).
     *
     * @param array<string, mixed> $redirect
     */
    private function owner(array $redirect): ?ElementInterface
    {
        $elementId = (int)($redirect['associatedElementId'] ?? 0);
        if ($elementId === 0) {
            return null;
        }
        $elementType = Craft::$app->getElements()->getElementTypeById($elementId);
        if ($elementType === null || !is_subclass_of($elementType, ElementInterface::class)) {
            return null;
        }

        return $elementType::find()->id($elementId)->status(null)->site('*')->one();
    }
}
