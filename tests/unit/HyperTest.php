<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fields\Entries;
use craft\models\EntryType;
use craft\models\Section;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\tests\support\FileServer;
use simplygoodwork\craftportage\tests\support\GlobalSetHyperLinkType;
use simplygoodwork\craftportage\tests\support\Requires;
use simplygoodwork\craftportage\tests\support\Schema;
use verbb\hyper\base\Link;
use verbb\hyper\fields\HyperField;
use verbb\hyper\links as linkTypes;
use verbb\hyper\models\LinkCollection;

/**
 * The Hyper adapter: several links per field, each of a link type; links to elements are Embedded references and each
 * link type's custom fields travel with the link.
 */
class HyperTest extends Unit
{
    public function testRoundTripsSeveralLinksOfMixedTypesInOrderWithTheirSettings(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);
        $photos = Schema::volume('photos', 'https://example.com/files');
        $photo = Schema::asset($photos, 'photo.png', 'blog');
        [$news, $article] = $this->newsWithLinks();

        $source = Schema::entry($news, $article, ['title' => 'Links', 'links' => [
            ['handle' => 'url', 'linkValue' => 'https://example.com/path?a=1', 'linkText' => 'Example', 'newWindow' => true, 'linkTitle' => 'Our site', 'classes' => 'button', 'urlSuffix' => '#team'],
            ['handle' => 'entry', 'linkValue' => [$craft->id], 'linkSiteId' => 1, 'linkText' => 'Read'],
            ['handle' => 'asset', 'linkValue' => [$photo->id], 'linkSiteId' => 1],
            ['handle' => 'email', 'linkValue' => 'hello@example.com'],
            ['handle' => 'embed', 'linkValue' => ['url' => 'https://example.com/video', 'title' => 'A video', 'code' => '<iframe src="https://example.com/video"></iframe>']],
        ]]);
        $this->assertCount(5, $source->getFieldValue('links')->getLinks());

        $payload = $this->portage()->export([$source]);
        // The links name their link types by UID, never by class.
        $exported = json_encode($this->portage()->preview($payload)->envelope['roots'][0]['fields']);
        $this->assertStringNotContainsString('verbb', $exported);
        $this->assertStringContainsString('"linkType":"url"', $exported);
        Schema::deleteElement($source);

        $result = $this->portage()->import($payload);

