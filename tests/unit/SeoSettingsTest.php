<?php

namespace simplygoodwork\craftdolly\tests\unit;

use Codeception\Test\Unit;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Volume;
use nystudio107\seomatic\fields\SeoSettings;
use nystudio107\seomatic\models\MetaBundle;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\services\Dolly;
use simplygoodwork\craftdolly\tests\support\FileServer;
use simplygoodwork\craftdolly\tests\support\Schema;

/**
 * The SEOmatic SEO Settings adapter: the overrides stored in the field round-trip verbatim, and the SEO images are
 * Embedded references.
 */
class SeoSettingsTest extends Unit
{
    public function testRoundTripsTextOverridesAndKeepsTwigVerbatim(): void
    {
        [$news, $article] = $this->newsWithSeo();
        $source = Schema::entry($news, $article, ['title' => 'Post', 'seo' => [
            'metaGlobalVars' => [
                'seoTitle' => '{{ object.title }} | {{ seomatic.site.siteName }}',
                'seoDescription' => '{{ object.summary|striptags|truncate(160) }}',
                'ogDescription' => '{title} – {{ siteName }}',
                'robots' => 'noindex, nofollow',
                'canonicalUrl' => '{{ object.url }}?ref=dolly',
                'ogTitle' => '{{ object.title|upper }} | {{ seomatic.site.siteName }}',
                'twitterCard' => 'summary_large_image',
            ],
            'metaSiteVars' => ['siteName' => 'Overridden site'],
            'metaBundleSettings' => ['seoTitleSource' => 'fromCustom', 'ogTitleSource' => 'fromCustom'],
        ]]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        $result = $this->dolly()->import($payload);

        $bundle = $this->importedBundle($result->roots[0]->elementId);
        $this->assertSame('{{ object.title }} | {{ seomatic.site.siteName }}', $bundle->metaGlobalVars->seoTitle);
        $this->assertSame('{{ object.summary|striptags|truncate(160) }}', $bundle->metaGlobalVars->seoDescription);
        $this->assertSame('{title} – {{ siteName }}', $bundle->metaGlobalVars->ogDescription);
        $this->assertSame('noindex, nofollow', $bundle->metaGlobalVars->robots);
        $this->assertSame('{{ object.url }}?ref=dolly', $bundle->metaGlobalVars->canonicalUrl);
        $this->assertSame('{{ object.title|upper }} | {{ seomatic.site.siteName }}', $bundle->metaGlobalVars->ogTitle);
        $this->assertSame('summary_large_image', $bundle->metaGlobalVars->twitterCard);
        $this->assertSame('Overridden site', $bundle->metaSiteVars->siteName);
        $this->assertSame('fromCustom', $bundle->metaBundleSettings->ogTitleSource);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testImagesPointAtTheAssetsTheyResolveToInThisEnvironment(): void
    {
        $photos = Schema::volume('photos', 'https://example.com/files');
        $photo = Schema::asset($photos, 'photo.png', 'blog');
        $diagram = Schema::asset($photos, 'diagram.png', 'blog');
        [$news, $article] = $this->newsWithSeo();
        $source = Schema::entry($news, $article, ['title' => 'Post', 'seo' => [
            'metaBundleSettings' => [
                'seoImageSource' => 'fromAsset',
                'seoImageIds' => [$photo->id],
                'twitterImageSource' => 'fromAsset',
                'twitterImageIds' => [$diagram->id],
            ],
        ]]);
        // The payload carries References, never the source IDs.
        $exported = json_encode($this->dolly()->preview($this->dolly()->export([$source]))->envelope['roots'][0]['fields']);
        $this->assertStringContainsString('"seoImageIds":["' . $photo->uid . '"]', $exported);
        // Nor the Twig SEOmatic derived from them, which holds source IDs.
        $this->assertStringNotContainsString('socialTransform(' . $photo->id, $exported);
        $this->assertStringNotContainsString('socialTransform(' . $diagram->id, $exported);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        $newPhoto = $this->replaceAsset($photo, $photos, 'photo.png');

        $result = $this->dolly()->import($payload);

        $bundle = $this->importedBundle($result->roots[0]->elementId);
        $this->assertSame([$newPhoto->id], $bundle->metaBundleSettings->seoImageIds);
        $this->assertStringContainsString('socialTransform(' . $newPhoto->id . ',', $bundle->metaGlobalVars->seoImage);
        $this->assertSame([$diagram->id], $bundle->metaBundleSettings->twitterImageIds);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testAnUnresolvedImageCanBeFetched(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files');
        $photo = Schema::asset($photos, 'photo.png', 'blog', ['title' => 'Photo']);
        $uid = $photo->uid;
        [$news, $article] = $this->newsWithSeo();
        $source = Schema::entry($news, $article, ['title' => 'Post', 'seo' => [
            'metaBundleSettings' => ['seoImageSource' => 'fromAsset', 'seoImageIds' => [$photo->id]],
        ]]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($photo);

        $result = $this->dolly()->import($payload, [$uid => 'fetch']);

        $fetched = Asset::find()->uid($uid)->one();
        $this->assertNotNull($fetched, 'No asset with the source UID');
        $bundle = $this->importedBundle($result->roots[0]->elementId);
        $this->assertSame([$fetched->id], $bundle->metaBundleSettings->seoImageIds);
        $this->assertStringContainsString('socialTransform(' . $fetched->id . ',', $bundle->metaGlobalVars->seoImage);
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testAnUnresolvedImageThatIsntFetchedIsClearedWithAWarning(): void
    {
        $photos = Schema::volume('photos', 'https://example.com/files');
        $photo = Schema::asset($photos, 'photo.png', 'blog', ['title' => 'Photo']);
        [$news, $article] = $this->newsWithSeo();
        $source = Schema::entry($news, $article, ['title' => 'Post', 'seo' => [
            'metaGlobalVars' => ['seoTitle' => 'Kept'],
            'metaBundleSettings' => ['seoImageSource' => 'fromAsset', 'seoImageIds' => [$photo->id]],
        ]]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($photo);

        $result = $this->dolly()->import($payload);

        $bundle = $this->importedBundle($result->roots[0]->elementId);
        $this->assertSame('Kept', $bundle->metaGlobalVars->seoTitle);
        $this->assertSame([], $bundle->metaBundleSettings->seoImageIds);
        $this->assertSame('', $bundle->metaGlobalVars->seoImage);
        $this->assertCount(1, $result->roots[0]->warnings);
        $this->assertStringContainsString('"SEO"', $result->roots[0]->warnings[0]);
        $this->assertStringContainsString('The image will be cleared.', $result->roots[0]->warnings[0]);
    }

    public function testOnlyImagesCanBeUsedAsSeoImages(): void
    {
        $files = Schema::volume('files', FileServer::start() . '/files');
        $notes = Schema::asset($files, 'notes.txt', '', ['title' => 'Notes']);
        $matched = Schema::asset($files, 'diagram.png', '', ['title' => 'Diagram']);
        $matchedText = Schema::asset($files, 'notes.txt', 'more', ['title' => 'More notes', 'filename' => 'more.txt']);
        $notesUid = $notes->uid;
        [$news, $article] = $this->newsWithSeo();
        $source = Schema::entry($news, $article, ['title' => 'Post', 'seo' => [
            'metaBundleSettings' => [
                'seoImageSource' => 'fromAsset',
                'seoImageIds' => [$notes->id, $matched->id, $matchedText->id],
            ],
        ]]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($notes);

        $result = $this->dolly()->import($payload, [$notesUid => 'fetch']);

        $bundle = $this->importedBundle($result->roots[0]->elementId);
        $this->assertSame([$matched->id], $bundle->metaBundleSettings->seoImageIds);
        $this->assertNull(Asset::find()->uid($notesUid)->one(), 'The text file was fetched');
        $this->assertSame([
            'The asset "Notes" is a file of the kind "Text", which the field "SEO" doesn\'t allow. It won\'t be related there.',
            'The asset "Notes" used as an SEO image in the field "SEO" doesn\'t exist in this environment. The image will be cleared.',
            'The asset "More notes" used as an SEO image in the field "SEO" isn\'t an image. The image will be cleared.',
        ], $result->roots[0]->warnings);
    }

    public function testASettingThatPullsFromAFieldTheElementDoesntHaveWarns(): void
    {
        [$news, $article] = $this->newsWithSeo([Schema::plainText('summary')]);
        $source = Schema::entry($news, $article, ['title' => 'Post', 'seo' => [
            'metaBundleSettings' => [
                'seoTitleSource' => 'fromField',
                'seoTitleField' => 'summary',
                'seoDescriptionSource' => 'summaryFromField',
                'seoDescriptionField' => 'body',
                'ogTitleSource' => 'fromCustom',
                'ogTitleField' => 'oldField',
            ],
        ]]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        $result = $this->dolly()->import($payload);

        $this->assertSame(
            ['The SEO setting "seoDescriptionField" in the field "SEO" pulls from the field "body", which this element doesn\'t have in this environment. It will pull nothing.'],
            $result->roots[0]->warnings,
        );
        $bundle = $this->importedBundle($result->roots[0]->elementId);
        $this->assertSame('summary', $bundle->metaBundleSettings->seoTitleField);
    }

    public function testAnImageWhoseFetchFailsIsCleared(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files');
        $gone = Schema::asset($photos, 'photo.png', '', ['title' => 'Gone photo', 'filename' => 'gone.png']);
        $uid = $gone->uid;
        [$news, $article] = $this->newsWithSeo();
        $source = Schema::entry($news, $article, ['title' => 'Post', 'seo' => [
            'metaGlobalVars' => ['seoTitle' => 'Kept'],
            'metaBundleSettings' => ['seoImageSource' => 'fromAsset', 'seoImageIds' => [$gone->id]],
        ]]);

        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($gone);

        $result = $this->dolly()->import($payload, [$uid => 'fetch']);

        $bundle = $this->importedBundle($result->roots[0]->elementId);
        $this->assertSame('Kept', $bundle->metaGlobalVars->seoTitle);
        $this->assertSame([], $bundle->metaBundleSettings->seoImageIds);
        $this->assertSame('', $bundle->metaGlobalVars->seoImage);
        $this->assertSame(
            ['The asset "Gone photo" couldn\'t be fetched from ' . FileServer::start() . '/files/gone.png: the file wasn\'t found at the source (HTTP 404 Not Found). Its relations and links to it were cleared.'],
            $result->roots[0]->warnings,
        );
    }

    public function testSectionLevelMetaBundlesAreNeverReadOrWritten(): void
    {
        [$news, $article] = $this->newsWithSeo();
        $before = Schema::seomaticSectionBundle($news, ['seoTitle' => 'SECTION-SEO-MARKER']);
        $source = Schema::entry($news, $article, ['title' => 'Post', 'seo' => ['metaGlobalVars' => ['seoTitle' => 'Entry title']]]);

        $payload = $this->dolly()->export([$source]);
        $this->assertStringNotContainsString('SECTION-SEO-MARKER', json_encode($this->dolly()->preview($payload)->envelope));
        Schema::deleteElement($source);
        $this->dolly()->import($payload);

        // SEOmatic itself touches the bundle's timestamps and fills in its meta global vars when an entry saves, but
        // nothing else about it changes, and the override it holds is still there.
        $after = Schema::seomaticSectionBundleRows($news);
        $this->assertCount(1, $after);
        $this->assertSame($this->withoutTimestamps($before), $this->withoutTimestamps($after[0]));
        $this->assertSame('SECTION-SEO-MARKER', json_decode($after[0]['metaGlobalVars'], true)['seoTitle']);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withoutTimestamps(array $row): array
    {
        return array_diff_key($row, array_flip(['dateCreated', 'dateUpdated', 'sourceDateUpdated', 'uid', 'metaGlobalVars']));
    }

    private function replaceAsset(Asset $old, Volume $volume, string $fixture): Asset
    {
        Schema::deleteElement($old);

        return Schema::asset($volume, $fixture, 'blog');
    }

    /**
     * @param FieldInterface[] $otherFields
     * @return array{0: Section, 1: EntryType}
     */
    private function newsWithSeo(array $otherFields = []): array
    {
        $field = Schema::field(SeoSettings::class, 'seo', ['name' => 'SEO', 'twitterTabEnabled' => true, 'facebookTabEnabled' => true]);
        $article = Schema::entryType('article', [$field, ...$otherFields]);

        return [Schema::section('news', [$article]), $article];
    }

    private function importedBundle(?int $id): MetaBundle
    {
        $this->assertNotNull($id, 'The Import result has no element ID');
        $entry = Entry::find()->id($id)->status(null)->one();
        $this->assertNotNull($entry, "No entry with ID $id");
        $value = $entry->getFieldValue('seo');
        $this->assertInstanceOf(MetaBundle::class, $value);

        return $value;
    }

    private function dolly(): Dolly
    {
        return Plugin::getInstance()->getDolly();
    }
}
