<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use lewisjenkins\craftdynamicfields\fields\Checkboxes;
use lewisjenkins\craftdynamicfields\fields\Dropdown;
use lewisjenkins\craftdynamicfields\fields\Multiselect;
use lewisjenkins\craftdynamicfields\fields\Radiobuttons;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\tests\support\FileServer;
use simplygoodwork\craftportage\tests\support\Schema;
use yii\base\InvalidConfigException;

/**
 * LJ Dynamic Fields: a field declared in the dynamicFields setting moves its IDs as Schema references or References,
 * and becomes the IDs they resolve to. An undeclared field falls back with its warning, and a field the two
 * environments declare differently is skipped.
 */
class DynamicFieldsTest extends Unit
{
    private const SECTIONS = ['dynamicFields' => ['sectionIDs' => 'section']];

    public function testDeclaredSectionIdsBecomeTheIdsOfTheSameSectionsInTheTargetEnvironment(): void
    {
        [$field, $news, $events] = $this->blogChannels();
        $source = $this->page($field, ['sectionIDs' => [(string)$news->id, (string)$events->id]]);

        $payload = Schema::withPortageSettings(self::SECTIONS, fn() => $this->portage()->export([$source]));
        $preview = Schema::withPortageSettings(self::SECTIONS, fn() => $this->portage()->preview($payload));
        $this->assertSame(
            ['kind' => 'section', 'values' => [$news->uid, $events->uid]],
            $preview->envelope['roots'][0]['fields'][$field->uid] ?? null,
        );
        $this->assertArrayHasKey($news->uid, $preview->envelope['schema']['sections']);
        $this->assertArrayHasKey($events->uid, $preview->envelope['schema']['sections']);

        $recreated = Schema::recreateSection($news);
        $this->assertNotSame($news->id, $recreated->id);
        $result = Schema::withPortageSettings(self::SECTIONS, fn() => $this->portage()->import($payload));

        $this->assertSame('created', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertSame([], $result->roots[0]->warnings);
        $this->assertSame(
            sprintf('["%d","%d"]', $recreated->id, $events->id),
            $this->entry($result->roots[0]->elementId)->getFieldValue('sectionIDs'),
        );
    }

    public function testASectionThatDoesntExistInTheTargetEnvironmentIsDroppedWithAWarning(): void
    {
        [$field, $news, $events] = $this->blogChannels();
        $source = $this->page($field, ['sectionIDs' => [(string)$news->id, (string)$events->id]]);
        $payload = Schema::withPortageSettings(self::SECTIONS, fn() => $this->portage()->export([$source]));
        Schema::deleteSection($events);

        $result = Schema::withPortageSettings(self::SECTIONS, fn() => $this->portage()->import($payload));

        $this->assertSame(
            ['The section "Events Blog" selected in the field "Blog Channels" doesn\'t exist in this environment. It will be dropped from the field.'],
            $result->roots[0]->warnings,
        );
        $this->assertSame(sprintf('["%d"]', $news->id), $this->entry($result->roots[0]->elementId)->getFieldValue('sectionIDs'));
    }

    public function testAValueThatIsntTheIdOfTheDeclaredKindIsDroppedWithAWarning(): void
    {
        [$field, $news] = $this->blogChannels();
        $source = $this->page($field, ['sectionIDs' => [(string)$news->id, '999999', 'news']]);
        $payload = Schema::withPortageSettings(self::SECTIONS, fn() => $this->portage()->export([$source]));

        $result = Schema::withPortageSettings(self::SECTIONS, fn() => $this->portage()->import($payload));

        $this->assertSame([
            'The value "999999" in the field "Blog Channels" wasn\'t the ID of a section where it was exported. It will be dropped from the field.',
            'The value "news" in the field "Blog Channels" wasn\'t the ID of a section where it was exported. It will be dropped from the field.',
        ], $result->roots[0]->warnings);
        $this->assertSame(sprintf('["%d"]', $news->id), $this->entry($result->roots[0]->elementId)->getFieldValue('sectionIDs'));
    }

    public function testDeclaredEntryIdsAreReferencesMatchedByNaturalKey(): void
    {
        [$field, $news, $post] = $this->featuredEntry();
        $launch = Schema::entry($news, $post, ['title' => 'Launch', 'slug' => 'launch']);
        $source = $this->page($field, ['featuredEntry' => (string)$launch->id]);
        $settings = ['dynamicFields' => ['featuredEntry' => 'entry']];
        $payload = Schema::withPortageSettings($settings, fn() => $this->portage()->export([$source]));
        // The same entry in another environment: another ID and UID, the same section and slug.
        Schema::deleteElement($launch);
        $target = Schema::entry($news, $post, ['title' => 'Launch', 'slug' => 'launch']);

        $result = Schema::withPortageSettings($settings, fn() => $this->portage()->import($payload));

        $this->assertSame([], $result->roots[0]->warnings);
        $this->assertSame((string)$target->id, $this->entry($result->roots[0]->elementId)->getFieldValue('featuredEntry'));
    }

    public function testAnUnresolvedEntryCanBeStubbed(): void
    {
        [$field, $news, $post] = $this->featuredEntry();
        $launch = Schema::entry($news, $post, ['title' => 'Launch', 'slug' => 'launch']);
        $uid = $launch->uid;
        $source = $this->page($field, ['featuredEntry' => (string)$launch->id]);
        $settings = ['dynamicFields' => ['featuredEntry' => 'entry']];
        $payload = Schema::withPortageSettings($settings, fn() => $this->portage()->export([$source]));
        Schema::deleteElement($launch);

        $result = Schema::withPortageSettings($settings, fn() => $this->portage()->import($payload, [$uid => 'stub']));

        $stub = Entry::find()->uid($uid)->status(null)->one();
        $this->assertNotNull($stub, 'No Stub with the source UID');
        $this->assertSame([], $result->roots[0]->warnings);
        $this->assertSame((string)$stub->id, $this->entry($result->roots[0]->elementId)->getFieldValue('featuredEntry'));
    }

    public function testAnUnresolvedEntryIsDroppedWithAWarning(): void
    {
        [$field, $news, $post] = $this->featuredEntry();
        $launch = Schema::entry($news, $post, ['title' => 'Launch', 'slug' => 'launch']);
        $source = $this->page($field, ['featuredEntry' => (string)$launch->id]);
        $settings = ['dynamicFields' => ['featuredEntry' => 'entry']];
        $payload = Schema::withPortageSettings($settings, fn() => $this->portage()->export([$source]));
        Schema::deleteElement($launch);

        $result = Schema::withPortageSettings($settings, fn() => $this->portage()->import($payload));

        $this->assertSame(
            ['The entry "Launch" selected in the field "Featured Entry" doesn\'t exist in this environment. It will be dropped from the field.'],
            $result->roots[0]->warnings,
        );
        $this->assertSame('', $this->entry($result->roots[0]->elementId)->getFieldValue('featuredEntry'));
    }

    public function testAnUnresolvedElementOfARegisteredTypeIsNamedByItsDisplayName(): void
    {
        $field = Schema::field(Dropdown::class, 'featuredForm', ['name' => 'Featured Form', 'dropdownOptions' => '']);
        $contact = Schema::form('Contact', 'contact');
        $source = $this->page($field, ['featuredForm' => (string)$contact->id]);
        $settings = ['dynamicFields' => ['featuredForm' => 'formieForm']];
        $payload = Schema::withPortageSettings($settings, fn() => $this->portage()->export([$source]));
        Schema::deleteElement($contact);

        $result = Schema::withPortageSettings($settings, fn() => $this->portage()->import($payload));

        $this->assertSame(
            ['The form "Contact" selected in the field "Featured Form" doesn\'t exist in this environment. It will be dropped from the field.'],
            $result->roots[0]->warnings,
        );
        $this->assertSame('', $this->entry($result->roots[0]->elementId)->getFieldValue('featuredForm'));
    }

    public function testFetchedAssetsGetTheirIdsAndAFailedFetchIsDropped(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files');
        $photo = Schema::asset($photos, 'photo.png', '', ['title' => 'Photo']);
        $gone = Schema::asset($photos, 'photo.png', '', ['title' => 'Gone photo', 'filename' => 'gone.png']);
        [$photoUid, $goneUid] = [$photo->uid, $gone->uid];
        $field = Schema::field(Multiselect::class, 'gallery', ['name' => 'Gallery', 'multiselectOptions' => '']);
        $source = $this->page($field, ['gallery' => [(string)$gone->id, (string)$photo->id]]);
        $settings = ['dynamicFields' => ['gallery' => 'asset']];
        $payload = Schema::withPortageSettings($settings, fn() => $this->portage()->export([$source]));
        Schema::deleteElement($photo);
        Schema::deleteElement($gone);

        $result = Schema::withPortageSettings($settings, fn() => $this->portage()->import($payload, [$photoUid => 'fetch', $goneUid => 'fetch']));

        $fetched = Asset::find()->uid($photoUid)->one();
        $this->assertNotNull($fetched, 'No asset with the source UID');
        $this->assertSame(sprintf('["%d"]', $fetched->id), $this->entry($result->roots[0]->elementId)->getFieldValue('gallery'));
        $this->assertSame(
            ['The asset "Gone photo" couldn\'t be fetched from ' . FileServer::start() . '/files/gone.png: the file wasn\'t found at the source (HTTP 404 Not Found). Its relations and links to it were cleared.'],
            $result->roots[0]->warnings,
        );
    }

    public function testDeclaredCategoryGroupAndCategoryIdsRoundTrip(): void
    {
        $topics = Schema::categoryGroup('topics');
        $regions = Schema::categoryGroup('regions');
        $travel = Schema::category($topics, ['title' => 'Travel', 'slug' => 'travel']);
        $groups = Schema::field(Checkboxes::class, 'groups', ['name' => 'Groups', 'checkboxOptions' => '']);
        $topic = Schema::field(Radiobuttons::class, 'topic', ['name' => 'Topic', 'radioOptions' => '']);
        $source = $this->page([$groups, $topic], [
            'groups' => [(string)$topics->id, (string)$regions->id],
            'topic' => (string)$travel->id,
        ]);
        $settings = ['dynamicFields' => ['groups' => 'categoryGroup', 'topic' => 'category']];

        $payload = Schema::withPortageSettings($settings, fn() => $this->portage()->export([$source]));
        $preview = Schema::withPortageSettings($settings, fn() => $this->portage()->preview($payload));
        $this->assertSame([$topics->uid, $regions->uid], array_keys($preview->envelope['schema']['categoryGroups']));
        $this->assertSame(
            ['kind' => 'category', 'values' => [$travel->uid]],
            $preview->envelope['roots'][0]['fields'][$topic->uid] ?? null,
        );

        $result = Schema::withPortageSettings($settings, fn() => $this->portage()->import($payload));

        $imported = $this->entry($result->roots[0]->elementId);
        $this->assertSame([], $result->roots[0]->warnings);
        $this->assertSame(sprintf('["%d","%d"]', $topics->id, $regions->id), $imported->getFieldValue('groups'));
        $this->assertSame((string)$travel->id, $imported->getFieldValue('topic'));
    }

    public function testAnUndeclaredFieldFallsBackWithItsWarning(): void
    {
        [$field, $news] = $this->blogChannels();
        $source = $this->page($field, ['sectionIDs' => [(string)$news->id]]);
        $payload = $this->portage()->export([$source]);

        $result = $this->portage()->import($payload);

        $this->assertSame(
            ['The field "Blog Channels" is a Multi-select (dynamic) field, which Portage has no adapter for. Its value will be imported as it is, and any IDs in it may point at different elements in this environment.'],
            $result->roots[0]->warnings,
        );
        $this->assertSame(sprintf('["%d"]', $news->id), $this->entry($result->roots[0]->elementId)->getFieldValue('sectionIDs'));
    }

    public function testAFieldDeclaredOnlyWhereItWasExportedIsSkipped(): void
    {
        [$field, $news] = $this->blogChannels();
        $source = $this->page($field, ['sectionIDs' => [(string)$news->id]]);
        $payload = Schema::withPortageSettings(self::SECTIONS, fn() => $this->portage()->export([$source]));

        $result = $this->portage()->import($payload);

        $this->assertSame(
            ['The field "Blog Channels" held section IDs where it was exported, but it isn\'t declared in the dynamicFields setting in this environment. Its values will be skipped.'],
            $result->roots[0]->warnings,
        );
        $this->assertSame('', $this->entry($result->roots[0]->elementId)->getFieldValue('sectionIDs'));
    }

    public function testAFieldDeclaredOnlyInTheTargetEnvironmentIsSkipped(): void
    {
        [$field, $news] = $this->blogChannels();
        $source = $this->page($field, ['sectionIDs' => [(string)$news->id]]);
        $payload = $this->portage()->export([$source]);

        $result = Schema::withPortageSettings(self::SECTIONS, fn() => $this->portage()->import($payload));

        $this->assertSame(
            ['The field "Blog Channels" holds section IDs in this environment, but it wasn\'t declared in the dynamicFields setting where it was exported, so its values are IDs from that environment. Its values will be skipped.'],
            $result->roots[0]->warnings,
        );
        $this->assertSame('', $this->entry($result->roots[0]->elementId)->getFieldValue('sectionIDs'));
    }

    public function testAFieldDeclaredAsAnotherKindInTheTargetEnvironmentIsSkipped(): void
    {
        [$field, $news] = $this->blogChannels();
        $source = $this->page($field, ['sectionIDs' => [(string)$news->id]]);
        $payload = Schema::withPortageSettings(self::SECTIONS, fn() => $this->portage()->export([$source]));

        $result = Schema::withPortageSettings(
            ['dynamicFields' => ['sectionIDs' => 'entry']],
            fn() => $this->portage()->import($payload),
        );

        $this->assertSame(
            ['The field "Blog Channels" held section IDs where it was exported, but holds entry IDs in this environment. Its values will be skipped.'],
            $result->roots[0]->warnings,
        );
        $this->assertSame('', $this->entry($result->roots[0]->elementId)->getFieldValue('sectionIDs'));
    }

    public function testAnEmptyValueNeedsNoMatchingDeclaration(): void
    {
        [$field] = $this->blogChannels();
        $source = $this->page($field, []);
        $payload = Schema::withPortageSettings(self::SECTIONS, fn() => $this->portage()->export([$source]));

        $result = $this->portage()->import($payload);

        $this->assertSame([], $result->roots[0]->warnings);
        $this->assertSame('', $this->entry($result->roots[0]->elementId)->getFieldValue('sectionIDs'));
    }

    public function testAnUnknownKindIsRefused(): void
    {
        [$field, $news] = $this->blogChannels();
        $source = $this->page($field, ['sectionIDs' => [(string)$news->id]]);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The dynamicFields setting declares the field "sectionIDs" as "sections", which isn\'t a kind Portage knows. Use one of section, entryType, categoryGroup, volume, site, or the short name of a registered element type, such as entry, category, tag, user or asset.');

        Schema::withPortageSettings(
            ['dynamicFields' => ['sectionIDs' => 'sections']],
            fn() => $this->portage()->export([$source]),
        );
    }

