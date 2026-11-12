<?php

namespace simplygoodwork\craftportage;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\web\UrlManager;
use simplygoodwork\craftportage\models\Settings;
use simplygoodwork\craftportage\services\ImportLog;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\utilities\PortageUtility;
use yii\base\Event;

/**
 * Portage plugin
 *
 * @method static Plugin getInstance()
 * @property-read Portage $portage
 * @property-read ImportLog $importLog
 * @author Good Work <dev@simplygoodwork.com>
 * @copyright Good Work
 * @license https://craftcms.github.io/license/ Craft License
 */
class Plugin extends BasePlugin
{
    /**
     * Lets a user Export content.
     */
    public const PERMISSION_EXPORT = 'portage-exportContent';

    /**
     * Lets a user Import content, on top of Craft's save permissions for everything the Import writes to.
     */
    public const PERMISSION_IMPORT = 'portage-importContent';

    public string $schemaVersion = '1.0.1';

    public static function config(): array
    {
        return [
            'components' => [
                'portage' => Portage::class,
                'importLog' => ImportLog::class,
            ],
        ];
    }

    /**
     * The core service: Export and Import of Payloads.
     */
    public function getPortage(): Portage
    {
        return $this->get('portage');
    }

    /**
     * The Import log.
     */
    public function getImportLog(): ImportLog
    {
        return $this->get('importLog');
    }

    public function getSettings(): Settings
    {
        /** @var Settings $settings */
        $settings = parent::getSettings();

        return $settings;
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    public function init(): void
    {
        parent::init();

        $this->attachEventHandlers();
    }

    private function attachEventHandlers(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => 'Portage',
                'permissions' => [
                    self::PERMISSION_EXPORT => ['label' => Craft::t('portage', 'Export content')],
                    self::PERMISSION_IMPORT => ['label' => Craft::t('portage', 'Import content')],
                ],
            ];
        });

        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = PortageUtility::class;
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['portage/log/<importId:\\d+>'] = 'portage/log/view';
        });
    }
}
