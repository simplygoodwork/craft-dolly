<?php

namespace simplygoodwork\craftportage\tests\support;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use RuntimeException;

/**
 * Builds schema (fields, entry types, sections) and entries inside tests.
 *
 * Every method saves through Craft's public services and throws if Craft
 * rejects the save, so a broken fixture fails loudly at the line that built it.
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
     * Call it after changing the install between Export and Import, so the Import doesn't see stale fields or
     * entry types.
     */
    public static function refresh(): void
    {
        // Fields, entry types, sections and field layouts are all memoized, some privately, so swap in fresh
        // services built from the same definitions.
        $definitions = Craft::$app->getComponents();
        foreach (['fields', 'entries'] as $id) {
            Craft::$app->set($id, $definitions[$id]);
        }
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
