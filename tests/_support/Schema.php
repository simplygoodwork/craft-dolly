<?php

namespace simplygoodwork\craftportage\tests\support;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\db\Table;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\helpers\Db;
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
use RuntimeException;

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
    public static function addFieldToEntryType(EntryType $entryType, FieldInterface $field): EntryType
    {
        $entries = Craft::$app->getEntries();
        $entryType = $entries->getEntryTypeById($entryType->id) ?? throw new RuntimeException("No entry type with ID $entryType->id");
        $layout = $entryType->getFieldLayout();
        $tab = $layout->getTabs()[0];
        $tab->setElements([...$tab->getElements(), new CustomField($field)]);
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
     * Hard-deletes an element, as if it had never existed in the Target environment.
     */
    public static function deleteElement(ElementInterface $element): void
    {
        Craft::$app->getElements()->deleteElement($element, true);
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
