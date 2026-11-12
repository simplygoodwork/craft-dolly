<?php

namespace simplygoodwork\craftportage\models;

use craft\base\Model;

/**
 * Portage's plugin settings.
 */
class Settings extends Model
{
    /**
     * @var int how long, in seconds, an Import waits for an asset's file to download from its source URL. The file's
     * size is limited by Craft's `maxUploadFileSize`.
     */
    public int $fetchTimeout = 30;

    protected function defineRules(): array
    {
        return [
            [['fetchTimeout'], 'integer', 'min' => 1],
        ];
    }
}
