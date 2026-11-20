<?php

namespace simplygoodwork\craftdolly;

use Craft;
use craft\base\Element;
use craft\base\Field;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\DefineMenuItemsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\web\UrlManager;
use craft\web\View;
use simplygoodwork\craftdolly\elements\CopyForAnotherEnvironment;
use simplygoodwork\craftdolly\import\BlockTarget;
use simplygoodwork\craftdolly\models\Settings;
use simplygoodwork\craftdolly\services\Dolly;
use simplygoodwork\craftdolly\services\ImportLog;
use simplygoodwork\craftdolly\utilities\DollyUtility;
use simplygoodwork\craftdolly\web\assets\export\ExportAsset;
use simplygoodwork\craftdolly\web\assets\paste\PasteAsset;
use yii\base\Event;

/**
 * Dolly plugin
 *
 * @method static Plugin getInstance()
 * @property-read Dolly $dolly
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
    public const PERMISSION_EXPORT = 'dolly-exportContent';

    /**
     * Lets a user Import content, on top of Craft's save permissions for everything the Import writes to.
     */
    public const PERMISSION_IMPORT = 'dolly-importContent';

    public string $schemaVersion = '1.0.2';

    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'dolly' => Dolly::class,
                'importLog' => ImportLog::class,
            ],
        ];
    }

    /**
     * The core service: Export and Import of Payloads.
     */
    public function getDolly(): Dolly
    {
        return $this->get('dolly');
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

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('dolly/_settings.twig', [
            'settings' => $this->getSettings(),
        ]);
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
                'heading' => 'Dolly',
                'permissions' => [
                    self::PERMISSION_EXPORT => ['label' => Craft::t('dolly', 'Export content')],
                    self::PERMISSION_IMPORT => ['label' => Craft::t('dolly', 'Import content')],
                ],
            ];
        });

        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = DollyUtility::class;
        });

        Event::on(Entry::class, Element::EVENT_REGISTER_ACTIONS, function(RegisterElementActionsEvent $event) {
            // Not for the indexes of nested entries (a Matrix field's), which can't be Root elements.
            if (!str_starts_with((string)$event->source, 'field:') && Craft::$app->getUser()->checkPermission(self::PERMISSION_EXPORT)) {
                $event->actions[] = CopyForAnotherEnvironment::class;
            }
        });

        Event::on(Entry::class, Element::EVENT_DEFINE_ACTION_MENU_ITEMS, function(DefineMenuItemsEvent $event) {
            $this->addEditorMenuItem($event);
        });

        // Fields have action menus from Craft 5.7. Below that, Matrix and Neo blocks can't be copied or pasted.
        if (defined(Field::class . '::EVENT_DEFINE_ACTION_MENU_ITEMS')) {
            Event::on(Field::class, constant(Field::class . '::EVENT_DEFINE_ACTION_MENU_ITEMS'), function(DefineMenuItemsEvent $event) {
                $this->addFieldMenuItems($event);
            });
        }

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['dolly/log/<importId:\\d+>'] = 'dolly/log/view';
        });
    }

    /**
     * Adds "Copy for another environment" to an entry editor's action menu, for a user who can Export content. It
     * exports what the editor is showing, so it isn't offered on revisions, on nested entries (only a whole entry is a
     * Root element) or before the entry exists.
     */
    private function addEditorMenuItem(DefineMenuItemsEvent $event): void
    {
        /** @var Entry $entry */
        $entry = $event->sender;
        if (
            $entry->id === null ||
            $entry->getIsRevision() ||
            $entry->getSection() === null ||
            !Craft::$app->getUser()->checkPermission(self::PERMISSION_EXPORT) ||
            !Craft::$app->getElements()->canView($entry)
        ) {
            return;
        }

        $view = Craft::$app->getView();
        $id = sprintf('action-dolly-export-%s', mt_rand());
        $item = [
            'id' => $id,
            'icon' => 'clone',
            'label' => Craft::t('dolly', 'Copy for another environment'),
        ];

        // Before the destructive items, which Craft groups at the end.
        $position = count($event->items);
        foreach ($event->items as $i => $existing) {
            if (!empty($existing['destructive'])) {
                $position = $i;
                break;
            }
        }
        array_splice($event->items, $position, 0, [$item]);

        $view->registerAssetBundle(ExportAsset::class);
        $view->registerJsWithVars(fn($id) => <<<JS
(() => {
  const btn = $('#' + $id);
  btn.on('activate', () => {
    const editor = btn.closest('.menu').data('disclosureMenu').\$trigger.closest('form').data('elementEditor');
    Dolly.exportEditedEntry(editor);
  });
})();
JS, [$view->namespaceInputId($id)]);
    }

    /**
     * Adds "Copy selected for another environment" and "Paste from another environment" to the action menu of a Matrix or
     * Neo field in an entry editor, each for a user who has its permission. Copying exports the blocks selected in the
     * field; pasting previews a Payload of blocks and adds them to the user's draft.
     */
    private function addFieldMenuItems(DefineMenuItemsEvent $event): void
    {
        $field = $event->sender;
        if (!$field instanceof Field || $field->static || !BlockTarget::supports($field)) {
            return;
        }

        $user = Craft::$app->getUser();
        $view = Craft::$app->getView();
        $items = [];

        foreach ([
            [self::PERMISSION_EXPORT, 'copy', 'clone', 'Copy selected for another environment', 'copySelectedBlocks'],
            [self::PERMISSION_IMPORT, 'paste', 'paste', 'Paste from another environment', 'pasteBlocks'],
        ] as [$permission, $kind, $icon, $label, $method]) {
            if (!$user->checkPermission($permission)) {
                continue;
            }

            $id = sprintf('action-dolly-%s-blocks-%s', $kind, mt_rand());
            $items[] = [
                'id' => $id,
                'icon' => $icon,
                'label' => Craft::t('dolly', $label),
            ];
            $view->registerJsWithVars(fn($id, $method) => <<<JS
(() => {
  const btn = $('#' + $id);
  btn.on('activate', () => {
    const field = btn.closest('.menu').data('disclosureMenu').\$trigger.closest('.field');
    Dolly[$method](field);
  });
})();
JS, [$view->namespaceInputId($id), $method]);
        }

        if (!$items) {
            return;
        }

        $view->registerAssetBundle(PasteAsset::class);
        if ($user->checkPermission(self::PERMISSION_EXPORT)) {
            // The block menus are Craft's, so the script adds its item to them when the user may export.
            $view->registerJs('window.dollyCanExport = true;', View::POS_BEGIN);
        }
        array_unshift($event->items, ...$items, ...($event->items ? [['type' => 'hr']] : []));
    }
}
