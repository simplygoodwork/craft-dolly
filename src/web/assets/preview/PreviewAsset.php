<?php

namespace simplygoodwork\craftportage\web\assets\preview;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The browser side of the Preview's choices, shared by the utility's Preview and the Preview in the paste modal.
 */
class PreviewAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['portage-preview.js'];

        parent::init();
    }
}
