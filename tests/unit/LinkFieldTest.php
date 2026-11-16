<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fields\data\LinkData;
use craft\fields\Link;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\tests\support\FileServer;
use simplygoodwork\craftportage\tests\support\GlobalSetLinkType;
use simplygoodwork\craftportage\tests\support\Requires;
use simplygoodwork\craftportage\tests\support\Schema;

/**
 * The Craft Link field: links that don't point at an element round-trip as they are, and a link to an element is an
 * Embedded reference.
 */
class LinkFieldTest extends Unit
{
    private const ADVANCED = ['urlSuffix', 'target', 'title', 'class', 'id', 'rel', 'ariaLabel'];

    protected function _before(): void
    {
        parent::_before();
        Requires::classes('The Link field', Link::class);
    }

    public function testNonElementLinkTypesRoundTripAsTheyAre(): void
    {
        $link = Schema::link('cta', ['types' => ['url', 'email', 'tel', 'sms']]);
        $article = Schema::entryType('article', [$link]);
        $news = Schema::section('news', [$article]);

        $expected = [
            'url' => 'https://example.com/path?a=1',
            'email' => 'mailto:hello@example.com',
            'tel' => 'tel:+15551234567',
            'sms' => 'sms:+15557654321',
        ];
        foreach ($expected as $type => $value) {
            $source = Schema::entry($news, $article, ['title' => "Link $type", 'cta' => ['type' => $type, 'value' => $value]]);
            $payload = $this->portage()->export([$source]);
            Schema::deleteElement($source);

            $result = $this->portage()->import($payload);

            $imported = $this->importedEntry($result->roots[0]->elementId)->getFieldValue('cta');
            $this->assertInstanceOf(LinkData::class, $imported);
            $this->assertSame(['value' => $value, 'type' => $type], $imported->serialize(), $type);
            $this->assertSame([], $result->roots[0]->warnings, $type);
        }
    }

    public function testAdvancedAttributesAndTheLabelSurviveTheRoundTrip(): void
    {
        $link = Schema::link('cta', ['types' => ['url'], 'showLabelField' => true, 'advancedFields' => [...self::ADVANCED, 'download']]);
        $article = Schema::entryType('article', [$link]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Attributes', 'cta' => [
            'type' => 'url',
            'value' => 'https://example.com',
            'label' => 'Visit us',
            'urlSuffix' => '#team',
            'target' => '_blank',
            'title' => 'Our site',
            'class' => 'button primary',
            'id' => 'visit',
            'rel' => 'noopener',
            'ariaLabel' => 'Visit our site',
            'download' => true,
            'filename' => 'brochure.pdf',
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);

        $result = $this->portage()->import($payload);

        $imported = $this->importedEntry($result->roots[0]->elementId)->getFieldValue('cta');
        $this->assertSame([
            'value' => 'https://example.com',
            'type' => 'url',
            'label' => 'Visit us',
            'urlSuffix' => '#team',
            'target' => '_blank',
            'title' => 'Our site',
            'class' => 'button primary',
            'id' => 'visit',
            'rel' => 'noopener',
            'ariaLabel' => 'Visit our site',
            'download' => true,
            'filename' => 'brochure.pdf',
        ], $imported->serialize());
    }

    public function testEntryAssetAndCategoryLinksPointAtTheElementsTheyResolveToInThisEnvironment(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);
        $photos = Schema::volume('photos', 'https://example.com/files');
        $photo = Schema::asset($photos, 'photo.png', 'blog');
        $colours = Schema::categoryGroup('colours');
        $red = Schema::category($colours, ['title' => 'Red', 'slug' => 'red']);

        $article = Schema::entryType('article', [
            Schema::link('entryLink', ['types' => ['entry']]),
            Schema::link('assetLink', ['types' => ['asset']]),
            Schema::link('categoryLink', ['types' => ['category']]),
        ]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, [
            'title' => 'Links',
            'entryLink' => ['type' => 'entry', 'value' => "{entry:$craft->id@1:url}"],
            'assetLink' => ['type' => 'asset', 'value' => "{asset:$photo->id@1:url}"],
            'categoryLink' => ['type' => 'category', 'value' => "{category:$red->id@1:url}"],
        ]);

