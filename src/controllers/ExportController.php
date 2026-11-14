<?php

namespace simplygoodwork\craftportage\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use craft\models\Site;
use craft\web\Controller;
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

    private function notFound(): Response
    {
        return $this->asFailure(Craft::t('portage', 'Some of the entries couldn’t be found. Reload the page and try again.'));
    }

    /**
     * @param non-empty-list<Entry> $entries
     */
    private function payloadResponse(array $entries): Response
    {
        $elements = Craft::$app->getElements();
        foreach ($entries as $entry) {
            if ($entry->getSection() === null || !$elements->canView($entry)) {
                throw new ForbiddenHttpException('User is not authorized to export this entry.');
            }
        }

        try {
            $payload = Plugin::getInstance()->getPortage()->export($entries, $this->editableSiteIds());
        } catch (InvalidConfigException $e) {
            return $this->asFailure($e->getMessage());
        }

        $asFile = strlen($payload) > Plugin::getInstance()->getSettings()->clipboardLimit;

        return $this->asSuccess(data: [
            'payload' => $payload,
            'count' => count($entries),
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
     * @param non-empty-list<Entry> $entries
     */
    private function filename(array $entries): string
    {
        $name = count($entries) === 1 ? StringHelper::slugify((string)($entries[0]->slug ?: $entries[0]->title)) : '';

        return ($name !== '' ? $name : 'entries') . '.portage';
    }
}
