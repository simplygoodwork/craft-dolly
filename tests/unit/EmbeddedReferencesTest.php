<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fields\Entries;
use simplygoodwork\craftportage\import\ReferencePlan;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\tests\support\FileServer;
use simplygoodwork\craftportage\tests\support\Schema;

/**
 * Embedded references written as Craft reference tags (`{type:id@site:attr||fallback}`) in text: translated to
 * References on Export, and resolved with the same rules and choices as relation References on Import.
 */
class EmbeddedReferencesTest extends Unit
{
    public function testAnEntryRefTagPointsAtTheEntryItResolvesToInThisEnvironment(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);

        $article = Schema::entryType('article', [Schema::plainText('body')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Linked', 'body' => "Read {entry:$craft->id:url} now."]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($craft);
        $recreated = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);
        $this->assertNotSame($craft->id, $recreated->id);

        $result = $this->portage()->import($payload);

        $this->assertSame("Read {entry:$recreated->id:url} now.", $this->importedEntry($result->roots[0]->elementId)->getFieldValue('body'));
    }

    public function testAssetAndCategoryRefTagsPointAtTheElementsTheyResolveToKeepingAttributesAndFallbacks(): void
    {
        $photos = Schema::volume('photos', 'https://example.com/files');
        $photo = Schema::asset($photos, 'photo.png', 'blog');
        $colours = Schema::categoryGroup('colours');
        $red = Schema::category($colours, ['title' => 'Red', 'slug' => 'red']);

        $body = Schema::plainText('body');
        $article = Schema::entryType('article', [$body]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, [
            'title' => 'Linked',
            'body' => "<img src=\"{asset:$photo->id:url||/missing.png}\"> {craft\\elements\\Category:$red->id:title} {entry:news/linked:url}",
        ]);

        $payload = $this->portage()->export([$source]);
        $this->assertSame(
            "<img src=\"{asset:$photo->uid:url||/missing.png}\"> {category:$red->uid:title} {entry:news/linked:url}",
            $this->portage()->preview($payload)->envelope['roots'][0]['fields'][$body->uid],
        );
        Schema::deleteElement($source);
        Schema::deleteElement($photo);
        Schema::deleteElement($red);
        $newPhoto = Schema::asset($photos, 'photo.png', 'blog');
        $newRed = Schema::category($colours, ['title' => 'Red', 'slug' => 'red']);

        $result = $this->portage()->import($payload);

        $this->assertSame(
            "<img src=\"{asset:$newPhoto->id:url||/missing.png}\"> {category:$newRed->id:title} {entry:news/linked:url}",
            $this->importedEntry($result->roots[0]->elementId)->getFieldValue('body'),
        );
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testAnUnresolvedRefTagUnwrapsItsLinkKeepingTheTextOrIsRemovedElsewhereWithWarnings(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone topic', 'slug' => 'gone-topic']);
        $goneUid = $gone->uid;

        $article = Schema::entryType('article', [Schema::plainText('body')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, [
            'title' => 'Linked',
            'body' => "See <a class=\"more\" href=\"{entry:$gone->id:url}\">the <em>gone</em> topic</a>. Its URL: {entry:$gone->id:url}. {entry:$gone->id:title||Untitled}",
        ]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($gone);

        $plan = $this->portage()->preview($payload);
        $this->assertSame('Unresolved: will be skipped', $plan->references[$goneUid]->outcome());

        $result = $this->portage()->import($payload);

        $this->assertSame(
            'See the <em>gone</em> topic. Its URL: . Untitled',
            $this->importedEntry($result->roots[0]->elementId)->getFieldValue('body'),
        );
        $this->assertSame([
            'The entry "Gone topic" linked in the field "Body" doesn\'t exist in this environment. The link will be removed and its text kept.',
            'The entry "Gone topic" referenced in the field "Body" doesn\'t exist in this environment. The reference tag will be replaced with its fallback text, or removed.',
        ], $result->roots[0]->warnings);
        $this->assertSame([
            ['uid' => $goneUid, 'elementType' => 'entry', 'title' => 'Gone topic', 'field' => 'Body'],
        ], $result->roots[0]->unresolvedReferences);
    }

    public function testChoosingStubForARefTagCreatesAStubAndPointsTheTagAtItInEveryPlace(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone topic', 'slug' => 'gone-topic']);
        $goneUid = $gone->uid;

        $block = Schema::blockType('text', [Schema::plainText('text')]);
        $article = Schema::entryType('article', [Schema::plainText('body'), Schema::matrix('blocks', [$block])]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, [
            'title' => 'Linked',
            'body' => "<a href=\"{entry:$gone->id:url}\">Gone</a>",
            'blocks' => [
                'new1' => ['type' => 'text', 'fields' => ['text' => "Block: {entry:$gone->id:title}"]],
            ],
        ]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($gone);

        $plan = $this->portage()->preview($payload, [$goneUid => 'stub']);
        $this->assertSame(['skip', 'stub', 'replace'], $plan->references[$goneUid]->availableChoices);
        $this->assertSame('Unresolved: a Stub will be created', $plan->references[$goneUid]->outcome());
        $this->assertNull(Entry::find()->uid($goneUid)->status(null)->one(), 'The Preview created the Stub');

        $result = $this->portage()->import($payload, [$goneUid => 'stub']);

        $stub = Entry::find()->uid($goneUid)->status(null)->one();
        $this->assertNotNull($stub, 'No Stub with the source UID');
        $this->assertSame([
            ['uid' => $goneUid, 'elementType' => 'entry', 'title' => 'Gone topic', 'elementId' => $stub->id],
        ], $result->roots[0]->stubs);
        $imported = $this->importedEntry($result->roots[0]->elementId);
        $this->assertSame("<a href=\"{entry:$stub->id:url}\">Gone</a>", $imported->getFieldValue('body'));
        $this->assertSame(["Block: {entry:$stub->id:title}"], array_map(
            fn(Entry $nested) => $nested->getFieldValue('text'),
            $imported->getFieldValue('blocks')->status(null)->all(),
        ));
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testSiteQualifiedRefTagsTravelBySiteUidAndAMissingSiteRemovesTheTag(): void
    {
        $german = Schema::site('german', ['language' => 'de']);
        $french = Schema::site('french', ['language' => 'fr']);
        $germanUid = $german->uid;

        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);

        $body = Schema::plainText('body');
        $article = Schema::entryType('article', [$body]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, [
            'title' => 'Linked',
            'body' => "{entry:$craft->id@$german->id:url} {entry:$craft->id@german:url} {entry:$craft->id@$french->id:url||Craft}",
        ]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteSite($french);

        $plan = $this->portage()->preview($payload);
        $this->assertSame(
            "{entry:$craft->uid@$germanUid:url} {entry:$craft->uid@german:url} {entry:$craft->uid@$french->uid:url||Craft}",
            $plan->envelope['roots'][0]['fields'][$body->uid],
        );

        $result = $this->portage()->import($payload);

        $this->assertSame(
            "{entry:$craft->id@$german->id:url} {entry:$craft->id@german:url} Craft",
            $this->importedEntry($result->roots[0]->elementId)->getFieldValue('body'),
        );
        $this->assertContains(
            'The site "French" in a reference tag to the entry "Craft" in the field "Body" doesn\'t exist in this environment. The reference tag will be replaced with its fallback text, or removed.',
            $result->roots[0]->warnings,
        );
    }

    public function testThePreviewListsEmbeddedReferencesAlongsideRelationReferences(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $related = Schema::entry($topics, $topic, ['title' => 'Related', 'slug' => 'related']);
        $linked = Schema::entry($topics, $topic, ['title' => 'Linked', 'slug' => 'linked']);

        $article = Schema::entryType('article', [Schema::field(Entries::class, 'topics'), Schema::plainText('body')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, [
            'title' => 'Both',
            'topics' => [$related->id],
            'body' => "<a href=\"{entry:$linked->id:url}\">Linked</a>",
        ]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);

        $plan = $this->portage()->preview($payload);

        $this->assertSame(['Related', 'Linked'], array_values(array_map(
            fn(ReferencePlan $reference) => $reference->title,
            $plan->references,
        )));
        $this->assertSame('Matched by UID', $plan->references[$linked->uid]->outcome());
    }

    public function testAFetchedAssetGetsItsRefTagAndAFailedFetchLeavesTheFallbackText(): void
    {
        $server = FileServer::start();
        $photos = Schema::volume('photos', "$server/files");
        $photo = Schema::asset($photos, 'photo.png', '', ['title' => 'Photo']);
        $gone = Schema::asset($photos, 'notes.txt', '', ['title' => 'Gone notes', 'filename' => 'gone.txt']);
        [$photoUid, $goneUid] = [$photo->uid, $gone->uid];

        $article = Schema::entryType('article', [Schema::plainText('body')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, [
            'title' => 'Pictured',
            'body' => "{asset:$photo->id:url} {asset:$gone->id:url||no notes}",
        ]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($photo);
        Schema::deleteElement($gone);

        $result = $this->portage()->import($payload, [$photoUid => 'fetch', $goneUid => 'fetch']);

        $fetched = Asset::find()->uid($photoUid)->one();
        $this->assertNotNull($fetched, 'No asset with the source UID');
        $this->assertSame("{asset:$fetched->id:url} no notes", $this->importedEntry($result->roots[0]->elementId)->getFieldValue('body'));
        $this->assertSame(
            ["The asset \"Gone notes\" couldn't be fetched from $server/files/gone.txt: the file wasn't found at the source (HTTP 404 Not Found). Its relations and links to it were cleared."],
            $result->roots[0]->warnings,
        );
    }

    public function testTagsThatAreNotEmbeddedReferencesStayAsTheyAre(): void
    {
        $article = Schema::entryType('article', [Schema::plainText('body')]);
        $news = Schema::section('news', [$article]);
        $text = '{globalset:99:handle} {entry:0b5c9d8e-1f2a-4b3c-8d4e-5f6a7b8c9d0e:url} {entry:news/hello:url}';
        $source = Schema::entry($news, $article, ['title' => 'Plain', 'body' => $text]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);

        $result = $this->portage()->import($payload);

        $this->assertSame($text, $this->importedEntry($result->roots[0]->elementId)->getFieldValue('body'));
        $this->assertSame([], $result->roots[0]->warnings);
        $this->assertSame([], $result->roots[0]->unresolvedReferences);
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
