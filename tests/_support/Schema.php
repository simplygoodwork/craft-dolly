<?php

namespace simplygoodwork\craftportage\tests\support;

use benf\neo\elements\Block as NeoBlock;
use benf\neo\Field as NeoField;
use benf\neo\helpers\Memoize as NeoMemoize;
use benf\neo\models\BlockType as NeoBlockType;
use benf\neo\Plugin as Neo;
use benf\neo\services\BlockTypes;
use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\ckeditor\Field as CkEditorField;
use craft\db\Table;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use craft\events\RegisterComponentTypesEvent;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Link;
use craft\fields\linktypes\BaseLinkType;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\fs\Local;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\models\CategoryGroup;
use craft\models\CategoryGroup_SiteSettings;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use craft\models\TagGroup;
use craft\models\Volume;
use craft\services\Elements;
use ether\seo\Seo as EtherSeo;
use nystudio107\retour\Retour;
use nystudio107\retour\services\Redirects as RetourRedirects;
use nystudio107\seomatic\seoelements\SeoEntry;
use percipiolondon\colourswatches\ColourSwatches;
use presseddigital\linkit\fields\LinkitField;
use ReflectionProperty;
use RuntimeException;
use simplygoodwork\craftportage\events\RegisterElementTypesEvent;
use simplygoodwork\craftportage\import\BlockTarget;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\references\ElementTypeDefinition;
use simplygoodwork\craftportage\references\ElementTypeRegistry;
use simplygoodwork\craftportage\services\Portage;
use Solspace\Calendar\Calendar;
use Solspace\Calendar\Elements\Event as CalendarEvent;
use Solspace\Calendar\Models\CalendarModel;
use Solspace\Calendar\Models\CalendarSiteSettingsModel;
use Solspace\Calendar\Services\CalendarsService;
use verbb\formie\elements\Form as FormieForm;
use verbb\hyper\base\Link as HyperLink;
use verbb\hyper\fieldlayoutelements\UrlSuffixField;
use verbb\hyper\fields\HyperField;
use verbb\hyper\services\Links as HyperLinks;
use yii\base\Event;

/**
 * Builds schema (fields, entry types, sections) and entries inside tests, and changes the install between Export
 * and Import to simulate a different Target environment.
 *
 * Every method saves through Craft's public services and throws if Craft
 * rejects the save, so a broken fixture fails loudly at the line that built it.
 * Every method that changes existing schema also calls `refresh()`, so the Import sees the change.
 */
