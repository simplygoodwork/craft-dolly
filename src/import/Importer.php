<?php

namespace simplygoodwork\craftportage\import;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\helpers\FileHelper;
use craft\services\Elements;
use craft\services\Structures;
use Throwable;
use yii\db\Transaction;

/**
 * Executes an ImportPlan, one transaction per Root element.
 *
 * New entries are saved disabled, so required fields left empty by skipped values don't fail the save. A Root
 * element that is blocked or can't be saved fails on its own without affecting the others. An asset that can't be
 * fetched is reported, and its relations skipped, without failing the Root element.
 */
final class Importer
{
    public function __construct(private readonly AssetFetcher $fetcher = new AssetFetcher())
    {
    }

    /**
     * @return list<RootResult>
     */
    public function import(ImportPlan $plan): array
    {
        $results = [];
        $pending = array_merge(...array_map(fn(RootPlan $root) => $root->pendingRefTags, $plan->roots));
        $completeRefTags = fn(ElementEvent $event) => $this->completeRefTags($event->element, $pending);

        $elements = Craft::$app->getElements();
        $elements->on(Elements::EVENT_BEFORE_SAVE_ELEMENT, $completeRefTags);
        try {
            return array_map(fn(RootPlan $root) => $this->importOnce($root, $results), $plan->roots);
        } finally {
            $elements->off(Elements::EVENT_BEFORE_SAVE_ELEMENT, $completeRefTags);
        }
    }

