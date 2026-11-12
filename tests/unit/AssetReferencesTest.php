<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\fields\Assets;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\tests\support\FileServer;
use simplygoodwork\craftportage\tests\support\Schema;
use yii\base\Event;

/**
 * Asset References: matching by UID and by volume, folder path and filename, the metadata they carry, and fetching an
 * unresolved asset's file from its source URL. Files are served by a local file server, never the internet.
 */
class AssetReferencesTest extends Unit
{
    public function testAnAssetWithADifferentUidResolvesByVolumeFolderPathAndFilename(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files');
        $photo = Schema::asset($photos, 'photo.png', 'blog/2026');
        $other = Schema::asset($photos, 'photo.png', 'blog/2025');
        $sourceUid = $photo->uid;

        [$news, $article] = $this->newsWithImages();
        $source = Schema::entry($news, $article, ['title' => 'Pictured', 'images' => [$photo->id]]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        Schema::changeUid($photo);
        Schema::changeUid($other);

        $plan = $this->portage()->preview($payload);
        $this->assertSame('naturalKey', $plan->references[$sourceUid]->resolution->rule);
        $this->assertSame($photo->id, $plan->references[$sourceUid]->resolution->elementId);

        $result = $this->portage()->import($payload);
        $this->assertSame([], $result->roots[0]->unresolvedReferences);
        $this->assertSame([$photo->id], $this->importedEntry($result->roots[0]->elementId)->getFieldValue('images')->ids());
    }

    public function testAnUnresolvedAssetCanBeFetchedFromItsSourceUrlWithItsMetadata(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files', [Schema::plainText('credit')]);
        $photo = Schema::asset($photos, 'photo.png', 'blog/2026', [
            'title' => 'Red square',
            'alt' => 'A red square',
            'focalPoint' => ['x' => 0.25, 'y' => 0.75],
            'credit' => 'Sam',
        ]);
        $sourceUid = $photo->uid;

        [$news, $article] = $this->newsWithImages();
        $source = Schema::entry($news, $article, ['title' => 'Pictured', 'images' => [$photo->id]]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        $this->hardDelete($photo);

        $plan = $this->portage()->preview($payload);
        $this->assertSame('skip', $plan->references[$sourceUid]->choice);
        $this->assertSame(['skip', 'fetch', 'replace'], $plan->references[$sourceUid]->availableChoices);
        $this->assertSame(
            'Unresolved: will be fetched from ' . FileServer::start() . '/files/blog/2026/photo.png into the folder "blog/2026/" of the "Photos" volume',
            $this->portage()->preview($payload, [$sourceUid => 'fetch'])->references[$sourceUid]->outcome(),
        );
        $this->assertNull(Asset::find()->uid($sourceUid)->one(), 'The Preview fetched the asset');

        $result = $this->portage()->import($payload, [$sourceUid => 'fetch']);

        $fetched = Asset::find()->uid($sourceUid)->one();
        $this->assertNotNull($fetched, 'No asset with the source UID');
        $this->assertSame(['photos', 'blog/2026/', 'photo.png'], [$fetched->getVolume()->handle, $fetched->getFolder()->path, $fetched->getFilename()]);
        $this->assertSame(['Red square', 'A red square', 'Sam'], [$fetched->title, $fetched->alt, $fetched->getFieldValue('credit')]);
        $this->assertSame(['x' => 0.25, 'y' => 0.75], $fetched->getFocalPoint());
        $this->assertSame([8, 6], [$fetched->width, $fetched->height]);
        $this->assertSame([$fetched->id], $this->importedEntry($result->roots[0]->elementId)->getFieldValue('images')->ids());
        $this->assertSame([], $result->roots[0]->unresolvedReferences);
        $this->assertSame([
            ['uid' => $sourceUid, 'title' => 'Red square', 'elementId' => $fetched->id],
        ], $result->roots[0]->fetchedAssets);
    }

    public function testAFailedFetchIsReportedWithTheReasonAndOnlyItsRelationIsSkipped(): void
    {
        $server = FileServer::start();
        $photos = Schema::volume('photos', "$server/files");
        $staging = Schema::volume('staging', "$server/private");
        $photo = Schema::asset($photos, 'photo.png', '', ['title' => 'Photo']);
        $gone = Schema::asset($photos, 'notes.txt', '', ['title' => 'Gone notes', 'filename' => 'gone.txt']);
        $private = Schema::asset($staging, 'diagram.png', '', ['title' => 'Private diagram']);
        [$photoUid, $goneUid, $privateUid] = [$photo->uid, $gone->uid, $private->uid];

        $article = Schema::entryType('article', [Schema::plainText('summary'), Schema::field(Assets::class, 'images')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, [
            'title' => 'Partly pictured',
            'summary' => 'Still imported',
            'images' => [$photo->id, $gone->id, $private->id],
        ]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        $this->hardDelete($photo);
        $this->hardDelete($gone);
        $this->hardDelete($private);

        $result = $this->portage()->import($payload, [$photoUid => 'fetch', $goneUid => 'fetch', $privateUid => 'fetch']);

        $root = $result->roots[0];
        $this->assertSame('created', $root->status, implode(' ', $root->errors));
        $imported = $this->importedEntry($root->elementId);
        $this->assertSame('Still imported', $imported->getFieldValue('summary'));
        $fetched = Asset::find()->uid($photoUid)->one();
        $this->assertNotNull($fetched);
        $this->assertSame([$fetched->id], $imported->getFieldValue('images')->ids());
        $this->assertNull(Asset::find()->uid($goneUid)->one());
        $this->assertNull(Asset::find()->uid($privateUid)->one());
        $this->assertSame([
            ['uid' => $goneUid, 'title' => 'Gone notes', 'url' => "$server/files/gone.txt", 'reason' => 'the file wasn\'t found at the source (HTTP 404 Not Found)'],
            [
                'uid' => $privateUid,
                'title' => 'Private diagram',
                'url' => "$server/private/diagram.png",
                'reason' => 'the source refused access (HTTP 401 Unauthorized). It may be behind basic auth, or the volume may be private',
            ],
        ], $root->fetchFailures);
        $this->assertSame([
            "The asset \"Gone notes\" couldn't be fetched from $server/files/gone.txt: the file wasn't found at the source (HTTP 404 Not Found). Its relations were skipped.",
            "The asset \"Private diagram\" couldn't be fetched from $server/private/diagram.png: the source refused access (HTTP 401 Unauthorized). It may be behind basic auth, or the volume may be private. Its relations were skipped.",
        ], $root->warnings);
        $this->assertSame([['uid' => $photoUid, 'title' => 'Photo', 'elementId' => $fetched->id]], $root->fetchedAssets);
    }

    public function testAnAssetInAVolumeWithoutPublicUrlsCanNotBeFetchedAndThePreviewSaysWhy(): void
    {
        $private = Schema::volume('private');
        $contract = Schema::asset($private, 'notes.txt', '', ['title' => 'Contract']);
        $uid = $contract->uid;

        [$news, $article] = $this->newsWithImages();
        $source = Schema::entry($news, $article, ['title' => 'Signed', 'images' => [$contract->id]]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        $this->hardDelete($contract);

        $reference = $this->portage()->preview($payload)->references[$uid];
        $this->assertSame(['skip', 'replace'], $reference->availableChoices);
        $this->assertSame(
            'Unresolved: will be skipped. It can\'t be fetched: its volume has no public URL in the Source environment.',
            $reference->outcome(),
        );
        $this->assertSame(
            'The asset "Contract" can\'t be fetched: its volume has no public URL in the Source environment. Skip it or choose a replacement.',
            $this->portage()->import($payload, [$uid => 'fetch'])->refusal,
        );
        $this->assertSame(
            'The asset "Contract" can\'t be Stubbed: Portage never Stubs assets. Skip it or choose a replacement.',
            $this->portage()->preview($payload, [$uid => 'stub'])->refusal,
        );
    }

    public function testAFetchIsLimitedToCraftsMaximumUploadSize(): void
    {
        $server = FileServer::start();
        $photos = Schema::volume('photos', "$server/files");
        $photo = Schema::asset($photos, 'photo.png', '', ['title' => 'Photo']);
        $uid = $photo->uid;

        [$news, $article] = $this->newsWithImages();
        $source = Schema::entry($news, $article, ['title' => 'Too big', 'images' => [$photo->id]]);
        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        $this->hardDelete($photo);

        $result = Schema::withGeneralConfig(['maxUploadFileSize' => 50], fn() => $this->portage()->import($payload, [$uid => 'fetch']));

        $this->assertSame('created', $result->roots[0]->status);
        $this->assertSame('the file is larger than the 50 bytes limit', $result->roots[0]->fetchFailures[0]['reason'] ?? null);
        $this->assertNull(Asset::find()->uid($uid)->one());
    }

    public function testAFetchGivesUpAfterTheFetchTimeout(): void
    {
        $server = FileServer::start();
        $slow = Schema::volume('slow', "$server/slow");
        $photo = Schema::asset($slow, 'photo.png', '', ['title' => 'Photo']);
        $uid = $photo->uid;

        [$news, $article] = $this->newsWithImages();
        $source = Schema::entry($news, $article, ['title' => 'Slow', 'images' => [$photo->id]]);
        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        $this->hardDelete($photo);

        $result = Schema::withPortageSettings(['fetchTimeout' => 2], fn() => $this->portage()->import($payload, [$uid => 'fetch']));

        $this->assertSame('created', $result->roots[0]->status);
        $this->assertSame('the source didn\'t respond within 2 seconds', $result->roots[0]->fetchFailures[0]['reason'] ?? null);
    }

    public function testAFileOfAKindThisEnvironmentDoesNotAllowCanNotBeFetched(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files');
        $notes = Schema::asset($photos, 'notes.txt', '', ['title' => 'Notes']);
        $uid = $notes->uid;

        [$news, $article] = $this->newsWithImages();
        $source = Schema::entry($news, $article, ['title' => 'Annotated', 'images' => [$notes->id]]);
        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        $this->hardDelete($notes);

        $plan = Schema::withGeneralConfig(['allowedFileExtensions' => ['png', 'jpg']], fn() => $this->portage()->preview($payload));

        $this->assertSame(['skip', 'replace'], $plan->references[$uid]->availableChoices);
        $this->assertSame('Unresolved: will be skipped. It can\'t be fetched: this environment doesn\'t allow ".txt" files.', $plan->references[$uid]->outcome());
    }

    public function testAFetchedAssetIsNotRelatedInAnAssetsFieldThatDoesNotAllowItsKind(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files');
        $notes = Schema::asset($photos, 'notes.txt', '', ['title' => 'Notes']);
        $uid = $notes->uid;

        [$news, $article] = $this->newsWithImages();
        $images = Craft::$app->getFields()->getFieldByHandle('images');
        $source = Schema::entry($news, $article, ['title' => 'Annotated', 'images' => [$notes->id]]);
        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        $this->hardDelete($notes);
        Schema::updateField($images->uid, ['restrictFiles' => true, 'allowedKinds' => ['image']]);

        $result = $this->portage()->import($payload, [$uid => 'fetch']);

        $this->assertSame([], $this->importedEntry($result->roots[0]->elementId)->getFieldValue('images')->ids());
        $this->assertContains(
            'The asset "Notes" is a file of the kind "Text", which the field "Images" doesn\'t allow. It won\'t be related there.',
            $result->roots[0]->warnings,
        );
        $this->assertNull(Asset::find()->uid($uid)->one(), 'An asset no field relates to was fetched');
    }

    public function testAnAssetWhoseVolumeIsMissingCanBeFetchedIntoAChosenVolumeAndFolder(): void
    {
        $server = FileServer::start();
        $photos = Schema::volume('photos', "$server/files");
        $archive = Schema::volume('archive');
        $photo = Schema::asset($photos, 'photo.png', 'blog', ['title' => 'Photo']);
        $uid = $photo->uid;

        [$news, $article] = $this->newsWithImages();
        $source = Schema::entry($news, $article, ['title' => 'Moved', 'images' => [$photo->id]]);
        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        $this->hardDelete($photo);
        Schema::deleteVolume($photos);

        $this->assertSame(
            'The asset "Photo" can\'t be fetched into its own volume: it doesn\'t exist in this environment. Choose a volume to fetch it into.',
            $this->portage()->preview($payload, [$uid => 'fetch'])->refusal,
        );
        $this->assertSame(
            'The volume "nowhere" chosen for the asset "Photo" doesn\'t exist in this environment.',
            $this->portage()->preview($payload, [$uid => ['choice' => 'fetch', 'volume' => 'nowhere']])->refusal,
        );

        $result = $this->portage()->import($payload, [$uid => ['choice' => 'fetch', 'volume' => 'archive', 'folder' => 'imports/2026']]);

        $fetched = Asset::find()->uid($uid)->one();
        $this->assertNotNull($fetched, 'No asset with the source UID');
        $this->assertSame(['archive', 'imports/2026/', 'photo.png'], [$fetched->getVolume()->handle, $fetched->getFolder()->path, $fetched->getFilename()]);
        $this->assertSame([$fetched->id], $this->importedEntry($result->roots[0]->elementId)->getFieldValue('images')->ids());
        $this->assertSame($archive->id, $fetched->volumeId);
    }

    public function testAnAssetFetchedForARootElementThatFailsIsFetchedAgainForTheNext(): void
    {
        $photos = Schema::volume('photos', FileServer::start() . '/files');
        $photo = Schema::asset($photos, 'photo.png', '', ['title' => 'Photo']);
        $uid = $photo->uid;

        [$news, $article] = $this->newsWithImages();
        $fails = Schema::entry($news, $article, ['title' => 'Fails', 'images' => [$photo->id]]);
        $works = Schema::entry($news, $article, ['title' => 'Works', 'images' => [$photo->id]]);
        $payload = $this->portage()->export([$fails, $works]);
        $this->hardDelete($fails);
        $this->hardDelete($works);
        $this->hardDelete($photo);

        $refuse = function(ModelEvent $event) {
            if ($event->sender instanceof Entry && $event->sender->title === 'Fails') {
                $event->isValid = false;
            }
        };
        Event::on(Entry::class, Entry::EVENT_BEFORE_SAVE, $refuse);
        try {
            $result = $this->portage()->import($payload, [$uid => 'fetch']);
        } finally {
            Event::off(Entry::class, Entry::EVENT_BEFORE_SAVE, $refuse);
        }

        $this->assertSame(['failed', 'created'], [$result->roots[0]->status, $result->roots[1]->status]);
        $assets = Asset::find()->volumeId($photos->id)->all();
        $this->assertCount(1, $assets);
        $this->assertSame([$uid, 'photo.png'], [$assets[0]->uid, $assets[0]->getFilename()]);
        $this->assertSame([], $result->roots[0]->fetchedAssets);
        $this->assertSame([['uid' => $uid, 'title' => 'Photo', 'elementId' => $assets[0]->id]], $result->roots[1]->fetchedAssets);
        $this->assertSame([$assets[0]->id], $this->importedEntry($result->roots[1]->elementId)->getFieldValue('images')->ids());
    }

    /**
     * @return array{\craft\models\Section, \craft\models\EntryType}
     */
    private function newsWithImages(array $fieldConfig = []): array
    {
        $article = Schema::entryType('article', [Schema::field(Assets::class, 'images', $fieldConfig)]);

        return [Schema::section('news', [$article]), $article];
    }

    private function portage(): Portage
    {
        return Plugin::getInstance()->getPortage();
    }

    private function hardDelete(Entry|Asset $element): void
    {
        Schema::deleteElement($element);
    }

    private function importedEntry(?int $id): Entry
    {
        $this->assertNotNull($id, 'The Import result has no element ID');
        $entry = Entry::find()->id($id)->status(null)->one();
        $this->assertNotNull($entry, "No entry with ID $id");

        return $entry;
    }
}
