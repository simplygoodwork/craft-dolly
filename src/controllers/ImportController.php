<?php

namespace simplygoodwork\craftportage\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use simplygoodwork\craftportage\import\ImportPlan;
use simplygoodwork\craftportage\Plugin;
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

        $this->requirePermission(Plugin::PERMISSION_IMPORT);

        return true;
    }

    /**
     * Previews pasted or uploaded Payload text.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();

        $payload = $this->payloadText();
        $plan = $payload !== null
            ? Plugin::getInstance()->getPortage()->preview($payload)
            : ImportPlan::refused(Craft::t('portage', 'The .portage file couldn’t be uploaded. Try again, or paste its text instead.'));

        return $this->renderTemplate('portage/_preview', [
            'plan' => $plan,
            'payload' => $payload,
            'driftKinds' => $this->driftKinds(),
        ]);
    }

    /**
     * Imports the previewed Payload with the default choices, then shows its result in the Import log. A refused
     * Payload shows the refusal instead.
     */
    public function actionImport(): Response
    {
        $this->requirePostRequest();

        $payload = trim((string)Craft::$app->getRequest()->getRequiredBodyParam('payload'));
        $result = Plugin::getInstance()->getPortage()->import($payload);

        if ($result->isRefused()) {
            return $this->renderTemplate('portage/_preview', [
                'plan' => ImportPlan::refused((string)$result->refusal),
                'payload' => $payload,
                'driftKinds' => $this->driftKinds(),
            ]);
        }

        if ($result->hasFailures()) {
            $this->setFailFlash(Craft::t('portage', 'Some entries couldn’t be imported.'));
        } else {
            $this->setSuccessFlash(Craft::t('portage', 'Imported.'));
        }

        return $this->redirect(UrlHelper::cpUrl("portage/log/$result->logId"));
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
