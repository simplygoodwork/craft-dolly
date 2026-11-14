<?php

namespace simplygoodwork\craftportage\controllers;

use benf\neo\elements\Block;
use Craft;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use craft\models\Site;
use craft\web\Controller;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\BlockTarget;
use simplygoodwork\craftportage\Plugin;
use yii\base\InvalidConfigException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The control panel's "Copy for another environment" actions. All behaviour is in the core service; this only finds the
 * entries, checks the user may see them and returns the Payload for the browser to copy or download.
 */
class ExportController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_EXPORT);

        return true;
    }

    /**
     * Exports the entry being edited as it is on screen: the draft (provisional or named) when the editor is on one,
     * otherwise the entry. The editor saves unsaved changes to the draft before it asks.
     */
    public function actionEntry(): Response
    {
        $request = Craft::$app->getRequest();
        $id = (int)$request->getRequiredBodyParam('elementId');
        $siteId = (int)$request->getRequiredBodyParam('siteId');

        /** @var Entry|null $entry */
        $entry = Entry::find()
            ->id($id)
            ->siteId($siteId)
            ->drafts(null)
            ->provisionalDrafts(null)
            ->status(null)
            ->one();

        if ($entry === null) {
            return $this->notFound();
        }
        // Craft never shows anyone another user's autosave, so neither does an Export.
        // `creatorId` comes from Craft's draft behavior.
        if ($entry->isProvisionalDraft && (int)$entry->creatorId !== (int)Craft::$app->getUser()->getId()) { // @phpstan-ignore property.notFound
            throw new ForbiddenHttpException('User is not authorized to export this entry.');
        }

        return $this->payloadResponse([$entry]);
    }

    /**
     * Exports the selected entries from the entry index, in the order selected. They're the canonical entries: the
     * index's drafts and revisions aren't exported.
     */
    public function actionEntries(): Response
    {
        $request = Craft::$app->getRequest();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$request->getRequiredBodyParam('ids')))));
        $siteId = (int)$request->getRequiredBodyParam('siteId');

        $found = Entry::find()->id($ids)->siteId($siteId)->status(null)->fixedOrder()->all();

        return count($found) === count($ids) && $found ? $this->payloadResponse($found) : $this->notFound();
    }

    /**
     * Exports the blocks selected in a Matrix or Neo field of the entry being edited, as they are on screen. The editor
     * saves unsaved changes to the draft first, so the blocks are found in the draft's field by their position in it,
     * which is the order they're shown in.
     */
    public function actionBlocks(): Response
    {
        $request = Craft::$app->getRequest();
        $ownerId = (int)$request->getRequiredBodyParam('elementId');
        $siteId = (int)$request->getRequiredBodyParam('siteId');
        $handle = (string)$request->getRequiredBodyParam('field');
        // Each selected block's position in the field, with the ID the page shows it with (0 for a block the page hasn't
        // got an ID for yet), to check that the page and the draft agree.
        $positions = array_map('intval', (array)$request->getRequiredBodyParam('positions'));
        $ids = array_map('intval', (array)$request->getBodyParam('ids', []));
        $pairs = [];
        foreach ($positions as $i => $position) {
            if ($position >= 0) {
                $pairs[$position] = $ids[$i] ?? 0;
            }
        }
        ksort($pairs);
        $positions = array_keys($pairs);

        /** @var Entry|null $owner */
        $owner = Entry::find()->id($ownerId)->siteId($siteId)->drafts(null)->provisionalDrafts(null)->status(null)->one();
        if ($owner === null || !$positions) {
            return $this->notFound();
        }

        if ($owner->isProvisionalDraft && (int)$owner->creatorId !== (int)Craft::$app->getUser()->getId()) { // @phpstan-ignore property.notFound
            throw new ForbiddenHttpException('User is not authorized to export this entry.');
        }
        if ($owner->getSection() === null || !Craft::$app->getElements()->canView($owner)) {
            throw new ForbiddenHttpException('User is not authorized to export this entry.');
        }

        $field = $owner->getFieldLayout()?->getFieldByHandle($handle);
        if (!BlockTarget::supports($field)) {
            return $this->asFailure(Craft::t('portage', 'Only blocks in a Matrix or Neo field can be copied.'));
        }

        $value = $owner->getFieldValue($handle);
        $all = $value instanceof ElementQueryInterface ? array_values((clone $value)->status(null)->all()) : [];
        $selected = array_values(array_filter(array_map(fn(int $position) => $all[$position] ?? null, $positions)));
        if (count($selected) !== count($positions)) {
            return $this->notFound();
        }
        foreach ($selected as $block) {
            $id = $pairs[array_search($block, $all, true)];
            if ($id !== 0 && $id !== (int)$block->id && $id !== (int)$block->getCanonicalId()) {
                return $this->asFailure(Craft::t('portage', 'The blocks changed since the page loaded. Reload the page and try again.'));
            }
        }

        return $this->payloadResponse($selected, $owner);
    }

    private function notFound(): Response
    {
        return $this->asFailure(Craft::t('portage', 'Some of the entries couldn’t be found. Reload the page and try again.'));
    }

    /**
     * @param non-empty-list<Entry|Block> $roots whole entries, or the blocks of one field of the entry the user may view
     * (`$owner`), which are checked before this
     */
    private function payloadResponse(array $roots, ?Entry $owner = null): Response
    {
        $entries = $roots;
        $elements = Craft::$app->getElements();
        if ($owner === null) {
            foreach ($entries as $entry) {
                if (!$entry instanceof Entry || $entry->getSection() === null || !$elements->canView($entry)) {
                    throw new ForbiddenHttpException('User is not authorized to export this entry.');
                }
            }
        }

        try {
            $payload = Plugin::getInstance()->getPortage()->export($roots, $this->editableSiteIds());
        } catch (InvalidConfigException $e) {
            return $this->asFailure($e->getMessage());
        }

        $asFile = strlen($payload) > Plugin::getInstance()->getSettings()->clipboardLimit;

        return $this->asSuccess(data: [
            'payload' => $payload,
            'count' => $owner !== null ? $this->blockCount($roots) : count($entries),
            'kind' => $owner !== null ? 'blocks' : 'entries',
            'asFile' => $asFile,
            'filename' => $this->filename($entries),
        ]);
    }

    /**
     * The sites the user may edit, which is as much of an entry as they may export: the Payload holds the entry in every
     * site, and Craft's permission check for viewing an entry only looks at the site it's viewed in.
     *
     * @return list<int>
     */
    private function editableSiteIds(): array
    {
        $user = Craft::$app->getUser();

        return array_values(array_map(
            fn(Site $site) => (int)$site->id,
            array_filter(
                Craft::$app->getSites()->getAllSites(true),
                fn(Site $site) => !Craft::$app->getIsMultiSite() || $user->checkPermission("editSite:$site->uid"),
            ),
        ));
    }

    /**
     * Counts the blocks a Payload carries: the Root elements, and the blocks nested under a Neo block, which it carries.
     *
     * @param non-empty-list<Entry|Block> $roots
     */
    private function blockCount(array $roots): int
    {
        $ids = [];
        foreach ($roots as $root) {
            $ids[$root->id] = true;
            foreach ($root instanceof Block ? ExportContext::childBlocks($root) : [] as $child) {
                $ids[$child->id] = true;
            }
        }

        return count($ids);
    }

    /**
     * @param non-empty-list<Entry|Block> $roots
     */
    private function filename(array $roots): string
    {
        if (ExportContext::isBlock($roots[0])) {
            return 'blocks.portage';
        }
        $name = count($roots) === 1 ? StringHelper::slugify((string)($roots[0]->slug ?: $roots[0]->title)) : '';

        return ($name !== '' ? $name : 'entries') . '.portage';
    }
}
