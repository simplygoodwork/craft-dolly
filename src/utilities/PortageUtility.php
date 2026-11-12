<?php

namespace simplygoodwork\craftportage\utilities;

use Craft;
use craft\base\Utility;
use simplygoodwork\craftportage\Plugin;

/**
 * The Portage utility: paste or upload a Payload to Preview and Import it, and browse the Import log.
 */
class PortageUtility extends Utility
{
    public static function displayName(): string
    {
        return 'Portage';
    }

    public static function id(): string
    {
        return 'portage';
    }

    public static function icon(): ?string
    {
        return 'truck';
    }

    public static function contentHtml(): string
    {
        $canImport = Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_IMPORT);

        return Craft::$app->getView()->renderTemplate('portage/_utility', [
            'canImport' => $canImport,
            'imports' => $canImport ? Plugin::getInstance()->getImportLog()->getEntries() : [],
        ]);
    }
}
