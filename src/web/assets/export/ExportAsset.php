<?php

namespace simplygoodwork\craftportage\web\assets\export;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

/**
 * The browser side of "Copy for another environment": asks for the Payload, then copies it or downloads it.
 */
class ExportAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['portage-export.js'];

        parent::init();
    }

    /**
     * @param View $view
     */
    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        $view->registerTranslations('portage', [
            'Copied for another environment: 1 entry.',
            'Copied for another environment: {count} entries.',
            'The Payload is large. Download it as {filename}, then upload it in the Portage utility.',
            'The Payload couldn’t be copied. Download it as {filename}, then upload it in the Portage utility.',
            'Download {filename}',
            'Couldn’t copy for another environment.',
        ]);
    }
}
