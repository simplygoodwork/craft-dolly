<?php

namespace simplygoodwork\craftportage\controllers;

use craft\web\Controller;
use simplygoodwork\craftportage\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Import log's detail view. The list is part of the Portage utility.
 */
class LogController extends Controller
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
     * @param int $importId the Import log entry (not `$id`, which Craft sets to the plugin handle on plugin routes)
     */
    public function actionView(int $importId): Response
    {
        $import = Plugin::getInstance()->getImportLog()->getEntryById($importId)
            ?? throw new NotFoundHttpException('No Import with that ID is in the Import log.');

        return $this->renderTemplate('portage/_log/view', ['import' => $import]);
    }
}
