<?php

namespace simplygoodwork\craftdolly\web\assets\export;

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
        $this->js = ['dolly-export.js'];

        parent::init();
    }

    /**
     * @param View $view
     */
    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        $view->registerTranslations('dolly', [
            'Copied for another environment: 1 entry.',
            'Copied for another environment: {count} entries.',
            'Copied for another environment: 1 block.',
            'Copied for another environment: {count} blocks.',
            'The Payload is large. Download it as {filename}, then upload it in the Dolly utility.',
            'The Payload couldn’t be copied. Download it as {filename}, then upload it in the Dolly utility.',
            'The Payload is large. Download it as {filename}, then upload it with “Paste from another environment” in the field’s menu.',
            'The Payload couldn’t be copied. Download it as {filename}, then upload it with “Paste from another environment” in the field’s menu.',
            'Download {filename}',
            'Couldn’t copy for another environment.',
        ]);
    }
}