        $payload = $this->portage()->export([$source]);
        $siteUid = Craft::$app->getSites()->getPrimarySite()->uid;
        $fields = $this->portage()->preview($payload)->envelope['roots'][0]['fields'];
        $this->assertContains(['value' => "{entry:$craft->uid@$siteUid:url}", 'type' => 'entry'], $fields);
        Schema::deleteElement($source);
        Schema::deleteElement($craft);
        Schema::deleteElement($photo);
        Schema::deleteElement($red);
        $newCraft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);
        $newPhoto = Schema::asset($photos, 'photo.png', 'blog');
        $newRed = Schema::category($colours, ['title' => 'Red', 'slug' => 'red']);

        $result = $this->portage()->import($payload);

        $imported = $this->importedEntry($result->roots[0]->elementId);
        $this->assertSame("{entry:$newCraft->id@1:url}", $imported->getFieldValue('entryLink')->serialize()['value']);
        $this->assertSame("{asset:$newPhoto->id@1:url}", $imported->getFieldValue('assetLink')->serialize()['value']);
        $this->assertSame("{category:$newRed->id@1:url}", $imported->getFieldValue('categoryLink')->serialize()['value']);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testAnUnresolvedElementLinkClearsTheValueWithAWarning(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone topic', 'slug' => 'gone-topic']);

        $link = Schema::link('cta', ['types' => ['entry'], 'showLabelField' => true]);
        $article = Schema::entryType('article', [$link]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Linked', 'cta' => [
            'type' => 'entry',
            'value' => "{entry:$gone->id@1:url}",
            'label' => 'Read more',
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($gone);

        $result = $this->portage()->import($payload);

        $this->assertNull($this->importedEntry($result->roots[0]->elementId)->getFieldValue('cta'));
        $this->assertContains(
            'The entry "Gone topic" linked in the field "Cta" doesn\'t exist in this environment. The link will be cleared.',
            $result->roots[0]->warnings,
        );
        $this->assertSame('Gone topic', $result->roots[0]->unresolvedReferences[0]['title']);
    }

    public function testChoosingStubForAnUnresolvedElementLinkPointsTheLinkAtTheStub(): void
    {
        [$payload, $goneUid] = $this->exportLinkToAGoneTopic();

        $result = $this->portage()->import($payload, [$goneUid => 'stub']);

        $stub = Entry::find()->uid($goneUid)->status(null)->one();
        $this->assertNotNull($stub, 'No Stub with the source UID');
        $link = $this->importedEntry($result->roots[0]->elementId)->getFieldValue('cta');
        $this->assertSame("{entry:$stub->id@1:url}", $link->serialize()['value']);
        $this->assertSame('Read more', $link->getLabel());
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testChoosingAReplacementForAnUnresolvedElementLinkPointsTheLinkAtTheReplacement(): void
    {
        [$payload, $goneUid] = $this->exportLinkToAGoneTopic();
        $other = Entry::find()->slug('other')->one();

        $result = $this->portage()->import($payload, [$goneUid => $other->id]);

        $link = $this->importedEntry($result->roots[0]->elementId)->getFieldValue('cta');
        $this->assertSame("{entry:$other->id@1:url}", $link->serialize()['value']);
        $this->assertSame('Read more', $link->getLabel());
    }

    public function testALinkToATypeOfElementPortageDoesNotSupportIsLeftOutInsteadOfCarryingASourceId(): void
    {
        Schema::withLinkType(GlobalSetLinkType::class, function(): void {
            $settings = Schema::globalSet('footer');
            $cta = Schema::link('cta', ['types' => ['url', 'globalset']]);
            $article = Schema::entryType('article', [$cta]);
            $news = Schema::section('news', [$article]);
            $source = Schema::entry($news, $article, ['title' => 'Linked', 'cta' => [
                'type' => 'globalset',
                'value' => "{globalset:$settings->id@1:url}",
            ]]);

            $payload = $this->portage()->export([$source]);
            $this->assertStringNotContainsString('globalset', json_encode($this->portage()->preview($payload)->envelope['roots'][0]['fields']));
            Schema::deleteElement($source);

            $result = $this->portage()->import($payload);

            $this->assertNull($this->importedEntry($result->roots[0]->elementId)->getFieldValue('cta'));
        });
    }

    public function testALinkTypeTheTargetFieldDoesNotAllowIsClearedWithAWarningBeforeAnyReferenceIsResolved(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone topic', 'slug' => 'gone-topic']);
        $cta = Schema::link('cta', ['types' => ['url', 'email', 'entry']]);
        $article = Schema::entryType('article', [$cta]);
        $news = Schema::section('news', [$article]);
        $emailLink = Schema::entry($news, $article, ['title' => 'Email', 'cta' => ['type' => 'email', 'value' => 'hello@example.com']]);
        $entryLink = Schema::entry($news, $article, ['title' => 'Entry', 'cta' => ['type' => 'entry', 'value' => "{entry:$gone->id@1:url}"]]);

        $payload = $this->portage()->export([$emailLink, $entryLink]);
        Schema::deleteElement($emailLink);
        Schema::deleteElement($entryLink);
        Schema::deleteElement($gone);
        Schema::updateField($cta->uid, ['types' => ['url']]);

        $result = $this->portage()->import($payload);

        foreach ($result->roots as $root) {
            $this->assertNull($this->importedEntry($root->elementId)->getFieldValue('cta'));
        }
        $this->assertSame(['The field "Cta" has different settings in this environment than where it was exported. Its values will be imported, but check them.', 'The field "Cta" doesn\'t allow email links in this environment. The link will be cleared.'], $result->roots[0]->warnings);
        $this->assertSame(['The field "Cta" has different settings in this environment than where it was exported. Its values will be imported, but check them.', 'The field "Cta" doesn\'t allow entry links in this environment. The link will be cleared.'], $result->roots[1]->warnings);
        $this->assertSame([], $result->roots[1]->unresolvedReferences);
        $this->assertNull(Entry::find()->uid($gone->uid)->status(null)->one(), 'A Stub was created');
    }

    public function testTheLabelAndAttributesTheTargetFieldDoesNotEnableAreSkippedWithAWarning(): void
    {
        $cta = Schema::link('cta', ['types' => ['url'], 'showLabelField' => true, 'advancedFields' => ['target', 'title', 'download']]);
        $article = Schema::entryType('article', [$cta]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Attributes', 'cta' => [
            'type' => 'url',
            'value' => 'https://example.com',
            'label' => 'Visit us',
            'target' => '_blank',
            'title' => 'Our site',
            'download' => true,
            'filename' => 'brochure.pdf',
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::updateField($cta->uid, ['showLabelField' => false, 'advancedFields' => ['title']]);

        $result = $this->portage()->import($payload);

        $this->assertSame(
            ['value' => 'https://example.com', 'type' => 'url', 'title' => 'Our site'],
            $this->importedEntry($result->roots[0]->elementId)->getFieldValue('cta')->serialize(),
        );
        $this->assertSame(
            [
                'The field "Cta" has different settings in this environment than where it was exported. Its values will be imported, but check them.',
                'The field "Cta" doesn\'t allow label, target, download, filename in this environment. They will be skipped.',
            ],
            $result->roots[0]->warnings,
        );
    }

    public function testAnAssetLinkCanBeFetchedAndPointsAtTheFetchedAsset(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files');
        $photo = Schema::asset($photos, 'photo.png', 'blog', ['title' => 'Photo']);
        $uid = $photo->uid;
        [$news, $article] = $this->newsWithAssetLink();
        $source = Schema::entry($news, $article, ['title' => 'Linked', 'cta' => ['type' => 'asset', 'value' => "{asset:$photo->id@1:url}"]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($photo);

        $result = $this->portage()->import($payload, [$uid => 'fetch']);

        $fetched = Asset::find()->uid($uid)->one();
        $this->assertNotNull($fetched, 'No asset with the source UID');
        $this->assertSame("{asset:$fetched->id@1:url}", $this->importedEntry($result->roots[0]->elementId)->getFieldValue('cta')->serialize()['value']);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testAnAssetLinkWhoseFetchFailsIsCleared(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files');
        $gone = Schema::asset($photos, 'notes.txt', '', ['title' => 'Gone notes', 'filename' => 'gone.txt']);
        $uid = $gone->uid;
        [$news, $article] = $this->newsWithAssetLink();
        $source = Schema::entry($news, $article, ['title' => 'Linked', 'cta' => ['type' => 'asset', 'value' => "{asset:$gone->id@1:url}"]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($gone);

        $result = $this->portage()->import($payload, [$uid => 'fetch']);

        $this->assertNull($this->importedEntry($result->roots[0]->elementId)->getFieldValue('cta'));
        $this->assertSame(
            ['The asset "Gone notes" couldn\'t be fetched from ' . FileServer::start() . '/files/gone.txt: the file wasn\'t found at the source (HTTP 404 Not Found). Its relations and links to it were cleared.'],
            $result->roots[0]->warnings,
        );
    }

    /**
     * @return array{0: \craft\models\Section, 1: \craft\models\EntryType}
     */
    private function newsWithAssetLink(): array
    {
        $article = Schema::entryType('article', [Schema::link('cta', ['types' => ['asset']])]);

        return [Schema::section('news', [$article]), $article];
    }

    /**
     * Exports an entry whose link points at a topic that is then deleted, with another topic to replace it.
     *
     * @return array{0: string, 1: string} the Payload and the deleted topic's UID
     */
    private function exportLinkToAGoneTopic(): array
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone topic', 'slug' => 'gone-topic']);
        $goneUid = $gone->uid;
        Schema::entry($topics, $topic, ['title' => 'Other', 'slug' => 'other']);

        $article = Schema::entryType('article', [Schema::link('cta', ['types' => ['entry'], 'showLabelField' => true])]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Linked', 'cta' => [
            'type' => 'entry',
            'value' => "{entry:$gone->id@1:url}",
            'label' => 'Read more',
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($gone);

        return [$payload, $goneUid];
    }

    private function portage(): Portage
    {
        return Plugin::getInstance()->getPortage();
    }

    private function importedEntry(?int $id): Entry
    {
        $this->assertNotNull($id, 'The Import result has no element ID');
        $entry = Entry::find()->id($id)->status(null)->one();
        $this->assertNotNull($entry, "No entry with ID $id");

        return $entry;
    }
}
