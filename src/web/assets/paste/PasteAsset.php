<?php

namespace simplygoodwork\craftportage\web\assets\paste;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;
use simplygoodwork\craftportage\web\assets\export\ExportAsset;
use simplygoodwork\craftportage\web\assets\preview\PreviewAsset;

/**
 * The browser side of copying blocks out of a Matrix or Neo field and pasting them into one, in the entry editor.
 */
class PasteAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class, ExportAsset::class, PreviewAsset::class];
        $this->js = ['portage-paste.js'];

        parent::init();
    }

    /**
     * @param View $view
     */
    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        $view->registerTranslations('portage', [
            'Copy and paste for another environment work on the blocks of an entry’s own fields.',
            'Select one or more blocks to copy first.',
            'Copy for another environment',
            'Paste from another environment',
            'Paste Payload text, or upload a .portage file. You will see a Preview before anything is added to your draft.',
            'Or upload a .portage file',
            'Preview',
            'Cancel',
            'Couldn’t preview the Payload.',
            'Couldn’t paste the blocks.',
        ]);
    }
}
