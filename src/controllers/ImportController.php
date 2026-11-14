<?php

namespace simplygoodwork\craftportage\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use simplygoodwork\craftportage\import\ImportPlan;
use simplygoodwork\craftportage\import\ReferencePlan;
use simplygoodwork\craftportage\Plugin;
use yii\web\Response;

/**
 * The Portage utility's Preview and Import. All behaviour is in the core service; this only reads the request and
 * renders the outcome.
 */
class ImportController extends Controller
{
    use RendersPreview;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_IMPORT);

        return true;
    }

    /**
     * Previews pasted or uploaded Payload text with the choices made so far. Every change to a choice posts here
     * again, so the Preview always shows what the Import will do.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();

        $payload = $this->payloadText();
        if ($payload === null) {
            return $this->renderPreview(ImportPlan::refused(Craft::t('portage', 'The .portage file couldn’t be uploaded. Try again, or paste its text instead.')), '');
        }

        return $this->previewWithChoices($payload, $this->readChoices());
    }

    /**
     * Imports the previewed Payload with the choices made in the Preview, then shows its result in the Import log. An Import of more than 10 Root elements, or with an asset fetch, is queued instead, and its Import log entry shows its progress. A
     * refused Payload, a choice that can't be carried out, or choices that aren't the ones the Preview last showed
     * import nothing, and show the Preview again.
     */
    public function actionImport(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $payload = trim((string)$request->getRequiredBodyParam('payload'));
        $form = $this->readChoices();
        $portage = Plugin::getInstance()->getPortage();

        $plan = $portage->preview($payload, $form['choices'], $form['rootChoices']);
        if ($plan->isRefused()) {
            return $this->previewWithChoices($payload, $form);
        }
        if (!hash_equals($plan->fingerprint(), (string)$request->getBodyParam('previewed'))) {
            return $this->previewWithChoices($payload, $form, Craft::t('portage', 'Your choices changed since the Preview. Check it, then import.'));
        }

        // A large Import runs in the queue, with exactly these choices. Its job discards the uploaded files when it's done.
        $result = $portage->import($payload, $form['choices'], $form['rootChoices'], queue: true);
        if ($result->isRefused()) {
            return $this->previewWithChoices($payload, $form, $result->refusal);
        }

        if ($result->queued) {
            $this->setSuccessFlash(Craft::t('portage', 'The Import is queued. This page shows its progress.'));

            return $this->redirect(UrlHelper::cpUrl("portage/log/$result->logId"));
        }

        $this->stagedUploads()->discard($form['staged']);

        if ($result->hasFailures()) {
            $this->setFailFlash(Craft::t('portage', 'Some entries couldn’t be imported.'));
        } else {
            $this->setSuccessFlash(Craft::t('portage', 'Imported.'));
        }

        return $this->redirect(UrlHelper::cpUrl("portage/log/$result->logId"));
    }

    /**
     * Renders the Preview of a Payload with the choices in the form. A choice that can't be carried out is dropped
     * and explained, and the rest are kept. If the Payload is refused whatever is chosen, the Preview shows the
     * defaults.
     *
     * @param array{choices: array<string, mixed>, rootChoices: array<string, array<string, string>>, requested: array<string, array<string, mixed>>, staged: list<string>} $form
     * @param string|null $notice something to tell the user before any problem with their choices
     */
    private function previewWithChoices(string $payload, array $form, ?string $notice = null): Response
    {
        [$plan, $form, $error] = $this->planWithChoices($payload, $form, $notice);

        return $this->renderPreview($plan, $payload, $form, $error);
    }

    /**
     * @param array{requested?: array<string, array<string, mixed>>, rootChoices?: array<string, array<string, string>>} $form
     */
    private function renderPreview(ImportPlan $plan, string $payload, array $form = [], ?string $choiceError = null): Response
    {
        return $this->renderTemplate('portage/_preview', [
            'plan' => $plan,
            'payload' => $payload,
            'driftKinds' => $this->driftKinds(),
            'requested' => $form['requested'] ?? [],
            'requestedRoots' => $form['rootChoices'] ?? [],
            'choiceError' => $choiceError,
            'choiceLabels' => ReferencePlan::choiceLabels(),
            'previewed' => $plan->isRefused() ? '' : $plan->fingerprint(),
            'pickers' => $this->pickers($plan),
            'volumes' => $this->volumes(),
        ]);
    }
}
