<?php

namespace simplygoodwork\craftportage\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\models\Volume;
use craft\web\Controller;
use craft\web\UploadedFile;
use simplygoodwork\craftportage\import\ImportPlan;
use simplygoodwork\craftportage\import\ReferencePlan;
use simplygoodwork\craftportage\import\StagedUploads;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\references\ElementTypes;
use simplygoodwork\craftportage\schema\SchemaManifest;
use yii\web\Response;

/**
 * The Portage utility's Preview and Import. All behaviour is in the core service; this only reads the request and
 * renders the outcome.
 */
class ImportController extends Controller
{
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
     * Imports the previewed Payload with the choices made in the Preview, then shows its result in the Import log. A
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

        $result = $portage->import($payload, $form['choices'], $form['rootChoices']);
        if ($result->isRefused()) {
            return $this->previewWithChoices($payload, $form);
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
        $portage = Plugin::getInstance()->getPortage();
        $errors = $notice !== null ? [$notice] : [];
        $plan = $portage->preview($payload, $form['choices'], $form['rootChoices']);

        while ($plan->isRefused() && $plan->refusalUid !== null && (isset($form['choices'][$plan->refusalUid]) || isset($form['rootChoices'][$plan->refusalUid]))) {
            $errors[] = (string)$plan->refusal;
            unset($form['choices'][$plan->refusalUid], $form['rootChoices'][$plan->refusalUid], $form['requested'][$plan->refusalUid]);
            $plan = $portage->preview($payload, $form['choices'], $form['rootChoices']);
        }

        if ($plan->isRefused() && ($form['choices'] || $form['rootChoices'])) {
            // The Payload itself may be fine: show it with the defaults, and the problem with the choices.
            $defaults = $portage->preview($payload);
            if (!$defaults->isRefused()) {
                $errors[] = (string)$plan->refusal;
                $plan = $defaults;
                $form = ['choices' => [], 'rootChoices' => [], 'requested' => [], 'staged' => $form['staged']];
            }
        }

        return $this->renderPreview($plan, $payload, $form, $errors ? implode(' ', $errors) : null);
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

    /**
     * Reads the choices the Preview's form posted, as the core service takes them. The planner decides what to do with
     * a choice that isn't complete yet.
     *
     * @return array{
     *     choices: array<string, string|array<string, mixed>>,
     *     rootChoices: array<string, array{mode?: string, section?: string}>,
     *     requested: array<string, array<string, mixed>>,
     *     staged: list<string>,
     * } `requested` is what the form asked for, to render it again; `staged` the paths of the uploaded files kept for it
     */
    private function readChoices(): array
    {
        $request = Craft::$app->getRequest();
        $posted = $request->getBodyParam('choices');
        $uploads = $this->stagedUploads();
        $choices = [];
        $requested = [];
        $staged = [];

        foreach (is_array($posted) ? $posted : [] as $uid => $input) {
            if (!is_string($uid) || !is_array($input)) {
                continue;
            }

            $choice = $this->nonEmptyString($input, 'choice') ?? ReferencePlan::CHOICE_SKIP;
            $replacement = $input['replacement'] ?? null;
            $replacementId = (int)(is_array($replacement) ? reset($replacement) : $replacement) ?: null;
            $volume = $this->nonEmptyString($input, 'volume');
            // Without a chosen volume the asset goes to its own volume and folder.
            $location = $volume !== null ? ['volume' => $volume, 'folder' => trim((string)($input['folder'] ?? ''), '/ ')] : [];

            $file = UploadedFile::getInstanceByName("uploads[$uid]", false);
            $upload = $choice === ReferencePlan::CHOICE_UPLOAD
                ? ($file ? $uploads->stage($file) : null) ?? $uploads->find($this->nonEmptyString($input, 'staged'))
                : null;
            if ($upload) {
                $staged[] = $upload['path'];
            }

            $requested[$uid] = [
                'choice' => $choice,
                'volume' => $location['volume'] ?? '',
                'folder' => $location['folder'] ?? '',
                'staged' => $upload['token'] ?? '',
                'stagedName' => $upload['filename'] ?? '',
                'replacementId' => $replacementId,
            ];
            $choices[$uid] = match ($choice) {
                ReferencePlan::CHOICE_STUB => ReferencePlan::CHOICE_STUB,
                ReferencePlan::CHOICE_REPLACE => ['choice' => ReferencePlan::CHOICE_REPLACE, 'element' => $replacementId],
                ReferencePlan::CHOICE_FETCH => ['choice' => ReferencePlan::CHOICE_FETCH] + $location,
                ReferencePlan::CHOICE_UPLOAD => ['choice' => ReferencePlan::CHOICE_UPLOAD, 'path' => $upload['path'] ?? null, 'filename' => $upload['filename'] ?? null] + $location,
                default => ReferencePlan::CHOICE_SKIP,
            };
        }

        $rootChoices = [];
        $postedRoots = $request->getBodyParam('rootChoices');
        foreach (is_array($postedRoots) ? $postedRoots : [] as $uid => $input) {
            if (!is_string($uid) || !is_array($input)) {
                continue;
            }
            $rootChoice = array_filter([
                'mode' => $this->nonEmptyString($input, 'mode'),
                'section' => $this->nonEmptyString($input, 'section'),
            ]);
            if ($rootChoice) {
                $rootChoices[$uid] = $rootChoice;
            }
        }

        return ['choices' => $choices, 'rootChoices' => $rootChoices, 'requested' => $requested, 'staged' => $staged];
    }

    /**
     * @param array<mixed> $input
     */
    private function nonEmptyString(array $input, string $key): ?string
    {
        return is_string($input[$key] ?? null) && $input[$key] !== '' ? $input[$key] : null;
    }

    private function stagedUploads(): StagedUploads
    {
        return new StagedUploads((int)Craft::$app->getUser()->getId());
    }

    /**
     * What the Preview needs to render Craft's element selector for each unresolved reference's replacement: the
     * element type, and the source the replacement is limited to when the Reference's section, group or volume exists
     * in this environment.
     *
     * @return array<string, array{elementType: class-string, sources: list<string>|null}>
     */
    private function pickers(ImportPlan $plan): array
    {
        $pickers = [];
        foreach ($plan->references as $uid => $reference) {
            $class = ElementTypes::classFor($reference->elementType);
            if ($reference->resolution->isResolved() || $class === null) {
                continue;
            }

            $source = ElementTypes::sourceFor($reference->elementType, (array)($reference->reference['naturalKey'] ?? []));
            $pickers[$uid] = ['elementType' => $class, 'sources' => $source !== null ? [$source] : null];
        }

        return $pickers;
    }

    /**
     * The volumes the user can save assets in, to choose where a fetched or uploaded asset goes.
     *
     * @return list<Volume>
     */
    private function volumes(): array
    {
        $user = Craft::$app->getUser()->getIdentity();

        return array_values(array_filter(
            Craft::$app->getVolumes()->getAllVolumes(),
            fn(Volume $volume) => $user && $user->can("saveAssetInVolume:$volume->uid"),
        ));
    }

    /**
     * Returns the Preview's label for each kind of Schema reference, from the manifest that defines the kinds.
     *
     * @return array<string, string>
     */
    private function driftKinds(): array
    {
        return array_map(fn(array $kind) => Craft::t('portage', ucfirst($kind[1])), SchemaManifest::KINDS);
    }

    /**
     * Returns the uploaded `.portage` file's contents if a file was chosen, otherwise the pasted text. Returns null if
     * the chosen file couldn't be uploaded or read.
     */
    private function payloadText(): ?string
    {
        // Only PHP fills in an upload's temp path, so the extra is_uploaded_file() check isn't needed (and fails in
        // functional tests, which don't upload over HTTP).
        $file = UploadedFile::getInstanceByName('payloadFile', false);
        if ($file && (int)$file->error !== UPLOAD_ERR_NO_FILE) {
            $contents = !$file->getHasError() && $file->tempName !== '' ? file_get_contents($file->tempName) : false;

            return $contents !== false ? trim($contents) : null;
        }

        return trim((string)Craft::$app->getRequest()->getBodyParam('payload', ''));
    }
}