    /**
     * Completes the reference tags pointing at Stubs and fetched assets in an element's text values, as it's saved.
     * The Stubs and assets are saved first, so they have IDs by then (unless an asset's fetch failed).
     *
     * @param array<string, PendingRefTag> $pending by token
     */
    private function completeRefTags(ElementInterface $element, array $pending): void
    {
        if (!$pending) {
            return;
        }

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $value = $element->getFieldValue($field->handle);
            if (is_string($value)) {
                $completed = PendingRefTag::renderIn($value, $pending);
                if ($completed !== $value) {
                    $element->setFieldValue($field->handle, $completed);
                }
            }
        }
    }

    /**
     * Imports a Root element, once, after the Root element it will be placed under, and returns its result.
     *
     * @param array<int, RootResult> $results the Root elements imported so far, by plan object ID
     */
    private function importOnce(RootPlan $plan, array &$results): RootResult
    {
        $id = spl_object_id($plan);
        if (!isset($results[$id])) {
            $parentRoot = $plan->placement?->parentRoot;
            $results[$id] = $this->importRoot($plan, $parentRoot ? $this->importOnce($parentRoot, $results) : null);
        }

        return $results[$id];
    }

    /**
     * @param RootResult|null $parentRootResult the result of the Root element the entry will be placed under, if any
     */
    private function importRoot(RootPlan $plan, ?RootResult $parentRootResult): RootResult
    {
        $result = RootResult::fromPlan($plan);
        if ($plan->overwrite) {
            return $this->overwriteRoot($plan, $plan->overwrite, $result);
        }
        $entry = $plan->entry;

        if ($plan->isBlocked() || !$entry) {
            $result->fail($plan->blockedBy ?? 'The Root element can\'t be imported.');
            return $result;
        }

        $created = [];
        $parentId = null;
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $error = $this->saveStubs($plan, $result, $created);
            if ($error === null) {
                $this->fetchAssets($plan, $result, $created);
            }
            // After the Stubs, as the parent may be one.
            $parentId = $plan->placement?->parentId($parentRootResult);
            if ($parentId) {
                $entry->setParentId($parentId);
            }
            if ($error === null && !Craft::$app->getElements()->saveElement($entry)) {
                $error = 'Craft couldn\'t save the entry: ' . implode(' ', $entry->getFirstErrors());
            }
            if ($error === null && $plan->placement?->moveToEnd) {
                $this->moveToEnd($entry, $parentId);
            }
            foreach ($plan->sites as $site) {
                if ($error !== null) {
                    break;
                }
                $error = $this->saveSite($entry, $site);
            }
            if ($error !== null) {
                $this->rollBack($transaction, $created);
                $result->fail($error);
                return $result;
            }
            $transaction->commit();
        } catch (Throwable $e) {
            $this->rollBack($transaction, $created);
            Craft::$app->getErrorHandler()->logException($e);
            $result->fail('Couldn\'t import the entry: ' . $e->getMessage());
            return $result;
        }

        $result->elementId = $entry->id;
        if ($plan->placement?->parentRoot && !$parentId) {
            $result->warnings[] = sprintf(
                'The parent entry %s couldn\'t be imported. The entry was placed at the top level of the "%s" section.',
                $plan->placement->parentName,
                $entry->getSection()?->name,
            );
        }

        return $result;
    }

    /**
     * Moves a new Structure entry after its siblings, in a section where Craft places new entries first.
     */
    private function moveToEnd(Entry $entry, ?int $parentId): void
    {
        $structureId = (int)$entry->getSection()?->structureId;
        $structures = Craft::$app->getStructures();

        if ($parentId) {
            $structures->append($structureId, $entry, $parentId, Structures::MODE_UPDATE);
        } else {
            $structures->appendToRoot($structureId, $entry, Structures::MODE_UPDATE);
        }
    }

    /**
     * Saves the Stubs the Root element's relations point at, and records them on the result.
     *
     * @param ElementInterface[] $created collects the Stubs this saved, so a rollback can forget their IDs
     * @return string|null the error, or null if they saved
     */
    private function saveStubs(RootPlan $plan, RootResult $result, array &$created): ?string
    {
        foreach ($plan->stubs as $reference) {
            $stub = $reference->stub;
            // A Stub shared with an earlier Root element is already saved.
            if (!$stub || $stub->id !== null) {
                continue;
            }
            if (!Craft::$app->getElements()->saveElement($stub)) {
                return "Craft couldn't create the Stub for {$reference->name()}: " . implode(' ', $stub->getFirstErrors());
            }
            $created[] = $stub;
            $result->stubs[] = [
                'uid' => $reference->uid,
                'elementType' => $reference->elementType,
                'title' => $reference->title,
                'elementId' => (int)$stub->id,
            ];
        }

        return null;
    }

    /**
     * Fetches the assets the Root element's relations point at and saves them with the metadata they carry, after the
     * Stubs their own fields relate to. A failed fetch is recorded on the result, and the relations to the asset are
     * dropped from the Root element's values.
     *
     * @param ElementInterface[] $created collects the assets this saved, so a rollback can forget them
     */
    private function fetchAssets(RootPlan $plan, RootResult $result, array &$created): void
    {
        foreach ($plan->fetches as $reference) {
            $fetch = $reference->fetch;
            // An asset fetched for an earlier Root element is already saved.
            if (!$fetch || $fetch->asset->id !== null) {
                continue;
            }
            if ($fetch->failure === null) {
                $fetch->failure = $this->fetchAsset($fetch);
                if ($fetch->failure === null) {
                    $created[] = $fetch->asset;
                    $result->fetchedAssets[] = [
                        'uid' => $reference->uid,
                        'title' => $reference->title,
                        'elementId' => (int)$fetch->asset->id,
                    ];
                    continue;
                }
            }
            $result->fetchFailures[] = [
                'uid' => $reference->uid,
                'title' => $reference->title,
                'url' => $fetch->url,
                'reason' => $fetch->failure,
            ];
            $result->warnings[] = "The asset {$reference->name()} couldn't be fetched from $fetch->url: $fetch->failure. Its relations were skipped.";
        }

        if ($result->fetchFailures) {
            foreach ($plan->relations as $related) {
                $related->dropUnsaved();
            }
        }
    }

    /**
     * Downloads an asset's file and saves the asset into its folder, creating the folder if needed.
     *
     * @return string|null why it failed, or null if it was saved
     */
    private function fetchAsset(AssetFetch $fetch): ?string
    {
        $asset = $fetch->asset;
        try {
            $tempPath = $this->fetcher->download($fetch->url, $asset->getFilename());
        } catch (AssetFetchFailedException $e) {
            return $e->getMessage();
        }

        // The assets this one's fields relate to were fetched first; drop those that failed.
        foreach ($fetch->fieldPlan->relations as $related) {
            $related->dropUnsaved();
        }
        $folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume($fetch->folderPath, $fetch->volume, false);
        $asset->tempFilePath = $tempPath;
        $asset->newFolderId = $folder->id;
        // A file with the same name may already be in the folder, as a different asset.
        $asset->avoidFilenameConflicts = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            FileHelper::unlink($tempPath);
            $asset->id = null;

            return 'Craft couldn\'t save it: ' . implode(' ', $asset->getFirstErrors());
        }

        return null;
    }

    /**
     * Creates a draft on the matched entry and applies the Payload's content to it, in one transaction with the Stubs
     * its relations need. The canonical entry is never changed.
     */
    private function overwriteRoot(RootPlan $root, OverwritePlan $plan, RootResult $result): RootResult
    {
        $name = "\"{$plan->canonical->title}\"";
        $created = [];
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $error = $this->saveStubs($root, $result, $created);
            if ($error === null) {
                $this->fetchAssets($root, $result, $created);
            }
            $draft = null;
            if ($error === null) {
                $draft = Craft::$app->getDrafts()->createDraft(
                    $plan->canonical,
                    Craft::$app->getUser()->getId(),
                    'Portage import',
                    'Created by a Portage Import.',
                );
            }
            foreach ($plan->sites as $site) {
                if ($error !== null || !$draft) {
                    break;
                }
                $error = $this->saveDraftSite($draft, $site);
            }
            if ($error !== null || !$draft) {
                $this->rollBack($transaction, $created);
                $result->fail($error ?? "Craft couldn't create a draft on the entry $name.");
                return $result;
            }
            $transaction->commit();
        } catch (Throwable $e) {
            $this->rollBack($transaction, $created);
            Craft::$app->getErrorHandler()->logException($e);
            $result->fail("Couldn't overwrite the entry $name: " . $e->getMessage());
            return $result;
        }

        $result->status = RootResult::STATUS_OVERWRITTEN;
        $result->elementId = (int)$plan->canonical->id;
        $result->draftId = (int)$draft->draftId;

        return $result;
    }

    /**
     * Applies the Payload's content for one site to the draft: its title, slug and the field values it carries.
     *
     * @return string|null the error, or null if it saved
     */
    private function saveDraftSite(Entry $draft, SitePlan $plan): ?string
    {
        $siteName = $plan->site->getName();
        $siteDraft = Entry::find()->id($draft->id)->drafts()->siteId($plan->site->id)->status(null)->one();
        if (!$siteDraft) {
            return "Craft didn't create the draft in the site \"$siteName\".";
        }

        if ($plan->title !== null) {
            $siteDraft->title = $plan->title;
        }
        if ($plan->slug !== null) {
            $siteDraft->slug = $plan->slug;
        }
        $siteDraft->setFieldValues($plan->fieldValues);
        // A draft may be incomplete, like any draft an editor saves.
        $siteDraft->setScenario(Element::SCENARIO_ESSENTIALS);

        if (!Craft::$app->getElements()->saveElement($siteDraft)) {
            return "Craft couldn't save the draft's content for the site \"$siteName\": " . implode(' ', $siteDraft->getFirstErrors());
        }

        return null;
    }

    /**
     * Saves the entry's content for one of its other sites, which the first save propagated it to.
     *
     * @return string|null the error, or null if it saved
     */
    private function saveSite(Entry $entry, SitePlan $plan): ?string
    {
        $siteName = $plan->site->getName();
        $translation = Entry::find()->id($entry->id)->siteId($plan->site->id)->status(null)->one();
        if (!$translation) {
            return "Craft didn't create the entry in the site \"$siteName\".";
        }

        if ($plan->title !== null) {
            $translation->title = $plan->title;
        }
        if ($plan->slug !== null) {
            $translation->slug = $plan->slug;
        }
        $translation->setFieldValues($plan->fieldValues);

        if (!Craft::$app->getElements()->saveElement($translation)) {
            return "Craft couldn't save the entry's content for the site \"$siteName\": " . implode(' ', $translation->getFirstErrors());
        }

        return null;
    }

    /**
     * Rolls back a Root element's transaction, and forgets the IDs of the Stubs and assets it created so that a later
     * Root element relating to the same Reference creates them again. The files of the assets it fetched are deleted.
     *
     * @param ElementInterface[] $created
     */
    private function rollBack(Transaction $transaction, array $created): void
    {
        $transaction->rollBack();
        foreach ($created as $element) {
            if ($element instanceof Asset) {
                $this->deleteFile($element);
            }
            $element->id = null;
        }
    }

    private function deleteFile(Asset $asset): void
    {
        try {
            $asset->getVolume()->deleteFile($asset->getPath());
        } catch (Throwable $e) {
            Craft::$app->getErrorHandler()->logException($e);
        }
        $asset->tempFilePath = null;
        $asset->newFolderId = null;
    }
}
