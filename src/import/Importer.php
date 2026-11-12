<?php

namespace simplygoodwork\craftportage\import;

use Craft;
use craft\elements\Entry;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use simplygoodwork\craftportage\fields\FieldAdapterRegistry;
use simplygoodwork\craftportage\references\ElementTypes;
use simplygoodwork\craftportage\references\ReferenceResolver;
use Throwable;

/**
 * Applies a decoded Payload to the Target environment, one transaction per Root element.
 *
 * New entries are saved disabled. A Root element that can't be imported fails on its own without
 * affecting the others.
 */
final class Importer
{
    public function __construct(private readonly FieldAdapterRegistry $adapters)
    {
    }

    /**
     * @param array<string, mixed> $envelope
     * @return list<RootResult>
     */
    public function import(array $envelope): array
    {
        $uids = new UidClaims();
        $resolver = new ReferenceResolver();
        $references = is_array($envelope['references'] ?? null) ? $envelope['references'] : [];
        $results = [];

        foreach ($envelope['roots'] as $root) {
            $result = new RootResult(is_array($root) && is_string($root['uid'] ?? null) ? $root['uid'] : null);
            $results[] = $result;

            if (!is_array($root)) {
                $result->fail('The Root element data is damaged.');
                continue;
            }

            $this->importRoot($root, new ImportContext($this->adapters, $resolver, $references, $uids, $result));
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $root
     */
    private function importRoot(array $root, ImportContext $context): void
    {
        $result = $context->result;

        if (ElementTypes::classFor($root['elementType'] ?? null) !== Entry::class) {
            $result->fail('Unsupported element type: ' . Json::encode($root['elementType'] ?? null));
            return;
        }

        $section = is_string($root['section'] ?? null) ? Craft::$app->getEntries()->getSectionByUid($root['section']) : null;
        if (!$section) {
            $result->fail('The section is missing in this environment.');
            return;
        }

        $entryType = null;
        foreach ($section->getEntryTypes() as $candidate) {
            if ($candidate->uid === ($root['type'] ?? null)) {
                $entryType = $candidate;
                break;
            }
        }
        if (!$entryType) {
            $result->fail("The entry type is missing from the \"$section->name\" section in this environment.");
            return;
        }

        [$site, $content] = $this->firstAvailableSite($root['sites'] ?? null);
        if (!$site) {
            $result->fail('None of the entry\'s sites exist in this environment.');
            return;
        }

        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $entryType->id,
            'siteId' => $site->id,
        ]);
        $entry->uid = $context->claimUid($root['uid'] ?? null);
        $entry->enabled = false;
        if (is_string($content['title'] ?? null)) {
            $entry->title = $content['title'];
        }
        if (is_string($content['slug'] ?? null)) {
            $entry->slug = $content['slug'];
        }

        // Field adapters may write (e.g. future Stubs), so they run inside the Root element's transaction too.
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $entry->setFieldValues($context->fieldValues($entryType->getFieldLayout(), is_array($content['fields'] ?? null) ? $content['fields'] : []));
            if (!Craft::$app->getElements()->saveElement($entry)) {
                $transaction->rollBack();
                $result->fail('Craft couldn\'t save the entry: ' . implode(' ', $entry->getFirstErrors()));
                return;
            }
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            Craft::$app->getErrorHandler()->logException($e);
            $result->fail('Couldn\'t import the entry: ' . $e->getMessage());
            return;
        }

        $result->elementId = $entry->id;
    }

    /**
     * @return array{0: \craft\models\Site|null, 1: array<string, mixed>}
     */
    private function firstAvailableSite(mixed $sites): array
    {
        if (!is_array($sites)) {
            return [null, []];
        }

        $targetSites = ArrayHelper::index(Craft::$app->getSites()->getAllSites(), 'uid');
        foreach ($sites as $siteUid => $content) {
            $site = $targetSites[$siteUid] ?? null;
            if ($site && is_array($content)) {
                return [$site, $content];
            }
        }

        return [null, []];
    }
}
