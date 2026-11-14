<?php

namespace simplygoodwork\craftportage\controllers;

use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use craft\web\View;
use simplygoodwork\craftportage\import\BlockTarget;
use simplygoodwork\craftportage\import\ImportPlan;
use simplygoodwork\craftportage\import\ReferencePlan;
use simplygoodwork\craftportage\import\RootResult;
use simplygoodwork\craftportage\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The control panel's "Paste from another environment" in a Matrix or Neo field. All behaviour is in the core service;
 * this finds the draft and the field the blocks go into, and renders the Preview in the modal.
 */
class PasteController extends Controller
{
    use RendersPreview;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_IMPORT);

        return true;
    }

    /**
     * Previews pasted or uploaded Payload text for the field, with the choices made so far. Every change to a choice
     * posts here again, so the Preview always shows what the paste will do.
     */
    public function actionPreview(): Response
    {
        $target = $this->blockTarget();
        $payload = $this->payloadText();
        if ($payload === null) {
            return $this->previewResponse(ImportPlan::refused(Craft::t('portage', 'The .portage file couldn’t be uploaded. Try again, or paste its text instead.')), '', $target);
        }

        [$plan, $form, $error] = $this->planWithChoices($payload, $this->readChoices(), null, $target);

        return $this->previewResponse($plan, $payload, $target, $form, $error);
    }

    /**
     * Adds the previewed blocks to the draft's field with the choices made in the Preview. A refused Payload, a choice
     * that can't be carried out, or choices that aren't the ones the Preview last showed add nothing.
     */
    public function actionPaste(): Response
    {
        $request = Craft::$app->getRequest();
        $target = $this->blockTarget();
        $payload = trim((string)$request->getRequiredBodyParam('payload'));
        $form = $this->readChoices();
        $portage = Plugin::getInstance()->getPortage();

        $plan = $portage->preview($payload, $form['choices'], $form['rootChoices'], $target);
        if ($plan->isRefused()) {
            return $this->asFailure((string)$plan->refusal);
        }
        if (!hash_equals($plan->fingerprint(), (string)$request->getBodyParam('previewed'))) {
            return $this->asFailure(Craft::t('portage', 'Your choices changed since the Preview. Check it, then paste.'));
        }

        $result = $portage->import($payload, $form['choices'], $form['rootChoices'], $target);
        if ($result->isRefused()) {
            return $this->asFailure((string)$result->refusal);
        }

        $this->stagedUploads()->discard($form['staged']);

        $pasted = array_sum(array_map(fn(RootResult $root) => $root->status === RootResult::STATUS_PASTED ? $root->blocks : 0, $result->roots));
        $problems = array_merge(...array_map(fn(RootResult $root) => $root->errors, $result->roots));
        if ($pasted === 0) {
            return $this->asFailure(Craft::t('portage', 'No blocks were pasted into {field}: {reasons}', [
                'field' => $target->label(),
                'reasons' => implode(' ', $problems),
            ]));
        }

        $message = $pasted === 1
            ? Craft::t('portage', 'Pasted 1 block into {field}.', ['field' => $target->label()])
            : Craft::t('portage', 'Pasted {count} blocks into {field}.', ['count' => $pasted, 'field' => $target->label()]);
        if ($problems) {
            $message .= ' ' . Craft::t('portage', 'Some blocks couldn’t be pasted: {reasons}', ['reasons' => implode(' ', $problems)]);
        }

        // The editor loads the draft again to show the blocks, and the message with it.
        $this->setSuccessFlash($message);

        return $this->asSuccess($message, ['pasted' => $pasted, 'logId' => $result->logId]);
    }

    /**
     * Finds the draft being edited and the Matrix or Neo field the blocks are pasted into: the user's own draft, in a
     * field of its field layout. Whether it is a draft, and whether the user may edit it, is for the core service to
     * say.
     *
     * @throws ForbiddenHttpException if the draft is another user's provisional draft
     * @throws \yii\web\BadRequestHttpException if the entry or the field can't be found
     */
    private function blockTarget(): BlockTarget
    {
        $request = Craft::$app->getRequest();
        $ownerId = (int)$request->getRequiredBodyParam('elementId');
        $siteId = (int)$request->getRequiredBodyParam('siteId');
        $handle = (string)$request->getRequiredBodyParam('field');

        /** @var Entry|null $owner */
        $owner = Entry::find()->id($ownerId)->siteId($siteId)->drafts(null)->provisionalDrafts(null)->status(null)->one();
        if ($owner === null) {
            throw new \yii\web\BadRequestHttpException('The entry couldn’t be found. Reload the page and try again.');
        }
        if ($owner->isProvisionalDraft && (int)$owner->creatorId !== (int)Craft::$app->getUser()->getId()) { // @phpstan-ignore property.notFound
            throw new ForbiddenHttpException('User is not authorized to paste into this entry.');
        }

        $field = $owner->getFieldLayout()?->getFieldByHandle($handle);
        if (!BlockTarget::supports($field)) {
            throw new \yii\web\BadRequestHttpException('Blocks can only be pasted into a Matrix or Neo field.');
        }

        return new BlockTarget($owner, $field);
    }

    /**
     * Returns the Preview as HTML for the modal, with the head and body HTML its template registered.
     *
     * @param array{requested?: array<string, array<string, mixed>>, rootChoices?: array<string, array<string, string>>} $form
     */
    private function previewResponse(ImportPlan $plan, string $payload, BlockTarget $target, array $form = [], ?string $choiceError = null): Response
    {
        $view = Craft::$app->getView();
        $html = $view->renderTemplate('portage/_paste', [
            'plan' => $plan,
            'payload' => $payload,
            'target' => $target,
            'driftKinds' => $this->driftKinds(),
            'requested' => $form['requested'] ?? [],
            'choiceError' => $choiceError,
            'choiceLabels' => ReferencePlan::choiceLabels(),
            'previewed' => $plan->isRefused() ? '' : $plan->fingerprint(),
            'pickers' => $this->pickers($plan),
            'volumes' => $this->volumes(),
        ], View::TEMPLATE_MODE_CP);

        return $this->asJson([
            'html' => $html,
            'headHtml' => $view->getHeadHtml(),
            'bodyHtml' => $view->getBodyHtml(),
        ]);
    }
}
