<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Asset;
use craft\elements\Entry;
use presseddigital\linkit\models\Asset as AssetLink;
use presseddigital\linkit\models\Category as CategoryLink;
use presseddigital\linkit\models\Email;
use presseddigital\linkit\models\Entry as EntryLink;
use presseddigital\linkit\models\Phone;
use presseddigital\linkit\models\Product;
use presseddigital\linkit\models\Twitter;
use presseddigital\linkit\models\Url;
use presseddigital\linkit\models\User as UserLink;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\tests\support\FileServer;
use simplygoodwork\craftportage\tests\support\Schema;

/**
 * Pressed Digital's Linkit field: links that don't point at an element round-trip as they are, and a link to an element
 * is an Embedded reference.
 */
class LinkitTest extends Unit
{
    public function testUrlEmailPhoneAndSocialLinksRoundTripAsTheyAreWithTheirTextAndTarget(): void
    {
        $cta = Schema::linkit('cta', [Url::class, Email::class, Phone::class, Twitter::class], ['allowCustomText' => true, 'allowTarget' => true]);
        $article = Schema::entryType('article', [$cta]);
        $news = Schema::section('news', [$article]);

        $expected = [
            'url' => [Url::class, 'https://example.com/path?a=1'],
            'email' => [Email::class, 'hello@example.com'],
            'phone' => [Phone::class, '+15551234567'],
            'twitter' => [Twitter::class, 'https://twitter.com/example'],
        ];
        foreach ($expected as $handle => [$class, $value]) {
            $source = Schema::entry($news, $article, ['title' => "Link $handle", 'cta' => [
                'type' => $class,
                'value' => $value,
                'customText' => 'Get in touch',
                'target' => true,
            ]]);
            $payload = $this->portage()->export([$source]);
            $this->assertContains(
                ['type' => $handle, 'value' => $value, 'customText' => 'Get in touch', 'target' => true],
                $this->portage()->preview($payload)->envelope['roots'][0]['fields'],
                $handle,
            );
            Schema::deleteElement($source);

            $result = $this->portage()->import($payload);

            $link = $this->importedEntry($result->roots[0]->elementId)->getFieldValue('cta');
            $this->assertInstanceOf($class, $link, $handle);
            $this->assertSame($value, $link->value, $handle);
            $this->assertSame('Get in touch', $link->customText, $handle);
            $this->assertTrue($link->target, $handle);
            $this->assertSame([], $result->roots[0]->warnings, $handle);
        }
    }

    public function testThePayloadNamesALinkTypeByItsHandleNeverItsClass(): void
    {
        $cta = Schema::linkit('cta', [Url::class]);
        $article = Schema::entryType('article', [$cta]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Linked', 'cta' => ['type' => Url::class, 'value' => 'https://example.com']]);

        $payload = $this->portage()->export([$source]);

        $this->assertStringNotContainsString('linkit', json_encode($this->portage()->preview($payload)->envelope['roots'][0]['fields']));
    }

    public function testEntryAssetCategoryAndUserLinksPointAtTheElementsTheyResolveToInThisEnvironment(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);
        $photos = Schema::volume('photos', 'https://example.com/files');
        $photo = Schema::asset($photos, 'photo.png', 'blog');
        $colours = Schema::categoryGroup('colours');
        $red = Schema::category($colours, ['title' => 'Red', 'slug' => 'red']);
        $author = Schema::user('author');

        $article = Schema::entryType('article', [
            Schema::linkit('entryLink', [EntryLink::class]),
            Schema::linkit('assetLink', [AssetLink::class]),
            Schema::linkit('categoryLink', [CategoryLink::class]),
            Schema::linkit('userLink', [UserLink::class]),
        ]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, [
            'title' => 'Links',
            'entryLink' => ['type' => EntryLink::class, 'value' => (string)$craft->id],
            'assetLink' => ['type' => AssetLink::class, 'value' => (string)$photo->id],
            'categoryLink' => ['type' => CategoryLink::class, 'value' => (string)$red->id],
            'userLink' => ['type' => UserLink::class, 'value' => (string)$author->id],
        ]);

        $payload = $this->portage()->export([$source]);
        $fields = $this->portage()->preview($payload)->envelope['roots'][0]['fields'];
        $this->assertContains(['type' => 'entry', 'value' => $craft->uid, 'customText' => null, 'target' => null], $fields);
        Schema::deleteElement($source);
        Schema::deleteElement($craft);
        Schema::deleteElement($photo);
        Schema::deleteElement($red);
        $newCraft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);
        $newPhoto = Schema::asset($photos, 'photo.png', 'blog');
        $newRed = Schema::category($colours, ['title' => 'Red', 'slug' => 'red']);

        $result = $this->portage()->import($payload);

