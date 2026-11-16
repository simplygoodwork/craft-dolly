<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use ether\seo\fields\SeoField;
use ether\seo\models\data\SeoData;
use ether\seo\models\data\SocialData;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\tests\support\FileServer;
use simplygoodwork\craftportage\tests\support\Schema;

/**
 * Ether's SEO field: its values round-trip as the element stores them, and its social images are Embedded references.
 */
class EtherSeoTest extends Unit
{
    public function testRoundTripsTheValueAsTheElementStoresIt(): void
    {
        [$news, $article] = $this->newsWithSeo();
        $source = Schema::withEtherSeoSettings(['twitterHandle' => 'source-handle', 'robots' => ['noindex']], fn() => Schema::entry($news, $article, [
            'title' => 'Post',
            'seo' => [
                'titleRaw' => ['1' => 'Custom title'],
                'descriptionRaw' => 'Custom description',
                'keywords' => [['keyword' => 'portage', 'rating' => 'good']],
                'score' => 'good',
                'social' => [
                    'twitter' => ['title' => 'Tweet title', 'description' => 'Tweet description'],
                    'facebook' => ['title' => 'Facebook title', 'description' => 'Facebook description'],
                ],
                'advanced' => ['robots' => ['nofollow', 'noarchive'], 'canonical' => 'https://source.example.com/post'],
            ],
        ]));

        $payload = Schema::withEtherSeoSettings(['twitterHandle' => 'source-handle'], fn() => $this->portage()->export([$source]));
        // The handles are the Source environment's settings, not the element's.
        $this->assertStringNotContainsString('source-handle', json_encode($this->portage()->preview($payload)->envelope));
        Schema::deleteElement($source);

        $result = Schema::withEtherSeoSettings(['twitterHandle' => 'target-handle', 'robots' => ['noindex']], fn() => $this->portage()->import($payload));

        $seo = $this->importedSeo($result->roots[0]->elementId);
        $this->assertSame(['1' => 'Custom title'], $seo->titleRaw);
        $this->assertSame('Custom description', $seo->descriptionRaw);
        $this->assertSame([['keyword' => 'portage', 'rating' => 'good']], $seo->keywords);
        $this->assertSame('good', $seo->score);
        $this->assertSame('Tweet title', $this->social($seo, 'twitter')->title);
        $this->assertSame('Tweet description', $this->social($seo, 'twitter')->description);
        $this->assertSame('Facebook title', $this->social($seo, 'facebook')->title);
        $this->assertSame('Facebook description', $this->social($seo, 'facebook')->description);
        $this->assertSame(['nofollow', 'noarchive'], $seo->advanced['robots']);
        $this->assertSame('https://source.example.com/post', $seo->advanced['canonical']);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testRobotsTheElementDoesntStoreAreFilledWithTheTargetEnvironmentsDefaults(): void
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Saved before the field existed']);
        $article = Schema::addFieldToEntryType($article, Schema::field(SeoField::class, 'seo', ['name' => 'SEO']));
        $source = Entry::find()->id($source->id)->status(null)->one();
        $this->assertNotNull($source);

        $payload = Schema::withEtherSeoSettings(['robots' => ['noindex', 'nofollow']], fn() => $this->portage()->export([$source]));
        $this->assertStringNotContainsString('nofollow', json_encode($this->portage()->preview($payload)->envelope['roots'][0]['fields']));
        Schema::deleteElement($source);

        $result = Schema::withEtherSeoSettings(['robots' => ['noarchive']], fn() => $this->portage()->import($payload));

        $this->assertSame(['noarchive'], $this->importedSeo($result->roots[0]->elementId)->advanced['robots']);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testSocialImagesPointAtTheAssetsTheyResolveToInThisEnvironment(): void
    {
        $photos = Schema::volume('photos', 'https://example.com/files');
        $photo = Schema::asset($photos, 'photo.png', 'blog');
        $diagram = Schema::asset($photos, 'diagram.png', 'blog');
        [$news, $article] = $this->newsWithSeo();
        // Ether has stored the image as an ID, and as a list of `{id}`.
        $source = Schema::entry($news, $article, ['title' => 'Post', 'seo' => ['social' => [
            'twitter' => ['imageId' => (string)$photo->id],
            'facebook' => ['imageId' => [['id' => $diagram->id]]],
        ]]]);

        $payload = $this->portage()->export([$source]);
        $fields = $this->portage()->preview($payload)->envelope['roots'][0]['fields'];
        $exported = json_encode($fields);
        $this->assertStringContainsString('"image":"' . $photo->uid . '"', $exported);
        $this->assertStringContainsString('"image":"' . $diagram->uid . '"', $exported);
        Schema::deleteElement($source);
        Schema::deleteElement($photo);
        $newPhoto = Schema::asset($photos, 'photo.png', 'blog');

        $result = $this->portage()->import($payload);

        $seo = $this->importedSeo($result->roots[0]->elementId);
        $this->assertSame($newPhoto->id, $this->social($seo, 'twitter')->imageId);
        $this->assertSame($diagram->id, $this->social($seo, 'facebook')->imageId);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testAnUnresolvedImageCanBeFetched(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files');
        $photo = Schema::asset($photos, 'photo.png', 'blog', ['title' => 'Photo']);
        $uid = $photo->uid;
        [$news, $article] = $this->newsWithSeo();
        $source = Schema::entry($news, $article, ['title' => 'Post', 'seo' => ['social' => ['twitter' => ['imageId' => $photo->id]]]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($photo);

        $result = $this->portage()->import($payload, [$uid => 'fetch']);

        $fetched = Asset::find()->uid($uid)->one();
        $this->assertNotNull($fetched, 'No asset with the source UID');
        $this->assertSame($fetched->id, $this->social($this->importedSeo($result->roots[0]->elementId), 'twitter')->imageId);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testAnUnresolvedImageThatIsntFetchedIsClearedWithAWarning(): void
    {
        $photos = Schema::volume('photos', 'https://example.com/files');
        $photo = Schema::asset($photos, 'photo.png', 'blog', ['title' => 'Photo']);
        [$news, $article] = $this->newsWithSeo();
        $source = Schema::entry($news, $article, ['title' => 'Post', 'seo' => [
            'descriptionRaw' => 'Kept',
            'social' => ['facebook' => ['title' => 'Kept title', 'imageId' => $photo->id]],
        ]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($photo);

        $result = $this->portage()->import($payload);

        $seo = $this->importedSeo($result->roots[0]->elementId);
        $this->assertSame('Kept', $seo->descriptionRaw);
        $this->assertSame('Kept title', $this->social($seo, 'facebook')->title);
        $this->assertNull($this->social($seo, 'facebook')->imageId);
        $this->assertSame(
            ['The asset "Photo" used as the Facebook image in the field "SEO" doesn\'t exist in this environment. The image will be cleared.'],
            $result->roots[0]->warnings,
        );
    }

    public function testAnImageWhoseFetchFailsIsCleared(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files');
        $gone = Schema::asset($photos, 'photo.png', '', ['title' => 'Gone photo', 'filename' => 'gone.png']);
        $uid = $gone->uid;
        [$news, $article] = $this->newsWithSeo();
        $source = Schema::entry($news, $article, ['title' => 'Post', 'seo' => ['social' => ['twitter' => ['title' => 'Kept title', 'imageId' => $gone->id]]]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($gone);

        $result = $this->portage()->import($payload, [$uid => 'fetch']);

        $seo = $this->importedSeo($result->roots[0]->elementId);
        $this->assertSame('Kept title', $this->social($seo, 'twitter')->title);
        $this->assertNull($this->social($seo, 'twitter')->imageId);
        $this->assertSame(
            ['The asset "Gone photo" couldn\'t be fetched from ' . FileServer::start() . '/files/gone.png: the file wasn\'t found at the source (HTTP 404 Not Found). Its relations and links to it were cleared.'],
            $result->roots[0]->warnings,
        );
    }

    public function testOnlyImagesCanBeUsedAsSocialImages(): void
    {
        $files = Schema::volume('files', 'https://example.com/files');
        $notes = Schema::asset($files, 'notes.txt', '', ['title' => 'Notes']);
        [$news, $article] = $this->newsWithSeo();
        $source = Schema::entry($news, $article, ['title' => 'Post', 'seo' => ['social' => ['twitter' => ['imageId' => $notes->id]]]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);

        $result = $this->portage()->import($payload);

        $this->assertNull($this->social($this->importedSeo($result->roots[0]->elementId), 'twitter')->imageId);
        $this->assertSame(
            ['The asset "Notes" used as the Twitter image in the field "SEO" isn\'t an image. The image will be cleared.'],
            $result->roots[0]->warnings,
        );
    }

    /**
     * @return array{0: Section, 1: EntryType}
     */
    private function newsWithSeo(): array
    {
        $article = Schema::entryType('article', [Schema::field(SeoField::class, 'seo', ['name' => 'SEO'])]);

        return [Schema::section('news', [$article]), $article];
    }

    private function importedSeo(?int $id): SeoData
    {
        $this->assertNotNull($id, 'The Import result has no element ID');
        $entry = Entry::find()->id($id)->status(null)->one();
        $this->assertNotNull($entry, "No entry with ID $id");
        $value = $entry->getFieldValue('seo');
        $this->assertInstanceOf(SeoData::class, $value);

        return $value;
    }

    private function social(SeoData $seo, string $network): SocialData
    {
        $social = $seo->social[$network] ?? null;
        $this->assertInstanceOf(SocialData::class, $social);

        return $social;
    }

    private function portage(): Portage
    {
        return Plugin::getInstance()->getPortage();
    }
}