    /**
     * A multi-select of blog sections, as on pbs-hub, and two blog sections.
     *
     * @return array{0: Multiselect, 1: Section, 2: Section}
     */
    private function blogChannels(): array
    {
        $field = Schema::field(Multiselect::class, 'sectionIDs', ['name' => 'Blog Channels', 'multiselectOptions' => '']);
        $post = Schema::entryType('post');

        return [
            $field,
            Schema::section('newsBlog', [$post], ['name' => 'News Blog']),
            Schema::section('eventsBlog', [$post], ['name' => 'Events Blog']),
        ];
    }

    /**
     * A dropdown of entries, and a section of entries to choose from.
     *
     * @return array{0: Dropdown, 1: Section, 2: EntryType}
     */
    private function featuredEntry(): array
    {
        $field = Schema::field(Dropdown::class, 'featuredEntry', ['name' => 'Featured Entry', 'dropdownOptions' => '']);
        $post = Schema::entryType('post');

        return [$field, Schema::section('news', [$post]), $post];
    }

    /**
     * Saves a page, in a section of pages whose entry type has the fields.
     *
     * @param FieldInterface|list<FieldInterface> $fields
     * @param array<string, mixed> $values
     */
    private function page(FieldInterface|array $fields, array $values): Entry
    {
        $page = Schema::entryType('page', is_array($fields) ? $fields : [$fields]);

        return Schema::entry(Schema::section('pages', [$page]), $page, ['title' => 'Blog'] + $values);
    }

    private function entry(?int $id): Entry
    {
        $this->assertNotNull($id, 'The Import result has no element ID');
        $entry = Entry::find()->id($id)->status(null)->one();
        $this->assertNotNull($entry, "No entry with ID $id");

        return $entry;
    }

    private function portage(): Portage
    {
        return Plugin::getInstance()->getPortage();
    }
}