        $links = $this->importedLinks($result->roots[0]->elementId);
        $this->assertSame(['url', 'entry', 'asset', 'email', 'embed'], array_map(fn($link) => $link->handle, $links));
        $this->assertSame('https://example.com/path?a=1', $links[0]->linkValue);
        $this->assertSame('Example', $links[0]->linkText);
        $this->assertTrue($links[0]->newWindow);
        $this->assertSame('Our site', $links[0]->linkTitle);
        $this->assertSame('button', $links[0]->classes);
        $this->assertSame('#team', $links[0]->urlSuffix);
        $this->assertSame([$craft->id], $this->linkedIds($links[1]));
        $this->assertSame(1, $links[1]->linkSiteId);
        $this->assertSame('Read', $links[1]->linkText);
        $this->assertSame([$photo->id], $this->linkedIds($links[2]));
        $this->assertSame('hello@example.com', $links[3]->linkValue);
        $this->assertSame('A video', $links[4]->linkValue['title']);
        $this->assertSame('https://example.com/video', $links[4]->linkValue['url']);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testElementLinksPointAtTheElementsTheyResolveToInThisEnvironment(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);
        [$news, $article] = $this->newsWithLinks();
        $source = Schema::entry($news, $article, ['title' => 'Links', 'links' => [
            ['handle' => 'entry', 'linkValue' => [$craft->id], 'linkSiteId' => 1],
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($craft);
        $newCraft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);

        $result = $this->portage()->import($payload);

        $this->assertSame([$newCraft->id], $this->linkedIds($this->importedLinks($result->roots[0]->elementId)[0]));
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testAnUnresolvedElementLinkIsRemovedWithAWarningWhileTheOtherLinksRemain(): void
    {
        [$payload, $goneUid] = $this->exportLinksWithAGoneTopic();

        $result = $this->portage()->import($payload);

        $links = $this->importedLinks($result->roots[0]->elementId);
        $this->assertSame(['url', 'email'], array_map(fn($link) => $link->handle, $links));
        $this->assertSame('https://example.com', $links[0]->linkValue);
        $this->assertSame(
            ['The entry "Gone topic" linked in the field "Links" doesn\'t exist in this environment. The link will be removed.'],
            $result->roots[0]->warnings,
        );
        $this->assertSame($goneUid, $result->roots[0]->unresolvedReferences[0]['uid']);
    }

    public function testChoosingStubForAnUnresolvedElementLinkPointsTheLinkAtTheStub(): void
    {
        [$payload, $goneUid] = $this->exportLinksWithAGoneTopic();

        $result = $this->portage()->import($payload, [$goneUid => 'stub']);

        $stub = Entry::find()->uid($goneUid)->status(null)->one();
        $this->assertNotNull($stub, 'No Stub with the source UID');
        $links = $this->importedLinks($result->roots[0]->elementId);
        $this->assertSame(['url', 'entry', 'email'], array_map(fn($link) => $link->handle, $links));
        $this->assertSame([$stub->id], $this->linkedIds($links[1]));
        $this->assertSame('Read', $links[1]->linkText);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testChoosingAReplacementForAnUnresolvedElementLinkPointsTheLinkAtTheReplacement(): void
    {
        [$payload, $goneUid] = $this->exportLinksWithAGoneTopic();
        $other = Entry::find()->slug('other')->one();

        $result = $this->portage()->import($payload, [$goneUid => $other->id]);

        $links = $this->importedLinks($result->roots[0]->elementId);
        $this->assertSame([$other->id], $this->linkedIds($links[1]));
    }

    public function testAnAssetLinkCanBeFetchedAndPointsAtTheFetchedAsset(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files');
        $photo = Schema::asset($photos, 'photo.png', 'blog', ['title' => 'Photo']);
        $uid = $photo->uid;
        [$news, $article] = $this->newsWithLinks();
        $source = Schema::entry($news, $article, ['title' => 'Links', 'links' => [
            ['handle' => 'asset', 'linkValue' => [$photo->id], 'linkSiteId' => 1],
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($photo);

        $result = $this->portage()->import($payload, [$uid => 'fetch']);

        $fetched = Asset::find()->uid($uid)->one();
        $this->assertNotNull($fetched, 'No asset with the source UID');
        $this->assertSame([$fetched->id], $this->linkedIds($this->importedLinks($result->roots[0]->elementId)[0]));
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testLinkTypeCustomFieldValuesRoundTripIncludingRelations(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);
        $cms = Schema::entry($topics, $topic, ['title' => 'CMS', 'slug' => 'cms']);
        $field = Schema::hyper('links', [
            Schema::hyperLinkType(linkTypes\Url::class, 'url', [Schema::plainText('badge'), Schema::field(Entries::class, 'relatedTopics')]),
            Schema::hyperLinkType(linkTypes\Email::class, 'email'),
        ]);
        $article = Schema::entryType('article', [$field]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Custom', 'links' => [
            ['handle' => 'url', 'linkValue' => 'https://example.com', 'fields' => ['badge' => 'New', 'relatedTopics' => [$craft->id, $cms->id]]],
            ['handle' => 'url', 'linkValue' => 'https://example.org', 'fields' => ['badge' => 'Old']],
        ]]);
        $this->assertSame('New', $source->getFieldValue('links')->getLinks()[0]->getFieldValue('badge'));

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($craft);
        Schema::deleteElement($cms);
        $newCms = Schema::entry($topics, $topic, ['title' => 'CMS', 'slug' => 'cms']);
        $newCraft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);

        $result = $this->portage()->import($payload);

        $links = $this->importedLinks($result->roots[0]->elementId);
        $this->assertCount(2, $links);
        $this->assertSame('New', $links[0]->getFieldValue('badge'));
        $this->assertEqualsCanonicalizing([$newCraft->id, $newCms->id], $links[0]->getFieldValue('relatedTopics')->ids());
        $this->assertSame('Old', $links[1]->getFieldValue('badge'));
        $this->assertSame([], $links[1]->getFieldValue('relatedTopics')->ids());
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testARelationInsideALinkTypeCustomFieldCanBeStubbed(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone topic', 'slug' => 'gone-topic']);
        $goneUid = $gone->uid;
        $field = Schema::hyper('links', [
            Schema::hyperLinkType(linkTypes\Url::class, 'url', [Schema::field(Entries::class, 'relatedTopics')]),
        ]);
        $article = Schema::entryType('article', [$field]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Custom', 'links' => [
            ['handle' => 'url', 'linkValue' => 'https://example.com', 'fields' => ['relatedTopics' => [$gone->id]]],
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($gone);

        $result = $this->portage()->import($payload, [$goneUid => 'stub']);

        $stub = Entry::find()->uid($goneUid)->status(null)->one();
        $this->assertNotNull($stub, 'No Stub with the source UID');
        $links = $this->importedLinks($result->roots[0]->elementId);
        // A Stub is disabled.
        $this->assertSame([$stub->id], $links[0]->getFieldValue('relatedTopics')->status(null)->ids());
    }

    public function testALinkWhoseLinkTypeIsMissingInTheTargetFieldIsSkippedWithAWarning(): void
    {
        $field = Schema::hyper('links', [
            Schema::hyperLinkType(linkTypes\Url::class, 'url'),
            Schema::hyperLinkType(linkTypes\Email::class, 'email'),
        ]);
        $article = Schema::entryType('article', [$field]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Links', 'links' => [
            ['handle' => 'email', 'linkValue' => 'hello@example.com'],
            ['handle' => 'url', 'linkValue' => 'https://example.com'],
        ]]);

        $payload = $this->portage()->export([$source]);
        $emailKey = $this->linkTypeKey($field, 'email');
        Schema::deleteElement($source);
        Schema::removeHyperLinkType($field, 'email');

        $plan = $this->portage()->preview($payload);
        $result = $this->portage()->import($payload);

        $this->assertSame('missing', $plan->drift->get('hyperLinkTypes', $emailKey)?->state);
        $links = $this->importedLinks($result->roots[0]->elementId);
        $this->assertSame(['url'], array_map(fn($link) => $link->handle, $links));
        $this->assertContains(
            'The Hyper link type "Email" in the field "Links" doesn\'t exist in this environment. Links of that type will be skipped.',
            $result->roots[0]->warnings,
        );
        $this->assertContains(
            'A link of type "Email" in "Links" will be skipped: its link type doesn\'t exist in that field in this environment.',
            $result->roots[0]->warnings,
        );
    }

    public function testADriftedLinkTypeImportsItsLinksWithAWarning(): void
    {
        $field = Schema::hyper('links', [Schema::hyperLinkType(linkTypes\Url::class, 'url')]);
        $article = Schema::entryType('article', [$field]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Links', 'links' => [
            ['handle' => 'url', 'linkValue' => 'https://example.com'],
        ]]);

        $payload = $this->portage()->export([$source]);
        $key = $this->linkTypeKey($field, 'url');
        Schema::deleteElement($source);
        Schema::changeHyperLinkTypes($field, fn(array $linkTypes) => array_map(fn(array $config) => ['label' => 'Website'] + $config, $linkTypes));

        $plan = $this->portage()->preview($payload);
        $result = $this->portage()->import($payload);

        $this->assertSame('drifted', $plan->drift->get('hyperLinkTypes', $key)?->state);
        $this->assertSame('https://example.com', $this->importedLinks($result->roots[0]->elementId)[0]->linkValue);
        $this->assertSame(
            [
                'The field "Links" has different settings in this environment than where it was exported. Its values will be imported, but check them.',
                'The Hyper link type "Url" in the field "Links" has different settings in this environment than where it was exported. Its links will be imported, but check them.',
            ],
            $result->roots[0]->warnings,
        );
    }

    public function testALinkToAnAssetWhoseFetchFailsIsRemoved(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files');
        $gone = Schema::asset($photos, 'notes.txt', '', ['title' => 'Gone notes', 'filename' => 'gone.txt']);
        $uid = $gone->uid;
        [$news, $article] = $this->newsWithLinks();
        $source = Schema::entry($news, $article, ['title' => 'Links', 'links' => [
            ['handle' => 'url', 'linkValue' => 'https://example.com'],
            ['handle' => 'asset', 'linkValue' => [$gone->id], 'linkSiteId' => 1],
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($gone);

        $result = $this->portage()->import($payload, [$uid => 'fetch']);

        $this->assertSame(['url'], array_map(fn($link) => $link->handle, $this->importedLinks($result->roots[0]->elementId)));
        $this->assertSame(
            ['The asset "Gone notes" couldn\'t be fetched from ' . FileServer::start() . '/files/gone.txt: the file wasn\'t found at the source (HTTP 404 Not Found). Its relations and links to it were cleared.'],
            $result->roots[0]->warnings,
        );
    }

    public function testPhoneAndSiteLinksRoundTripAndASiteLinkToAMissingSiteIsRemoved(): void
    {
        $field = Schema::hyper('links', [
            Schema::hyperLinkType(linkTypes\Phone::class, 'phone'),
            Schema::hyperLinkType(linkTypes\Site::class, 'site'),
        ]);
        $article = Schema::entryType('article', [$field]);
        $news = Schema::section('news', [$article]);
        $french = Schema::site('french', ['language' => 'fr', 'baseUrl' => 'https://fr.example.com']);
        $source = Schema::entry($news, $article, ['title' => 'Links', 'links' => [
            ['handle' => 'phone', 'linkValue' => '+15551234567'],
            ['handle' => 'site', 'linkValue' => $french->uid],
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        $imported = $this->portage()->import($payload)->roots[0]->elementId;
        $links = $this->importedLinks($imported);
        $this->assertSame(['phone', 'site'], array_map(fn($link) => $link->handle, $links));
        $this->assertSame('+15551234567', $links[0]->linkValue);
        $this->assertSame($french->uid, $links[1]->linkValue);

        Schema::deleteElement(Entry::find()->id($imported)->status(null)->one());
        Schema::deleteSite($french);
        $result = $this->portage()->import($payload);

        $this->assertSame(['phone'], array_map(fn($link) => $link->handle, $this->importedLinks($result->roots[0]->elementId)));
        $this->assertContains('The site "French" of a link in the field "Links" doesn\'t exist in this environment. The link will be removed.', $result->roots[0]->warnings);
    }

    public function testALinkSiteThatIsMissingInTheTargetEnvironmentFallsBackToTheSiteTheLinkIsSavedIn(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);
        $french = Schema::site('french', ['language' => 'fr', 'baseUrl' => 'https://fr.example.com']);
        [$news, $article] = $this->newsWithLinks();
        $source = Schema::entry($news, $article, ['title' => 'Links', 'links' => [
            ['handle' => 'entry', 'linkValue' => [$craft->id], 'linkSiteId' => $french->id],
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteSite($french);
        $result = $this->portage()->import($payload);

        $links = $this->importedLinks($result->roots[0]->elementId);
        $this->assertSame([$craft->id], $this->linkedIds($links[0]));
        $this->assertNull($links[0]->linkSiteId);
        $this->assertContains('The site "French" of a link in the field "Links" doesn\'t exist in this environment. The link will use the site it is saved in.', $result->roots[0]->warnings);
    }

    public function testALinkToAnElementTypePortageDoesNotSupportIsSkippedWithAWarning(): void
    {
        Schema::withHyperLinkType(GlobalSetHyperLinkType::class, function(): void {
            $settings = Schema::globalSet('footer');
            $field = Schema::hyper('links', [
                Schema::hyperLinkType(linkTypes\Url::class, 'url'),
                Schema::hyperLinkType(GlobalSetHyperLinkType::class, 'globalSet'),
            ]);
            $article = Schema::entryType('article', [$field]);
            $news = Schema::section('news', [$article]);
            $source = Schema::entry($news, $article, ['title' => 'Links', 'links' => [
                ['handle' => 'globalSet', 'linkValue' => [$settings->id], 'linkSiteId' => 1],
                ['handle' => 'url', 'linkValue' => 'https://example.com'],
            ]]);

            $payload = $this->portage()->export([$source]);
            Schema::deleteElement($source);
            $result = $this->portage()->import($payload);

            $this->assertSame(['url'], array_map(fn($link) => $link->handle, $this->importedLinks($result->roots[0]->elementId)));
            $this->assertContains(
                'A Global Set link in the field "Links" points at a kind of element Portage can\'t move between environments. The link will be skipped.',
                $result->roots[0]->warnings,
            );
        });
    }

    public function testALinkTypeDisabledInTheTargetFieldIsSkippedWithAWarning(): void
    {
        [$news, $article] = $this->newsWithLinks();
        $field = $article->getFieldLayout()->getFieldByHandle('links');
        $source = Schema::entry($news, $article, ['title' => 'Links', 'links' => [
            ['handle' => 'email', 'linkValue' => 'hello@example.com'],
            ['handle' => 'url', 'linkValue' => 'https://example.com'],
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::changeHyperLinkTypes($field, fn(array $linkTypes) => array_map(fn(array $config) => $config['handle'] === 'email' ? ['enabled' => false] + $config : $config, $linkTypes));
        $result = $this->portage()->import($payload);

        $this->assertSame(['url'], array_map(fn($link) => $link->handle, $this->importedLinks($result->roots[0]->elementId)));
        $this->assertContains('The link type "Email" is disabled in the field "Links" in this environment. Its links will be skipped.', $result->roots[0]->warnings);
    }

    public function testALinkTypeWhoseTypeChangedIsSkippedWithAWarning(): void
    {
        [$news, $article] = $this->newsWithLinks();
        $field = $article->getFieldLayout()->getFieldByHandle('links');
        $source = Schema::entry($news, $article, ['title' => 'Links', 'links' => [
            ['handle' => 'email', 'linkValue' => 'hello@example.com'],
            ['handle' => 'url', 'linkValue' => 'https://example.com'],
        ]]);

        $payload = $this->portage()->export([$source]);
        $key = $this->linkTypeKey($field, 'email');
        Schema::deleteElement($source);
        Schema::changeHyperLinkTypes($field, fn(array $linkTypes) => array_map(fn(array $config) => $config['handle'] === 'email' ? ['type' => linkTypes\Phone::class] + $config : $config, $linkTypes));
        $plan = $this->portage()->preview($payload);
        $result = $this->portage()->import($payload);

        $this->assertSame('type-changed', $plan->drift->get('hyperLinkTypes', $key)?->state);
        $this->assertSame(['url'], array_map(fn($link) => $link->handle, $this->importedLinks($result->roots[0]->elementId)));
        $this->assertContains(
            'The Hyper link type "Email" in the field "Links" is a Phone link type in this environment but was a Email link type where it was exported. Its links will be skipped.',
            $result->roots[0]->warnings,
        );
    }

    public function testACustomFieldThatIsNoLongerInTheLinkTypesLayoutIsSkippedWithAWarning(): void
    {
        $field = Schema::hyper('links', [
            Schema::hyperLinkType(linkTypes\Url::class, 'url', [Schema::plainText('badge')]),
        ]);
        $article = Schema::entryType('article', [$field]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Links', 'links' => [
            ['handle' => 'url', 'linkValue' => 'https://example.com', 'fields' => ['badge' => 'New']],
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::changeHyperLinkTypes($field, function(array $linkTypes): array {
            foreach ($linkTypes as &$config) {
                foreach ($config['layoutConfig']['tabs'] as &$tab) {
                    $tab['elements'] = array_values(array_filter($tab['elements'], fn(array $element) => !isset($element['field']) && !isset($element['fieldUid'])));
                }
            }

            return $linkTypes;
        });
        $result = $this->portage()->import($payload);

        $links = $this->importedLinks($result->roots[0]->elementId);
        $this->assertSame('https://example.com', $links[0]->linkValue);
        $this->assertContains('The field "Badge" isn\'t in the "Url" link type\'s field layout in this environment. Its values will be skipped.', $result->roots[0]->warnings);
    }

    public function testAFieldThatHoldsNestedContentInALinkTypeIsSkippedWithAWarningWhileTheLinkImports(): void
    {
        $textBlock = Schema::blockType('textBlock', [Schema::plainText('body')]);
        $field = Schema::hyper('links', [
            Schema::hyperLinkType(linkTypes\Url::class, 'url', [Schema::plainText('badge'), Schema::matrix('items', [$textBlock])]),
        ]);
        $article = Schema::entryType('article', [$field]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Links', 'links' => [
            ['handle' => 'url', 'linkValue' => 'https://example.com', 'fields' => ['badge' => 'New']],
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        $result = $this->portage()->import($payload);

        $links = $this->importedLinks($result->roots[0]->elementId);
        $this->assertSame('https://example.com', $links[0]->linkValue);
        $this->assertSame('New', $links[0]->getFieldValue('badge'));
        $this->assertSame(
            ['The field "Items" in the "Url" link type holds nested content, which needs an element to belong to. Its values will be skipped.'],
            $result->roots[0]->warnings,
        );
    }

    public function testLinksInFieldsThatShareALinkTypeConfigRoundTrip(): void
    {
        Requires::package('verbb/hyper', '^3.0', 'Shared link type configs');
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);
        [$news, $article, $primary, $secondary] = $this->newsWithSharedLinkTypes();
        $source = Schema::entry($news, $article, ['title' => 'Shared', 'primaryLinks' => [
            ['handle' => 'url', 'linkValue' => 'https://example.com', 'linkText' => 'Example', 'newWindow' => true],
            ['handle' => 'entry', 'linkValue' => [$craft->id], 'linkSiteId' => 1, 'linkText' => 'Read'],
        ], 'secondaryLinks' => [
            ['handle' => 'url', 'linkValue' => 'https://example.org'],
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        $plan = $this->portage()->preview($payload);
        $result = $this->portage()->import($payload);

        $this->assertSame('matched', $plan->drift->get('hyperLinkTypes', $this->linkTypeKey($primary, 'url'))?->state);
        $this->assertSame('matched', $plan->drift->get('hyperLinkTypes', $this->linkTypeKey($primary, 'entry'))?->state);
        $this->assertSame('matched', $plan->drift->get('hyperLinkTypes', $this->linkTypeKey($secondary, 'url'))?->state);
        $links = $this->importedLinks($result->roots[0]->elementId, 'primaryLinks');
        $this->assertSame(['url', 'entry'], array_map(fn($link) => $link->handle, $links));
        $this->assertSame('https://example.com', $links[0]->linkValue);
        $this->assertSame('Example', $links[0]->linkText);
        $this->assertTrue($links[0]->newWindow);
        $this->assertSame([$craft->id], $this->linkedIds($links[1]));
        $this->assertSame('Read', $links[1]->linkText);
        $secondaryLinks = $this->importedLinks($result->roots[0]->elementId, 'secondaryLinks');
        $this->assertSame(['url'], array_map(fn($link) => $link->handle, $secondaryLinks));
        $this->assertSame('https://example.org', $secondaryLinks[0]->linkValue);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testALinkInAFieldOnTheDefaultLinkTypeConfigCanPointAtAStub(): void
    {
        Requires::package('verbb/hyper', '^3.0', 'Shared link type configs');
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone topic', 'slug' => 'gone-topic']);
        $goneUid = $gone->uid;
        $field = Schema::hyper('links', [], ['linkTypeConfig' => 'default', 'defaultLinkType' => 'url']);
        $article = Schema::entryType('article', [$field]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Default', 'links' => [
            ['handle' => 'entry', 'linkValue' => [$gone->id], 'linkSiteId' => 1],
            ['handle' => 'url', 'linkValue' => 'https://example.com'],
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($gone);
        $result = $this->portage()->import($payload, [$goneUid => 'stub']);

        $stub = Entry::find()->uid($goneUid)->status(null)->one();
        $this->assertNotNull($stub, 'No Stub with the source UID');
        $links = $this->importedLinks($result->roots[0]->elementId);
        $this->assertSame(['entry', 'url'], array_map(fn($link) => $link->handle, $links));
        $this->assertSame([$stub->id], $this->linkedIds($links[0]));
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testALinkTypeRemovedFromASharedLinkTypeConfigIsSkippedWithAWarning(): void
    {
        Requires::package('verbb/hyper', '^3.0', 'Shared link type configs');
        [$news, $article, $primary] = $this->newsWithSharedLinkTypes();
        $source = Schema::entry($news, $article, ['title' => 'Shared', 'primaryLinks' => [
            ['handle' => 'email', 'linkValue' => 'hello@example.com'],
            ['handle' => 'url', 'linkValue' => 'https://example.com'],
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::changeHyperLinkTypeConfig('navigation', fn(array $linkTypes) => array_filter($linkTypes, fn(array $config) => $config['handle'] !== 'email'));
        $plan = $this->portage()->preview($payload);
        $result = $this->portage()->import($payload);

        $this->assertSame('missing', $plan->drift->get('hyperLinkTypes', $this->linkTypeKey($primary, 'email'))?->state);
        $this->assertSame(['url'], array_map(fn($link) => $link->handle, $this->importedLinks($result->roots[0]->elementId, 'primaryLinks')));
        $this->assertSame(
            [
                'The Hyper link type "Email" in the field "Primary Links" doesn\'t exist in this environment. Links of that type will be skipped.',
                'A link of type "Email" in "Primary Links" will be skipped: its link type doesn\'t exist in that field in this environment.',
            ],
            $result->roots[0]->warnings,
        );
    }

    public function testADriftedSharedLinkTypeConfigWarnsForEachFieldThatUsesIt(): void
    {
        Requires::package('verbb/hyper', '^3.0', 'Shared link type configs');
        [$news, $article] = $this->newsWithSharedLinkTypes();
        $source = Schema::entry($news, $article, ['title' => 'Shared', 'primaryLinks' => [
            ['handle' => 'url', 'linkValue' => 'https://example.com'],
        ], 'secondaryLinks' => [
            ['handle' => 'url', 'linkValue' => 'https://example.org'],
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::changeHyperLinkTypeConfig('navigation', fn(array $linkTypes) => array_map(fn(array $config) => $config['handle'] === 'url' ? ['label' => 'Website'] + $config : $config, $linkTypes));
        $result = $this->portage()->import($payload);

        $this->assertSame('https://example.com', $this->importedLinks($result->roots[0]->elementId, 'primaryLinks')[0]->linkValue);
        $this->assertSame('https://example.org', $this->importedLinks($result->roots[0]->elementId, 'secondaryLinks')[0]->linkValue);
        $this->assertEqualsCanonicalizing(
            [
                'The Hyper link type "Url" in the field "Primary Links" has different settings in this environment than where it was exported. Its links will be imported, but check them.',
                'The Hyper link type "Url" in the field "Secondary Links" has different settings in this environment than where it was exported. Its links will be imported, but check them.',
            ],
            $result->roots[0]->warnings,
        );
    }

    /**
     * @return array{0: Section, 1: EntryType}
     */
    private function newsWithLinks(): array
    {
        $field = Schema::hyper('links', [
            Schema::hyperLinkType(linkTypes\Url::class, 'url'),
            Schema::hyperLinkType(linkTypes\Entry::class, 'entry'),
            Schema::hyperLinkType(linkTypes\Asset::class, 'asset'),
            Schema::hyperLinkType(linkTypes\Email::class, 'email'),
            Schema::hyperLinkType(linkTypes\Embed::class, 'embed'),
        ]);
        $article = Schema::entryType('article', [$field]);

        return [Schema::section('news', [$article]), $article];
    }

    /**
     * A section whose entry type has two Hyper fields on the shared link type config "navigation" (Hyper 3), which has
     * URL, entry and email link types.
     *
     * @return array{0: Section, 1: EntryType, 2: HyperField, 3: HyperField}
     */
    private function newsWithSharedLinkTypes(): array
    {
        $navigation = Schema::hyperLinkTypeConfig('navigation', [
            Schema::hyperLinkType(linkTypes\Url::class, 'url'),
            Schema::hyperLinkType(linkTypes\Entry::class, 'entry'),
            Schema::hyperLinkType(linkTypes\Email::class, 'email'),
        ]);
        $primary = Schema::hyper('primaryLinks', [], ['linkTypeConfig' => $navigation, 'defaultLinkType' => 'url']);
        $secondary = Schema::hyper('secondaryLinks', [], ['linkTypeConfig' => $navigation, 'defaultLinkType' => 'url']);
        $article = Schema::entryType('article', [$primary, $secondary]);

        return [Schema::section('news', [$article]), $article, $primary, $secondary];
    }

    /**
     * Exports an entry with a URL link, a link to a topic that is then deleted and an email link, with another topic to
     * replace it.
     *
     * @return array{0: string, 1: string} the Payload and the deleted topic's UID
     */
    private function exportLinksWithAGoneTopic(): array
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone topic', 'slug' => 'gone-topic']);
        $goneUid = $gone->uid;
        Schema::entry($topics, $topic, ['title' => 'Other', 'slug' => 'other']);
        [$news, $article] = $this->newsWithLinks();
        $source = Schema::entry($news, $article, ['title' => 'Links', 'links' => [
            ['handle' => 'url', 'linkValue' => 'https://example.com'],
            ['handle' => 'entry', 'linkValue' => [$gone->id], 'linkSiteId' => 1, 'linkText' => 'Read'],
            ['handle' => 'email', 'linkValue' => 'hello@example.com'],
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($gone);

        return [$payload, $goneUid];
    }

    /**
     * The key of a field's link type in the Drift report: the field's UID and the link type's handle.
     */
    private function linkTypeKey(HyperField $field, string $handle): string
    {
        return "$field->uid/$handle";
    }

    /**
     * The IDs an element link points at. Hyper 2.0 keeps a link's element ID bare, later versions as a list of one.
     *
     * @return list<mixed>
     */
    private function linkedIds(Link $link): array
    {
        return is_array($link->linkValue) ? $link->linkValue : [$link->linkValue];
    }

    /**
     * @return list<Link>
     */
    private function importedLinks(?int $id, string $field = 'links'): array
    {
        $this->assertNotNull($id, 'The Import result has no element ID');
        $entry = Entry::find()->id($id)->status(null)->one();
        $this->assertNotNull($entry, "No entry with ID $id");
        $value = $entry->getFieldValue($field);
        $this->assertInstanceOf(LinkCollection::class, $value);

        return array_values($value->getLinks());
    }

    private function portage(): Portage
    {
        return Plugin::getInstance()->getPortage();
    }
}