final class Schema
{
    /**
     * Saves a custom field of any type.
     *
     * @template T of FieldInterface
     * @param class-string<T> $class
     * @param array<string, mixed> $config
     * @return T
     */
    public static function field(string $class, string $handle, array $config = []): FieldInterface
    {
        $field = new $class(array_merge([
            'name' => self::nameFor($handle),
            'handle' => $handle,
        ], $config));

        if (!Craft::$app->getFields()->saveField($field)) {
            self::fail("field \"$handle\"", $field->getErrors());
        }

        return $field;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function plainText(string $handle, array $config = []): PlainText
    {
        return self::field(PlainText::class, $handle, $config);
    }

    /**
     * Saves a Link field.
     *
     * @param array<string, mixed> $config
     */
    public static function link(string $handle, array $config = []): Link
    {
        return self::field(Link::class, $handle, $config);
    }

    /**
     * Saves a Linkit field that enables the given link types, e.g. `[\presseddigital\linkit\models\Entry::class]`.
     *
     * @param list<class-string> $types
     * @param array<string, mixed> $config
     */
    public static function linkit(string $handle, array $types, array $config = []): LinkitField
    {
        return self::field(LinkitField::class, $handle, ['types' => self::linkitTypes($types)] + $config);
    }

    /**
     * Changes the link types a Linkit field enables.
     *
     * @param list<class-string> $types
     */
    public static function changeLinkitTypes(LinkitField $field, array $types): void
    {
        self::updateField($field->uid, ['types' => self::linkitTypes($types)]);
    }

    /**
     * @param list<class-string> $types
     * @return array<class-string, array{enabled: string}>
     */
    private static function linkitTypes(array $types): array
    {
        return array_fill_keys($types, ['enabled' => '1']);
    }

    /**
     * Saves a global set, an element type Portage doesn't support.
     */
    public static function globalSet(string $handle): GlobalSet
    {
        $set = new GlobalSet(['name' => self::nameFor($handle), 'handle' => $handle]);
        if (!Craft::$app->getGlobals()->saveSet($set)) {
            self::fail("global set \"$handle\"", $set->getErrors());
        }

        return $set;
    }

    /**
     * Runs a callback with an extra Link field type registered, as a plugin would.
     *
     * @param class-string<BaseLinkType> $class
     */
    public static function withLinkType(string $class, callable $callback): mixed
    {
        // Craft caches the registered types in a private static, so it's replaced for the callback and restored after.
        $property = new ReflectionProperty(Link::class, '_types');
        $original = Link::types();
        $property->setValue(null, [$class::id() => $class] + $original);
        try {
            return $callback();
        } finally {
            $property->setValue(null, $original);
        }
    }

    /**
     * Saves a Hyper field with the given link types, built with `hyperLinkType()`.
     *
     * @param list<array<string, mixed>> $linkTypes
     * @param array<string, mixed> $config
     */
    public static function hyper(string $handle, array $linkTypes, array $config = []): HyperField
    {
        $field = self::withConsoleFlagCleared(fn() => self::field(HyperField::class, $handle, array_merge([
            'linkTypes' => $linkTypes,
            'multipleLinks' => true,
            'defaultLinkType' => $linkTypes[0]['handle'] ?? null,
        ], $config)));
        self::refresh();

        $saved = Craft::$app->getFields()->getFieldByUid($field->uid);
        assert($saved instanceof HyperField);

        return $saved;
    }

    /**
     * Builds the config of a Hyper link type with the given custom fields, to pass to `hyper()`.
     *
     * @param class-string<HyperLink> $class
     * @param array<FieldInterface|CustomField> $fields
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function hyperLinkType(string $class, string $handle, array $fields = [], array $config = []): array
    {
        $layout = $class::getDefaultFieldLayout();
        // The URL suffix isn't in Hyper's default layout, which drops its value.
        $advanced = $layout->getTabs()[1];
        $advanced->setElements([...$advanced->getElements(), new UrlSuffixField()]);
        $tab = $layout->getTabs()[0];
        $tab->setElements([
            ...$tab->getElements(),
            ...array_map(fn(FieldInterface|CustomField $field) => $field instanceof CustomField ? $field : new CustomField($field), $fields),
        ]);

        return array_merge([
            'type' => $class,
            'handle' => $handle,
            'label' => self::nameFor($handle),
            'enabled' => true,
            'layoutUid' => StringHelper::UUID(),
            'layoutConfig' => $layout->getConfig(),
        ], $config);
    }

    /**
     * Runs a callback with the request no longer flagged as a console request. `HyperField::getSettings()` calls
     * `getParams()` when it is, which only Craft's console `Request` has, but the tests run in the console with a web
     * `Request`.
     */
    private static function withConsoleFlagCleared(callable $callback): mixed
    {
        $request = Craft::$app->getRequest();
        $was = $request->getIsConsoleRequest();
        $request->setIsConsoleRequest(false);
        try {
            return $callback();
        } finally {
            $request->setIsConsoleRequest($was);
        }
    }

    /**
     * Runs a callback as a control panel request, as a Preview or Import started from the control panel is. Element
     * queries of some plugins (Formie 4's forms) are limited to what the current user can view in the control panel.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function asControlPanelRequest(callable $callback): mixed
    {
        $request = Craft::$app->getRequest();
        $was = $request->getIsCpRequest();
        $request->setIsCpRequest(true);
        try {
            return $callback();
        } finally {
            $request->setIsCpRequest($was);
        }
    }

    /**
     * Runs a callback with an extra Hyper link type registered, as a plugin would.
     *
     * @param class-string<HyperLink> $class
     */
    public static function withHyperLinkType(string $class, callable $callback): mixed
    {
        $register = function(RegisterComponentTypesEvent $event) use ($class): void {
            $event->types[] = $class;
        };
        Event::on(HyperLinks::class, HyperLinks::EVENT_REGISTER_LINK_TYPES, $register);
        try {
            return $callback();
        } finally {
            Event::off(HyperLinks::class, HyperLinks::EVENT_REGISTER_LINK_TYPES, $register);
        }
    }

    /**
     * Changes the link types of an existing Hyper field and saves it. The callback gets the link types' configs, as
     * `hyperLinkType()` builds them, and returns the new ones.
     *
     * @param callable(list<array<string, mixed>>): list<array<string, mixed>> $change
     */
    public static function changeHyperLinkTypes(HyperField $field, callable $change): void
    {
        $fields = Craft::$app->getFields();
        $saved = $fields->getFieldByUid($field->uid);
        assert($saved instanceof HyperField);

        // A field keeps the link types it has already read, so the change is saved on a new instance.
        $saveChange = function() use ($fields, $saved, $change): ?array {
            $settings = $saved->getSettings();
            $updated = new HyperField([
                ...$settings,
                'id' => $saved->id,
                'uid' => $saved->uid,
                'name' => $saved->name,
                'handle' => $saved->handle,
                'linkTypes' => array_values($change($settings['linkTypes'])),
            ]);

            return $fields->saveField($updated) ? null : $updated->getErrors();
        };
        $errors = self::withConsoleFlagCleared($saveChange);
        if ($errors !== null) {
            self::fail("field \"$saved->handle\"", $errors);
        }
        self::refresh();
    }

    /**
     * Removes a link type from an existing Hyper field.
     */
    public static function removeHyperLinkType(HyperField $field, string $handle): void
    {
        self::changeHyperLinkTypes($field, fn(array $linkTypes) => array_filter($linkTypes, fn(array $config) => $config['handle'] !== $handle));
    }

    /**
     * Saves a Matrix field whose blocks are the given entry types.
     *
     * @param EntryType[] $entryTypes
     * @param array<string, mixed> $config
     */
    public static function matrix(string $handle, array $entryTypes, array $config = []): Matrix
    {
        return self::field(Matrix::class, $handle, array_merge([
            'entryTypes' => $entryTypes,
        ], $config));
    }

    /**
     * Saves a Neo field with the given block types, built with `neoBlockType()`. Block types allow every block type as
     * children.
     *
     * @param NeoBlockType[] $blockTypes
     * @param array<string, mixed> $config
     */
    public static function neo(string $handle, array $blockTypes, array $config = []): NeoField
    {
        foreach (array_values($blockTypes) as $i => $blockType) {
            $blockType->sortOrder = $i + 1;
        }

        $field = self::field(NeoField::class, $handle, array_merge([
            'blockTypes' => $blockTypes,
        ], $config));
        // The field keeps the block types it was saved with, which have no IDs, so load it again.
        self::refresh();

        $saved = Craft::$app->getFields()->getFieldByUid($field->uid);
        assert($saved instanceof NeoField);

        return $saved;
    }

    /**
     * Builds a Neo block type with the given custom fields, to pass to `neo()`.
     *
     * @param array<FieldInterface|CustomField> $fields
     * @param array<string, mixed> $config
     */
    public static function neoBlockType(string $handle, array $fields = [], array $config = []): NeoBlockType
    {
        $blockType = new NeoBlockType(array_merge([
            'name' => self::nameFor($handle),
            'handle' => $handle,
            'childBlocks' => '*',
        ], $config));

        $layout = new FieldLayout(['type' => NeoBlock::class]);
        $layout->setTabs([
            new FieldLayoutTab([
                'name' => 'Content',
                'layout' => $layout,
                'elements' => array_map(fn(FieldInterface|CustomField $field) => $field instanceof CustomField ? $field : new CustomField($field), $fields),
            ]),
        ]);
        // @phpstan-ignore method.notFound (a behavior)
        $blockType->setFieldLayout($layout);

        return $blockType;
    }

    /**
     * Sets properties on an existing Neo block type and saves it, e.g. `['name' => 'Renamed']`.
     *
     * @param array<string, mixed> $properties
     */
    public static function updateNeoBlockType(NeoField $field, string $handle, array $properties): void
    {
        $blockType = self::neoBlockTypeByHandle($field, $handle);
        foreach ($properties as $name => $value) {
            $blockType->$name = $value;
        }
        if (!self::neoBlockTypes()->save($blockType)) {
            self::fail("Neo block type \"$handle\"", $blockType->getErrors());
        }
        self::refresh();
    }

    /**
     * Deletes a Neo block type, with the blocks of that type.
     */
    public static function deleteNeoBlockType(NeoField $field, string $handle): void
    {
        $blockType = self::neoBlockTypeByHandle($field, $handle);
        if (!self::neoBlockTypes()->delete($blockType)) {
            throw new RuntimeException("Couldn't delete Neo block type \"$handle\".");
        }
        self::refresh();
    }

    private static function neoBlockTypes(): BlockTypes
    {
        $service = Neo::$plugin?->get('blockTypes');
        assert($service instanceof BlockTypes);

        return $service;
    }

    private static function neoBlockTypeByHandle(NeoField $field, string $handle): NeoBlockType
    {
        foreach (self::neoBlockTypes()->getByFieldId($field->id) as $blockType) {
            if ($blockType->handle === $handle) {
                return $blockType;
            }
        }

        throw new RuntimeException("No Neo block type \"$handle\" in field \"$field->handle\"");
    }

    /**
     * Saves a CKEditor field whose nested entries are the given entry types.
     *
     * @param EntryType[] $entryTypes
     * @param array<string, mixed> $config
     */
    public static function ckeditor(string $handle, array $entryTypes = [], array $config = []): CkEditorField
    {
        return self::field(CkEditorField::class, $handle, array_merge([
            'entryTypes' => $entryTypes,
            'toolbar' => ['heading', '|', 'bold', 'italic', 'link', 'createEntry'],
        ], $config));
    }

    /**
     * Saves an entry nested in a CKEditor field of the owner, as the editor's "create entry" button does, and returns
     * the marker that embeds it in the field's HTML. Set the field's value to HTML containing the marker.
     *
     * @param array<string, mixed> $values
     */
    public static function nestedCkeditorEntry(Entry $owner, CkEditorField $field, EntryType $entryType, array $values = []): string
    {
        $entry = new Entry([
            'typeId' => $entryType->id,
            'fieldId' => $field->id,
            'siteId' => $owner->siteId,
        ]);
        $entry->setOwner($owner);
        $entry->setPrimaryOwner($owner);
        self::saveElement($entry, $values);

        return "<craft-entry data-entry-id=\"$entry->id\">&nbsp;</craft-entry>";
    }

    /**
     * Wraps a field for `entryType()` so that it's required in the entry type's field layout.
     */
    public static function required(FieldInterface $field): CustomField
    {
        return new CustomField($field, ['required' => true]);
    }

    /**
     * Saves an entry type with a title field (unless `hasTitleField` is false) followed by the given custom fields.
     * Pass a field through `required()` to make it required.
     *
     * @param array<FieldInterface|CustomField> $fields
     * @param array<string, mixed> $config
     */
    public static function entryType(string $handle, array $fields = [], array $config = []): EntryType
    {
        $entryType = new EntryType(array_merge([
            'name' => self::nameFor($handle),
            'handle' => $handle,
        ], $config));

        $layout = new FieldLayout(['type' => Entry::class]);
        $layout->setTabs([
            new FieldLayoutTab([
                'name' => 'Content',
                'layout' => $layout,
                'elements' => [
                    ...($entryType->hasTitleField ? [new EntryTitleField()] : []),
                    ...array_map(fn(FieldInterface|CustomField $field) => $field instanceof CustomField ? $field : new CustomField($field), $fields),
                ],
            ]),
        ]);
        $entryType->setFieldLayout($layout);

        if (!Craft::$app->getEntries()->saveEntryType($entryType)) {
            self::fail("entry type \"$handle\"", $entryType->getErrors());
        }

        return $entryType;
    }

    /**
     * Saves an entry type without a title field, the usual shape for Matrix block types.
     *
     * @param array<FieldInterface|CustomField> $fields
     * @param array<string, mixed> $config
     */
    public static function blockType(string $handle, array $fields = [], array $config = []): EntryType
    {
        return self::entryType($handle, $fields, array_merge(['hasTitleField' => false], $config));
    }

    /**
     * Saves a section enabled for every site, without URLs.
     *
     * @param EntryType[] $entryTypes
     * @param array<string, mixed> $config
     */
    public static function section(string $handle, array $entryTypes, array $config = []): Section
    {
        $section = new Section(array_merge([
            'name' => self::nameFor($handle),
            'handle' => $handle,
            'type' => Section::TYPE_CHANNEL,
        ], $config));

        $siteSettings = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings[$site->id] = new Section_SiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => false,
            ]);
        }
        $section->setSiteSettings($siteSettings);
        $section->setEntryTypes($entryTypes);

        if (!Craft::$app->getEntries()->saveSection($section)) {
            self::fail("section \"$handle\"", $section->getErrors());
        }

        return $section;
    }

    /**
     * Saves a site without URLs, in the primary site's group. Create sites before the sections that use them.
     *
     * @param array<string, mixed> $config
     */
    public static function site(string $handle, array $config = []): Site
    {
        $site = new Site(array_merge([
            'groupId' => Craft::$app->getSites()->getPrimarySite()->groupId,
            'name' => self::nameFor($handle),
            'handle' => $handle,
            'language' => 'en-US',
            'hasUrls' => false,
        ], $config));

        if (!Craft::$app->getSites()->saveSite($site)) {
            self::fail("site \"$handle\"", $site->getErrors());
        }
        self::refresh();

        return $site;
    }

    /**
     * Deletes a site, as if it had never existed in the Target environment.
     */
    public static function deleteSite(Site $site): void
    {
        if (!Craft::$app->getSites()->deleteSite($site)) {
            throw new RuntimeException("Couldn't delete site \"$site->handle\".");
        }
        self::refresh();
    }

    /**
     * Saves an existing element's content in another site it exists in. Keys of `$values` that aren't element
     * attributes are set as custom field values.
     *
     * @template T of ElementInterface
     * @param T $element
     * @param array<string, mixed> $values
     * @return T
     */
    public static function translate(ElementInterface $element, Site $site, array $values): ElementInterface
    {
        $translation = $element::find()->id($element->id)->siteId($site->id)->status(null)->one()
            ?? throw new RuntimeException("The element $element->id doesn't exist in the site \"$site->handle\".");
        self::saveElement($translation, $values);

        return $translation;
    }

    /**
     * Saves a category group enabled for every site, without URLs.
     *
     * @param array<string, mixed> $config
     */
    public static function categoryGroup(string $handle, array $config = []): CategoryGroup
    {
        $group = new CategoryGroup(array_merge([
            'name' => self::nameFor($handle),
            'handle' => $handle,
        ], $config));

        $siteSettings = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings[$site->id] = new CategoryGroup_SiteSettings([
                'siteId' => $site->id,
                'hasUrls' => false,
            ]);
        }
        $group->setSiteSettings($siteSettings);
        $group->setFieldLayout(new FieldLayout(['type' => Category::class]));

        if (!Craft::$app->getCategories()->saveGroup($group)) {
            self::fail("category group \"$handle\"", $group->getErrors());
        }

        return $group;
    }

    /**
     * Saves a category. Pass `parentId` in `$values` to nest it under another category.
     *
     * @param array<string, mixed> $values
     */
    public static function category(CategoryGroup $group, array $values = []): Category
    {
        $category = new Category(['groupId' => $group->id]);
        self::saveElement($category, $values);

        return $category;
    }

    /**
     * Saves a Solspace Calendar calendar enabled for every site, without URLs.
     */
    public static function calendar(string $handle): CalendarModel
    {
        $calendar = CalendarModel::create();
        $calendar->name = self::nameFor($handle);
        $calendar->handle = $handle;

        $siteSettings = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings[] = new CalendarSiteSettingsModel([
                'uid' => StringHelper::UUID(),
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => false,
            ]);
        }
        $calendar->setSiteSettings($siteSettings);
        $calendar->setFieldLayout(new FieldLayout(['type' => CalendarEvent::class]));

        if (!Calendar::getInstance()->calendars->saveCalendar($calendar)) {
            self::fail("calendar \"$handle\"", $calendar->getErrors());
        }
        // Calendar memoizes its calendars privately, so swap in a fresh service.
        Calendar::getInstance()->set('calendars', CalendarsService::class);

        return $calendar;
    }

    /**
     * Saves a one-hour Calendar event starting on a date (`Y-m-d H:i`). Pass `rrule` in `$values` to make it repeat.
     *
     * @param array<string, mixed> $values
     */
    public static function calendarEvent(CalendarModel $calendar, string $startDate, array $values = []): CalendarEvent
    {
        $event = new CalendarEvent([
            'calendarId' => $calendar->id,
            'startDate' => $startDate,
            'endDate' => (new \DateTime($startDate))->modify('+1 hour')->format('Y-m-d H:i'),
            'allDay' => false,
        ]);
        self::saveElement($event, $values);

        return $event;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function tagGroup(string $handle, array $config = []): TagGroup
    {
        $group = new TagGroup(array_merge([
            'name' => self::nameFor($handle),
            'handle' => $handle,
        ], $config));
        $group->setFieldLayout(new FieldLayout(['type' => Tag::class]));

        if (!Craft::$app->getTags()->saveTagGroup($group)) {
            self::fail("tag group \"$handle\"", $group->getErrors());
        }

        return $group;
    }

    public static function tag(TagGroup $group, string $title): Tag
    {
        $tag = new Tag(['groupId' => $group->id]);
        self::saveElement($tag, ['title' => $title]);

        return $tag;
    }

    /**
     * Saves an active user.
     *
     * @param array<string, mixed> $values
     */
    public static function user(string $username, array $values = []): User
    {
        $user = new User(['username' => $username, 'email' => "$username@example.com", 'active' => true]);
        self::saveElement($user, $values);

        return $user;
    }

    /**
     * Gives a user exactly these permissions, e.g. `['accessCp', "createEntries:$section->uid"]`, replacing any they had.
     *
     * @param list<string> $permissions
     */
    public static function grantPermissions(User $user, array $permissions): void
    {
        if (!Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions)) {
            throw new RuntimeException("Couldn't save permissions for user \"$user->username\"");
        }
    }

    /**
     * Changes a user's email and username, as if the same person had a different account in the Target environment.
     */
    public static function changeUserIdentity(User $user, ?string $email, ?string $username = null): void
    {
        self::saveElement($user, array_filter(['email' => $email, 'username' => $username]));
    }

    /**
     * Sets properties on an existing field and saves it, e.g. `['charLimit' => 42]` or `['entryTypes' => [$text]]`
     * for a Matrix field. With no properties, re-saves the field unchanged.
     *
     * @param array<string, mixed> $properties
     */
    public static function updateField(string $fieldUid, array $properties = []): FieldInterface
    {
        $fields = Craft::$app->getFields();
        $field = $fields->getFieldByUid($fieldUid) ?? throw new RuntimeException("No field with UID $fieldUid");
        foreach ($properties as $name => $value) {
            $field->$name = $value;
        }

        if (!$fields->saveField($field)) {
            self::fail("field \"$field->handle\"", $field->getErrors());
        }
        self::refresh();

        return $field;
    }

    public static function deleteField(FieldInterface $field): void
    {
        if (!Craft::$app->getFields()->deleteField($field)) {
            throw new RuntimeException("Couldn't delete field \"$field->handle\".");
        }
        self::refresh();
    }

    /**
     * Sets properties on an existing section and saves it, e.g. `['propagationMethod' => PropagationMethod::All]`.
     *
     * @param array<string, mixed> $properties
     */
    public static function updateSection(Section $section, array $properties): Section
    {
        $entries = Craft::$app->getEntries();
        $section = $entries->getSectionById($section->id) ?? throw new RuntimeException("No section with ID $section->id");
        foreach ($properties as $name => $value) {
            $section->$name = $value;
        }

        if (!$entries->saveSection($section)) {
            self::fail("section \"$section->handle\"", $section->getErrors());
        }
        self::refresh();

        return $section;
    }

    /**
     * Stops a section from being enabled for a site.
     */
    public static function disableSectionForSite(Section $section, Site $site): Section
    {
        $section = Craft::$app->getEntries()->getSectionById($section->id) ?? throw new RuntimeException("No section with ID $section->id");
        $siteSettings = array_filter($section->getSiteSettings(), fn(Section_SiteSettings $settings) => $settings->siteId !== $site->id);

        return self::updateSection($section, ['siteSettings' => $siteSettings]);
    }

    public static function deleteSection(Section $section): void
    {
        if (!Craft::$app->getEntries()->deleteSection($section)) {
            throw new RuntimeException("Couldn't delete section \"$section->handle\".");
        }
        self::refresh();
    }

    /**
     * Changes a field's type, keeping its UID, name and handle, then refreshes the schema caches.
     *
     * @param class-string<FieldInterface> $class
     */
    public static function changeFieldType(string $fieldUid, string $class): FieldInterface
    {
        $fields = Craft::$app->getFields();
        $old = $fields->getFieldByUid($fieldUid) ?? throw new RuntimeException("No field with UID $fieldUid");
        $new = $fields->createField([
            'type' => $class,
            'id' => $old->id,
            'uid' => $old->uid,
            'name' => $old->name,
            'handle' => $old->handle,
        ]);

        if (!$fields->saveField($new)) {
            self::fail("field \"$old->handle\" as $class", $new->getErrors());
        }
        self::refresh();

        return $new;
    }

    /**
     * Clears Craft's in-memory schema caches, as a new request in the Target environment would.
     *
     * The methods that change existing schema call it already. Call it yourself only after changing schema through
     * Craft's services directly.
     */
    public static function refresh(): void
    {
        // Fields, entry types, sections and field layouts are all memoized, some privately, so swap in fresh
        // services built from the same definitions.
        $definitions = Craft::$app->getComponents();
        foreach (['fields', 'entries'] as $id) {
            Craft::$app->set($id, $definitions[$id]);
        }
        // Neo memoizes its block types statically.
        NeoMemoize::$blockTypesById = NeoMemoize::$blockTypesByHandle = NeoMemoize::$blockTypesByFieldId = [];
        NeoMemoize::$blockTypeGroupsById = NeoMemoize::$blockTypeGroupsByFieldId = NeoMemoize::$blockTypeRecordsById = [];
        // Element queries only apply their site param once Craft knows the install is multi-site.
        Craft::$app->getIsMultiSite(true);
        Craft::$app->getIsMultiSite(true, true);
    }

    /**
     * Gives an existing element a new UID, as if it had been created separately in the Target environment, and
     * returns the new UID.
     */
    public static function changeUid(ElementInterface $element): string
    {
        $uid = StringHelper::UUID();
        Db::update(Table::ELEMENTS, ['uid' => $uid], ['id' => $element->id]);
        $element->uid = $uid;

        return $uid;
    }

    /**
     * Adds a field to an existing entry type's field layout, as if the Target environment's project config had a
     * field the Source environment's didn't.
     */
    public static function addFieldToEntryType(EntryType $entryType, FieldInterface|CustomField $field): EntryType
    {
        $entries = Craft::$app->getEntries();
        $entryType = $entries->getEntryTypeById($entryType->id) ?? throw new RuntimeException("No entry type with ID $entryType->id");
        $layout = $entryType->getFieldLayout();
        $tab = $layout->getTabs()[0];
        $tab->setElements([...$tab->getElements(), $field instanceof CustomField ? $field : new CustomField($field)]);
        $layout->setTabs([$tab]);
        $entryType->setFieldLayout($layout);

        if (!$entries->saveEntryType($entryType)) {
            self::fail("entry type \"$entryType->handle\"", $entryType->getErrors());
        }
        self::refresh();

        return $entryType;
    }

    /**
     * Sets when an Import in the Import log happened, as a datetime in the site's time zone like "2026-11-12 10:15:00",
     * so a test can assert on its date.
     */
    public static function setImportDate(int $logId, string $dateTime): void
    {
        $date = new \DateTime($dateTime, new \DateTimeZone(Craft::$app->getTimeZone()));
        Db::update('{{%portage_importlog}}', ['dateCreated' => Db::prepareDateForDb($date)], ['id' => $logId]);
    }

    /**
     * Saves a volume on its own local filesystem, in a fresh temp directory. With a `url`, the filesystem has public
     * URLs there, e.g. the file server's `FileServer::start() . '/files'`; without one, it has none. The volume's field
     * layout holds the given custom fields.
     *
     * @param FieldInterface[] $fields
     * @param array<string, mixed> $config
     */
    public static function volume(string $handle, ?string $url = null, array $fields = [], array $config = []): Volume
    {
        $path = sys_get_temp_dir() . '/portage-volumes/' . $handle . '-' . StringHelper::randomString(8);
        FileHelper::createDirectory($path);

        $fs = new Local([
            'name' => self::nameFor($handle) . ' files',
            'handle' => $handle . 'Fs',
            'path' => $path,
            'hasUrls' => $url !== null,
            'url' => $url,
        ]);
        if (!Craft::$app->getFs()->saveFilesystem($fs)) {
            self::fail("filesystem \"{$handle}Fs\"", $fs->getErrors());
        }

        $volume = new Volume(array_merge([
            'name' => self::nameFor($handle),
            'handle' => $handle,
            'fsHandle' => $fs->handle,
        ], $config));
        $layout = new FieldLayout(['type' => Asset::class]);
        $layout->setTabs([
            new FieldLayoutTab([
                'name' => 'Content',
                'layout' => $layout,
                'elements' => array_map(fn(FieldInterface $field) => new CustomField($field), $fields),
            ]),
        ]);
        $volume->setFieldLayout($layout);

        if (!Craft::$app->getVolumes()->saveVolume($volume)) {
            self::fail("volume \"$handle\"", $volume->getErrors());
        }

        return $volume;
    }

    /**
     * Saves an asset from one of the files in `tests/_data/files`, in a folder of the volume (created if needed, e.g.
     * `"blog/2026"`). Keys of `$values` that aren't asset attributes are set as custom field values.
     *
     * @param array<string, mixed> $values
     */
    public static function asset(Volume $volume, string $fixture, string $folderPath = '', array $values = []): Asset
    {
        $source = dirname(__DIR__) . '/_data/files/' . $fixture;
        if (!is_file($source)) {
            throw new RuntimeException("No fixture file \"$fixture\" in tests/_data/files.");
        }
        $tempPath = Craft::$app->getPath()->getTempPath() . '/' . StringHelper::randomString(12) . '-' . $fixture;
        copy($source, $tempPath);

        $folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume($folderPath, $volume, false);
        $asset = new Asset();
        $asset->tempFilePath = $tempPath;
        $asset->setFilename($values['filename'] ?? $fixture);
        unset($values['filename']);
        $asset->newFolderId = $folder->id;
        $asset->setVolumeId($volume->id);
        $asset->setScenario(Asset::SCENARIO_CREATE);
        self::saveElement($asset, $values);

        return $asset;
    }

    /**
     * Gives a section URLs and SEOmatic a section-level meta bundle for it, whose meta global vars are the given values,
     * and returns the bundle's row. SEOmatic only makes meta bundles for sections with URLs.
     *
     * @param array<string, mixed> $globalVars
     * @return array<string, mixed>
     */
    public static function seomaticSectionBundle(Section $section, array $globalVars): array
    {
        foreach ($section->getSiteSettings() as $siteSettings) {
            $siteSettings->hasUrls = true;
            $siteSettings->uriFormat = $section->handle . '/{slug}';
            $siteSettings->template = '_' . $section->handle;
        }
        if (!Craft::$app->getEntries()->saveSection($section)) {
            self::fail("section \"$section->handle\" with URLs", $section->getErrors());
        }
        if (!self::seomaticSectionBundleRows($section)) {
            SeoEntry::createContentMetaBundle($section);
        }

        $updated = Craft::$app->getDb()->createCommand()
            ->update('{{%seomatic_metabundles}}', ['metaGlobalVars' => json_encode($globalVars)], ['sourceBundleType' => 'section', 'sourceId' => $section->id])
            ->execute();
        if ($updated === 0) {
            throw new RuntimeException("SEOmatic made no meta bundle for the section \"$section->handle\".");
        }

        return self::seomaticSectionBundleRows($section)[0];
    }

    /**
     * Returns the rows of SEOmatic's meta bundles for a section, one per site.
     *
     * @return list<array<string, mixed>>
     */
    public static function seomaticSectionBundleRows(Section $section): array
    {
        return Craft::$app->getDb()->createCommand(
            'SELECT * FROM {{%seomatic_metabundles}} WHERE [[sourceBundleType]] = :type AND [[sourceId]] = :id ORDER BY [[id]]',
            [':type' => 'section', ':id' => $section->id],
        )->queryAll();
    }

    /**
     * Runs a callback with Craft's general config changed, as in a Target environment configured differently, e.g.
     * `['maxUploadFileSize' => 50]`, then restores it.
     *
     * @template T
     * @param array<string, mixed> $values
     * @param callable(): T $callback
     * @return T
     */
    public static function withGeneralConfig(array $values, callable $callback): mixed
    {
        return self::withProperties(Craft::$app->getConfig()->getGeneral(), $values, $callback);
    }

    /**
     * Runs a callback with Ether SEO's global settings changed, e.g. `['robots' => ['noindex']]`, as in an environment
     * whose SEO defaults differ, then restores them.
     *
     * @template T
     * @param array<string, mixed> $values
     * @param callable(): T $callback
     * @return T
     */
    public static function withEtherSeoSettings(array $values, callable $callback): mixed
    {
        return self::withProperties(EtherSeo::$i->getSettings(), $values, $callback);
    }

    /**
     * Runs a callback with Colour Swatches' config-file settings changed, e.g. `['palettes' => ['Brand' => [...]]]`,
     * to simulate an environment whose `config/colour-swatches.php` defines different palettes, then restores them.
     *
     * @template T
     * @param array<string, mixed> $values
     * @param callable(): T $callback
     * @return T
     */
    public static function withColourSwatchesSettings(array $values, callable $callback): mixed
    {
        return self::withProperties(ColourSwatches::$plugin->getSettings(), $values, $callback);
    }

    /**
     * Runs a callback with Portage's plugin settings changed, e.g. `['fetchTimeout' => 2]`, then restores them.
     *
     * @template T
     * @param array<string, mixed> $values
     * @param callable(): T $callback
     * @return T
     */
    public static function withPortageSettings(array $values, callable $callback): mixed
    {
        return self::withProperties(Plugin::getInstance()->getSettings(), $values, $callback);
    }

    /**
     * Runs a callback in an environment where a plugin registers an element type with Portage, under a short name,
     * then unregisters it. Portage's element types are read once per service, so the service is rebuilt both times.
     * `$definition` is usually an `ElementTypeDefinition`; anything else tests that the registration is refused. The
     * definition's element class is registered with Craft too, as the plugin's own element type, so Craft resolves its
     * reference handle.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function withElementType(string $name, mixed $definition, callable $callback): mixed
    {
        $register = function(RegisterElementTypesEvent $event) use ($name, $definition) {
            $event->types[$name] = $definition;
        };
        $registerWithCraft = function(RegisterComponentTypesEvent $event) use ($definition) {
            if ($definition instanceof ElementTypeDefinition) {
                $event->types[] = $definition->elementClass();
            }
        };
        Event::on(ElementTypeRegistry::class, ElementTypeRegistry::EVENT_REGISTER_ELEMENT_TYPES, $register);
        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, $registerWithCraft);
        Plugin::getInstance()->set('portage', Portage::class);
        try {
            return $callback();
        } finally {
            Event::off(ElementTypeRegistry::class, ElementTypeRegistry::EVENT_REGISTER_ELEMENT_TYPES, $register);
            Event::off(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, $registerWithCraft);
            Plugin::getInstance()->set('portage', Portage::class);
        }
    }

    /**
     * Saves a widget, the element type of `tests/_support/Widget.php`.
     */
    public static function widget(string $title, string $slug): Widget
    {
        $widget = new Widget(['title' => $title, 'slug' => $slug]);
        if (!Craft::$app->getElements()->saveElement($widget)) {
            throw new RuntimeException('Couldn\'t save the widget: ' . implode(' ', $widget->getFirstErrors()));
        }

        return $widget;
    }

    /**
     * Saves a Formie form with no fields. Forms aren't in project config, so each environment has its own.
     */
    public static function form(string $title, string $handle): FormieForm
    {
        $form = new FormieForm(['title' => $title, 'handle' => $handle]);
        if (!Craft::$app->getElements()->saveElement($form)) {
            self::fail("form \"$handle\"", $form->getErrors());
        }

        return $form;
    }

    /**
     * Runs a callback in an environment configured with a Shared key, read from the env var `PORTAGE_TEST_SHARED_KEY`
     * as a real environment would, or with no Shared key when `$key` is null. Restores both afterwards.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function withSharedKey(?string $key, callable $callback): mixed
    {
        return self::withEnv(
            ['PORTAGE_TEST_SHARED_KEY' => $key],
            fn() => self::withPortageSettings(['sharedKey' => $key === null ? null : '$PORTAGE_TEST_SHARED_KEY'], $callback),
        );
    }

    /**
     * Runs a callback in an environment whose Shared key setting refers to the env var `PORTAGE_MISSING_KEY`, which
     * isn't set, as in an environment someone forgot to configure.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function withSharedKeyEnvVarUnset(callable $callback): mixed
    {
        return self::withEnv(
            ['PORTAGE_MISSING_KEY' => null],
            fn() => self::withPortageSettings(['sharedKey' => '$PORTAGE_MISSING_KEY'], $callback),
        );
    }

    /**
     * Runs a callback with env vars set, or unset when null, then restores them.
     *
     * @template T
     * @param array<string, string|null> $values
     * @param callable(): T $callback
     * @return T
     */
    public static function withEnv(array $values, callable $callback): mixed
    {
        $original = [];
        foreach ($values as $name => $value) {
            $original[$name] = [$_SERVER[$name] ?? null, getenv($name)];
            self::setEnv($name, $value);
        }
        try {
            return $callback();
        } finally {
            foreach ($original as $name => [$server, $env]) {
                self::setEnv($name, $env === false ? null : $env);
                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
            }
        }
    }

    private static function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[$name]);
            putenv($name);
        } else {
            $_SERVER[$name] = $value;
            putenv("$name=$value");
        }
    }

    /**
     * @template T
     * @param array<string, mixed> $values
     * @param callable(): T $callback
     * @return T
     */
    private static function withProperties(object $object, array $values, callable $callback): mixed
    {
        $original = [];
        foreach ($values as $name => $value) {
            $original[$name] = $object->$name;
            $object->$name = $value;
        }
        try {
            return $callback();
        } finally {
            foreach ($original as $name => $value) {
                $object->$name = $value;
            }
        }
    }

    public static function deleteVolume(Volume $volume): void
    {
        if (!Craft::$app->getVolumes()->deleteVolume($volume)) {
            throw new RuntimeException("Couldn't delete volume \"$volume->handle\".");
        }
        self::refresh();
    }

    /**
     * Hard-deletes an element, as if it had never existed in the Target environment. Neo leaves the blocks of a deleted owner to a queue job, which tests don't run, so its
     * blocks are deleted here and their UIDs are free again.
     */
    public static function deleteElement(ElementInterface $element): void
    {
        $elements = Craft::$app->getElements();
        if ($element->id !== null) {
            $blocks = NeoBlock::find()->primaryOwnerId($element->id)->status(null)->site('*')->drafts(null)->inReverse()->all();
            foreach ($blocks as $block) {
                $block->deletedWithOwner = true;
                $elements->deleteElement($block, true);
            }
        }
        $elements->deleteElement($element, true);
    }

    /**
     * Saves an entry. Keys of `$values` that aren't entry attributes are set as custom field values.
     *
     * @param array<string, mixed> $values
     */
    public static function entry(Section $section, EntryType $entryType, array $values = []): Entry
    {
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $entryType->id,
        ]);

        self::saveElement($entry, $values);

        return $entry;
    }

    /**
     * Changes an existing entry's attributes and field values and saves it, as an editor editing it in the Target
     * environment would.
     *
     * @param array<string, mixed> $values
     */
    public static function updateEntry(Entry $entry, array $values): Entry
    {
        $entry = Entry::find()->id($entry->id)->status(null)->one() ?? throw new RuntimeException("No entry with ID $entry->id");
        self::saveElement($entry, $values);

        return $entry;
    }

    /**
     * Applies a draft to its entry, as an editor applying it in the entry editor does, and returns the entry.
     *
     * It's applied as in a request of its own: Retour removes an element's old short link redirects only on the first
     * save of the element in a request.
     */
    public static function applyDraft(int $draftId): Entry
    {
        $draft = Entry::find()->draftId($draftId)->status(null)->one() ?? throw new RuntimeException("No draft with ID $draftId");
        (new ReflectionProperty(RetourRedirects::class, 'purgedShortLinkElementIds'))->setValue(null, []);

        return Craft::$app->getDrafts()->applyDraft($draft);
    }

    /**
     * Saves a manual Retour redirect, one that no element's short link made, from a URL to another.
     */
    public static function retourRedirect(string $from, string $to): void
    {
        $saved = Retour::getInstance()->getRedirects()->saveRedirect([
            'redirectSrcUrl' => $from,
            'redirectDestUrl' => $to,
            'redirectMatchType' => 'exactmatch',
            'redirectSrcMatch' => 'pathonly',
            'redirectHttpCode' => 301,
            'enabled' => true,
        ]);
        if (!$saved) {
            self::fail("Retour redirect \"$from\"", []);
        }
    }

    /**
     * Builds the target of a block paste: a Matrix or Neo field of a draft.
     */
    public static function blockTarget(Entry $draft, string $handle): BlockTarget
    {
        $field = Craft::$app->getFields()->getFieldByHandle($handle) ?? throw new RuntimeException("No field with the handle $handle");

        return new BlockTarget($draft, $field);
    }

    /**
     * Creates a draft of an entry, as the entry editor does, and saves values on it, leaving the entry as it was.
     *
     * @param array<string, mixed> $values
     * @param bool $provisional whether it's the user's provisional draft (the editor's autosave) rather than a named one
     */
    public static function draft(Entry $entry, array $values, bool $provisional = false): Entry
    {
        $entry = Entry::find()->id($entry->id)->status(null)->one() ?? throw new RuntimeException("No entry with ID $entry->id");
        $draft = Craft::$app->getDrafts()->createDraft($entry, (int)User::find()->admin()->one()->id, $provisional ? null : 'Staging edits', provisional: $provisional);
        self::saveElement($draft, $values);

        return $draft;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function saveElement(ElementInterface $element, array $values): void
    {
        $layout = $element->getFieldLayout();

        foreach ($values as $name => $value) {
            if ($layout?->getFieldByHandle($name)) {
                $element->setFieldValue($name, $value);
            } else {
                $element->$name = $value;
            }
        }

        if (!Craft::$app->getElements()->saveElement($element)) {
            self::fail($element::class, self::errorsWithNested($element));
        }
    }

    /**
     * Craft only reports "validation errors found in nested entries", so pull up the nested errors too.
     *
     * @return array<string, mixed>
     */
    private static function errorsWithNested(ElementInterface $element): array
    {
        $errors = $element->getErrors();

        foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            $value = $element->getFieldValue($field->handle);
            if (!$value instanceof ElementQueryInterface) {
                continue;
            }
            foreach ($value->getCachedResult() ?? [] as $i => $nested) {
                if ($nested->hasErrors()) {
                    $errors["{$field->handle}[$i]"] = self::errorsWithNested($nested);
                }
            }
        }

        return $errors;
    }

    private static function nameFor(string $handle): string
    {
        return ucfirst(preg_replace('/(?<!^)[A-Z]/', ' $0', $handle));
    }

    /**
     * @param array<string, mixed> $errors
     */
    private static function fail(string $what, array $errors): never
    {
        throw new RuntimeException("Couldn't save $what: " . json_encode($errors));
    }
}