        $imported = $this->importedEntry($result->roots[0]->elementId);
        $this->assertSame((string)$newCraft->id, $imported->getFieldValue('entryLink')->value);
        $this->assertSame((string)$newPhoto->id, $imported->getFieldValue('assetLink')->value);
        $this->assertSame((string)$newRed->id, $imported->getFieldValue('categoryLink')->value);
        $this->assertSame((string)$author->id, $imported->getFieldValue('userLink')->value);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testAnUnresolvedElementLinkIsClearedWithAWarning(): void
    {
        [$payload] = $this->exportLinkToAGoneTopic();

        $result = $this->portage()->import($payload);

        $this->assertNull($this->importedEntry($result->roots[0]->elementId)->getFieldValue('cta'));
        $this->assertSame(
            ['The entry "Gone topic" linked in the field "Cta" doesn\'t exist in this environment. The link will be cleared.'],
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
        $this->assertInstanceOf(EntryLink::class, $link);
        $this->assertSame((string)$stub->id, $link->value);
        $this->assertSame('Read more', $link->customText);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testChoosingAReplacementForAnUnresolvedElementLinkPointsTheLinkAtTheReplacement(): void
    {
        [$payload, $goneUid] = $this->exportLinkToAGoneTopic();
        $other = Entry::find()->slug('other')->one();

        $result = $this->portage()->import($payload, [$goneUid => $other->id]);

        $this->assertSame((string)$other->id, $this->importedEntry($result->roots[0]->elementId)->getFieldValue('cta')->value);
    }

    public function testALinkTypeTheTargetFieldDoesNotEnableIsClearedWithAWarningBeforeAnyReferenceIsResolved(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone topic', 'slug' => 'gone-topic']);
        $cta = Schema::linkit('cta', [Url::class, Email::class, EntryLink::class]);
        $article = Schema::entryType('article', [$cta]);
        $news = Schema::section('news', [$article]);
        $emailLink = Schema::entry($news, $article, ['title' => 'Email', 'cta' => ['type' => Email::class, 'value' => 'hello@example.com']]);
        $entryLink = Schema::entry($news, $article, ['title' => 'Entry', 'cta' => ['type' => EntryLink::class, 'value' => (string)$gone->id]]);

        $payload = $this->portage()->export([$emailLink, $entryLink]);
        Schema::deleteElement($emailLink);
        Schema::deleteElement($entryLink);
        Schema::deleteElement($gone);
        Schema::changeLinkitTypes($cta, [Url::class]);

        $result = $this->portage()->import($payload, [$gone->uid => 'stub']);

        foreach ($result->roots as $root) {
            $this->assertNull($this->importedEntry($root->elementId)->getFieldValue('cta'));
        }
        $this->assertSame(['The field "Cta" has different settings in this environment than where it was exported. Its values will be imported, but check them.', 'The field "Cta" doesn\'t allow email links in this environment. The link will be cleared.'], $result->roots[0]->warnings);
        $this->assertSame(['The field "Cta" has different settings in this environment than where it was exported. Its values will be imported, but check them.', 'The field "Cta" doesn\'t allow entry links in this environment. The link will be cleared.'], $result->roots[1]->warnings);
        $this->assertSame([], $result->roots[1]->unresolvedReferences);
        $this->assertNull(Entry::find()->uid($gone->uid)->status(null)->one(), 'A Stub was created');
    }

    public function testAProductLinkIsClearedWithAWarningInsteadOfCarryingASourceId(): void
    {
        $cta = Schema::linkit('cta', [Url::class, Product::class]);
        $article = Schema::entryType('article', [$cta]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Linked', 'cta' => ['type' => Product::class, 'value' => '1234']]);

        $payload = $this->portage()->export([$source]);
        $this->assertContains(['type' => 'product', 'unsupported' => true], $this->portage()->preview($payload)->envelope['roots'][0]['fields']);
        Schema::deleteElement($source);

        $result = $this->portage()->import($payload);

        $this->assertNull($this->importedEntry($result->roots[0]->elementId)->getFieldValue('cta'));
        $this->assertSame(
            ['The field "Cta" links to a product, which Portage can\'t move between environments. The link will be cleared.'],
            $result->roots[0]->warnings,
        );
    }

    public function testAnAssetLinkCanBeFetchedAndPointsAtTheFetchedAsset(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files');
        $photo = Schema::asset($photos, 'photo.png', 'blog', ['title' => 'Photo']);
        $uid = $photo->uid;
        [$news, $article] = $this->newsWithAssetLink();
        $source = Schema::entry($news, $article, ['title' => 'Linked', 'cta' => ['type' => AssetLink::class, 'value' => (string)$photo->id]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($photo);

        $result = $this->portage()->import($payload, [$uid => 'fetch']);

        $fetched = Asset::find()->uid($uid)->one();
        $this->assertNotNull($fetched, 'No asset with the source UID');
        $this->assertSame((string)$fetched->id, $this->importedEntry($result->roots[0]->elementId)->getFieldValue('cta')->value);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testAnAssetLinkWhoseFetchFailsIsCleared(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files');
        $gone = Schema::asset($photos, 'notes.txt', '', ['title' => 'Gone notes', 'filename' => 'gone.txt']);
        $uid = $gone->uid;
        [$news, $article] = $this->newsWithAssetLink();
        $source = Schema::entry($news, $article, ['title' => 'Linked', 'cta' => ['type' => AssetLink::class, 'value' => (string)$gone->id]]);

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
        $article = Schema::entryType('article', [Schema::linkit('cta', [AssetLink::class])]);

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

        $article = Schema::entryType('article', [Schema::linkit('cta', [EntryLink::class], ['allowCustomText' => true])]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Linked', 'cta' => [
            'type' => EntryLink::class,
            'value' => (string)$gone->id,
            'customText' => 'Read more',
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
