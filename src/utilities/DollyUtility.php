<?php

namespace simplygoodwork\craftdolly\utilities;

use Craft;
use craft\base\Utility;
use simplygoodwork\craftdolly\Plugin;

/**
 * The Dolly utility: paste or upload a Payload to Preview and Import it, and browse the Import log.
 */
class DollyUtility extends Utility
{
    public static function displayName(): string
    {
        return 'Dolly';
    }

    public static function id(): string
    {
        return 'dolly';
    }

    public static function icon(): ?string
    {
        return 'sheep';
    }

    public static function contentHtml(): string
    {
        $canImport = Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_IMPORT);

        return Craft::$app->getView()->renderTemplate('dolly/_utility', [
            'canImport' => $canImport,
            'imports' => $canImport ? Plugin::getInstance()->getImportLog()->getEntries() : [],
        ]);
    }
}
