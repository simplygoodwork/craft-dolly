<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use craft\events\ModelEvent;
use craft\fields\Categories;
use craft\fields\Entries;
use craft\fields\Tags;
use craft\fields\Users;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\tests\support\Schema;
use yii\base\Event;

/**
 * Resolving References by UID and Natural key, and the choices for Unresolved references: skip, Stub or a replacement.
 */
class ReferencesTest extends Unit
{
    public function testAnEntryReferenceWithADifferentUidResolvesBySectionAndSlug(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $craft = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);
        $sourceUid = $craft->uid;

        $article = Schema::entryType('article', [Schema::field(Entries::class, 'relatedTopics')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Related', 'relatedTopics' => [$craft->id]]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        Schema::changeUid($craft);

        $plan = $this->portage()->preview($payload);
        $this->assertSame('naturalKey', $plan->references[$sourceUid]->resolution->rule);
        $this->assertSame($craft->id, $plan->references[$sourceUid]->resolution->elementId);

        $result = $this->portage()->import($payload);
        $this->assertSame([], $result->roots[0]->unresolvedReferences);
        $this->assertSame([$craft->id], $this->importedEntry($result->roots[0]->elementId)->getFieldValue('relatedTopics')->ids());
    }

    public function testInAStructureTheParentSlugPathPicksBetweenEntriesWithTheSameSlug(): void
    {
        $page = Schema::entryType('page');
        $pages = Schema::section('pages', [$page], ['type' => 'structure']);
        $about = Schema::entry($pages, $page, ['title' => 'About', 'slug' => 'about']);
        $services = Schema::entry($pages, $page, ['title' => 'Services', 'slug' => 'services']);
        $aboutIntro = Schema::entry($pages, $page, ['title' => 'About intro', 'slug' => 'intro', 'parentId' => $about->id]);
        $servicesIntro = Schema::entry($pages, $page, ['title' => 'Services intro', 'slug' => 'intro', 'parentId' => $services->id]);
        $sourceUid = $servicesIntro->uid;

        $article = Schema::entryType('article', [Schema::field(Entries::class, 'relatedPages')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Related', 'relatedPages' => [$servicesIntro->id]]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        Schema::changeUid($aboutIntro);
        Schema::changeUid($servicesIntro);

        $plan = $this->portage()->preview($payload);
        $this->assertSame('naturalKey', $plan->references[$sourceUid]->resolution->rule);
        $this->assertSame($servicesIntro->id, $plan->references[$sourceUid]->resolution->elementId);
    }

    public function testRoundTripsACategoriesFieldMatchingByUidAndByGroupSlugAndParentPath(): void
    {
        $colours = Schema::categoryGroup('colours');
        $red = Schema::category($colours, ['title' => 'Red', 'slug' => 'red']);
        $warm = Schema::category($colours, ['title' => 'Warm', 'slug' => 'warm']);
        $cool = Schema::category($colours, ['title' => 'Cool', 'slug' => 'cool']);
        $warmLight = Schema::category($colours, ['title' => 'Light', 'slug' => 'light', 'parentId' => $warm->id]);
        $coolLight = Schema::category($colours, ['title' => 'Light', 'slug' => 'light', 'parentId' => $cool->id]);
        $coolLightUid = $coolLight->uid;

        $article = Schema::entryType('article', [Schema::field(Categories::class, 'colours', ['source' => "group:$colours->uid"])]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Colourful', 'colours' => [$coolLight->id, $red->id]]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        Schema::changeUid($warmLight);
        Schema::changeUid($coolLight);

        $plan = $this->portage()->preview($payload);
        $this->assertSame('naturalKey', $plan->references[$coolLightUid]->resolution->rule);
        $this->assertSame('uid', $plan->references[$red->uid]->resolution->rule);

        $result = $this->portage()->import($payload);
        $this->assertSame([$coolLight->id, $red->id], $this->importedEntry($result->roots[0]->elementId)->getFieldValue('colours')->ids());
    }

    public function testRoundTripsATagsFieldMatchingByUidAndByGroupAndTitle(): void
    {
        $keywords = Schema::tagGroup('keywords');
        $craft = Schema::tag($keywords, 'Craft');
        $php = Schema::tag($keywords, 'PHP');
        $phpUid = $php->uid;

        $article = Schema::entryType('article', [Schema::field(Tags::class, 'keywords', ['source' => "taggroup:$keywords->uid"])]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Tagged', 'keywords' => [$php->id, $craft->id]]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        Schema::changeUid($php);

        $plan = $this->portage()->preview($payload);
        $this->assertSame('naturalKey', $plan->references[$phpUid]->resolution->rule);
        $this->assertSame('uid', $plan->references[$craft->uid]->resolution->rule);

        $result = $this->portage()->import($payload);
        $this->assertSame([$php->id, $craft->id], $this->importedEntry($result->roots[0]->elementId)->getFieldValue('keywords')->ids());
    }

    public function testRoundTripsAUsersFieldMatchingByUidThenEmailThenUsernameAndSkipsUnresolvedUsers(): void
    {
        $sam = Schema::user('sam');
        $alex = Schema::user('alex');
        $jo = Schema::user('jo');
        $gone = Schema::user('gone');
        [$alexUid, $joUid, $goneUid] = [$alex->uid, $jo->uid, $gone->uid];

        $article = Schema::entryType('article', [Schema::field(Users::class, 'contacts')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Contacts', 'contacts' => [$sam->id, $alex->id, $jo->id, $gone->id]]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        Schema::changeUid($alex);
        Schema::changeUid($jo);
        Schema::changeUserIdentity($jo, 'jo@elsewhere.example.com');
        Craft::$app->getElements()->deleteElement($gone, true);

        $plan = $this->portage()->preview($payload);
        $this->assertSame('uid', $plan->references[$sam->uid]->resolution->rule);
        $this->assertSame('naturalKey', $plan->references[$alexUid]->resolution->rule);
        $this->assertSame('naturalKey', $plan->references[$joUid]->resolution->rule);
        $this->assertSame($jo->id, $plan->references[$joUid]->resolution->elementId);
        $this->assertFalse($plan->references[$goneUid]->resolution->isResolved());

        $result = $this->portage()->import($payload);
        $this->assertSame([$sam->id, $alex->id, $jo->id], $this->importedEntry($result->roots[0]->elementId)->getFieldValue('contacts')->ids());
        $this->assertSame([
            ['uid' => $goneUid, 'elementType' => 'user', 'title' => 'gone', 'field' => 'Contacts'],
        ], $result->roots[0]->unresolvedReferences);
    }

    public function testAnUnresolvedEntryIsSkippedByDefaultAndCanBeStubbedInItsOriginalSection(): void
    {
        $topic = Schema::entryType('topic', [Schema::required(Schema::plainText('summary'))]);
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone topic', 'slug' => 'gone-topic', 'summary' => 'Not carried']);
        $goneUid = $gone->uid;

        $article = Schema::entryType('article', [Schema::field(Entries::class, 'relatedTopics')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Related', 'relatedTopics' => [$gone->id]]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        $this->hardDelete($gone);

        $plan = $this->portage()->preview($payload);
        $this->assertSame('skip', $plan->references[$goneUid]->choice);
        $this->assertSame(['skip', 'stub', 'replace'], $plan->references[$goneUid]->availableChoices);
        $this->assertSame('stub', $this->portage()->preview($payload, [$goneUid => 'stub'])->references[$goneUid]->choice);
        $this->assertNull(Entry::find()->uid($goneUid)->status(null)->one(), 'The Preview created the Stub');

        $result = $this->portage()->import($payload, [$goneUid => 'stub']);

        $stub = Entry::find()->uid($goneUid)->status(null)->one();
        $this->assertNotNull($stub, 'No Stub with the source UID');
        $this->assertFalse($stub->enabled);
        $this->assertSame('Gone topic', $stub->title);
        $this->assertSame('gone-topic', $stub->slug);
        $this->assertSame('topics', $stub->getSection()->handle);
        $this->assertNull($stub->getFieldValue('summary'));
        $this->assertSame([$stub->id], $this->importedEntry($result->roots[0]->elementId)->getFieldValue('relatedTopics')->status(null)->ids());
        $this->assertSame([], $result->roots[0]->unresolvedReferences);
        $this->assertSame([
            ['uid' => $goneUid, 'elementType' => 'entry', 'title' => 'Gone topic', 'elementId' => $stub->id],
        ], $result->roots[0]->stubs);
    }

    public function testUnresolvedCategoriesAndTagsCanBeStubbedInTheirOriginalGroups(): void
    {
        $colours = Schema::categoryGroup('colours');
        $red = Schema::category($colours, ['title' => 'Red', 'slug' => 'red']);
        $keywords = Schema::tagGroup('keywords');
        $craft = Schema::tag($keywords, 'Craft');
        [$redUid, $craftUid] = [$red->uid, $craft->uid];

        $article = Schema::entryType('article', [
            Schema::field(Categories::class, 'colours', ['source' => "group:$colours->uid"]),
            Schema::field(Tags::class, 'keywords', ['source' => "taggroup:$keywords->uid"]),
        ]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Stubbed', 'colours' => [$red->id], 'keywords' => [$craft->id]]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        Craft::$app->getElements()->deleteElement($red, true);
        Craft::$app->getElements()->deleteElement($craft, true);

        $result = $this->portage()->import($payload, [$redUid => 'stub', $craftUid => 'stub']);

        $category = Category::find()->uid($redUid)->status(null)->one();
        $this->assertNotNull($category, 'No category Stub with the source UID');
        $this->assertFalse($category->enabled);
        $this->assertSame(['Red', 'red', 'colours'], [$category->title, $category->slug, $category->getGroup()->handle]);

        $tag = Tag::find()->uid($craftUid)->status(null)->one();
        $this->assertNotNull($tag, 'No tag Stub with the source UID');
        $this->assertSame(['Craft', 'keywords'], [$tag->title, $tag->getGroup()->handle]);

        $imported = $this->importedEntry($result->roots[0]->elementId);
        $this->assertSame([$category->id], $imported->getFieldValue('colours')->status(null)->ids());
        $this->assertSame([$tag->id], $imported->getFieldValue('keywords')->status(null)->ids());
    }

    public function testAnUnresolvedReferenceCanBeReplacedWithAChosenElement(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone']);
        $instead = Schema::entry($topics, $topic, ['title' => 'Instead']);
        $goneUid = $gone->uid;

        $article = Schema::entryType('article', [Schema::field(Entries::class, 'relatedTopics')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Related', 'relatedTopics' => [$gone->id]]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        $this->hardDelete($gone);

        $this->assertSame('replace', $this->portage()->preview($payload, [$goneUid => $instead->id])->references[$goneUid]->choice);
        $result = $this->portage()->import($payload, [$goneUid => $instead->id]);

        $this->assertSame([$instead->id], $this->importedEntry($result->roots[0]->elementId)->getFieldValue('relatedTopics')->ids());
        $this->assertSame([], $result->roots[0]->unresolvedReferences);
        $this->assertSame([], $result->roots[0]->stubs);
    }

    public function testChoosingAStubForAUserIsRefusedAndNothingIsImported(): void
    {
        $gone = Schema::user('gone', ['fullName' => 'Gone User']);
        $goneUid = $gone->uid;

        $article = Schema::entryType('article', [Schema::field(Users::class, 'contacts')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Contacts', 'contacts' => [$gone->id]]);
        $sourceUid = $source->uid;

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        Craft::$app->getElements()->deleteElement($gone, true);

        $this->assertSame(['skip', 'replace'], $this->portage()->preview($payload)->references[$goneUid]->availableChoices);
        $refusal = 'The user "Gone User" can\'t be Stubbed: Portage never creates users. Skip it or choose a replacement user.';
        $this->assertSame($refusal, $this->portage()->preview($payload, [$goneUid => 'stub'])->refusal);

        $result = $this->portage()->import($payload, [$goneUid => 'stub']);
        $this->assertSame($refusal, $result->refusal);
        $this->assertNull(Entry::find()->uid($sourceUid)->status(null)->one());
        $this->assertNull(User::find()->uid($goneUid)->status(null)->one());
    }

    public function testAReplacementThatDoesNotExistIsRefused(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone']);
        $goneUid = $gone->uid;

        $article = Schema::entryType('article', [Schema::field(Entries::class, 'relatedTopics')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Related', 'relatedTopics' => [$gone->id]]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        $this->hardDelete($gone);

        $this->assertSame(
            'The replacement for the Reference "Gone" doesn\'t exist: there\'s no entry with the ID 999999 in this environment.',
            $this->portage()->preview($payload, [$goneUid => 999999])->refusal,
        );
    }

    public function testAnUnresolvedAuthorBecomesTheUserRunningTheImport(): void
    {
        $sam = Schema::user('sam');
        $gone = Schema::user('gone');
        $importer = Schema::user('importer');

        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article], ['maxAuthors' => 2]);
        $source = Schema::entry($news, $article, ['title' => 'Co-written', 'authorIds' => [$sam->id, $gone->id]]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        Craft::$app->getElements()->deleteElement($gone, true);
        Craft::$app->getUser()->setIdentity($importer);

        $result = $this->portage()->import($payload);

        $this->assertSame([$sam->id, $importer->id], $this->importedEntry($result->roots[0]->elementId)->getAuthorIds());
        $this->assertSame(
            ['The author "gone" doesn\'t exist in this environment. "importer" will be credited instead.'],
            $result->roots[0]->warnings,
        );
        $this->assertSame([], $result->roots[0]->unresolvedReferences);
    }

    public function testAStubIsCreatedInsideItsRootElementsTransaction(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone']);
        $goneUid = $gone->uid;

        $article = Schema::entryType('article', [Schema::field(Entries::class, 'relatedTopics')]);
        $news = Schema::section('news', [$article]);
        $fails = Schema::entry($news, $article, ['title' => 'Fails', 'relatedTopics' => [$gone->id]]);
        $works = Schema::entry($news, $article, ['title' => 'Works', 'relatedTopics' => [$gone->id]]);

        $payload = $this->portage()->export([$fails, $works]);
        $this->hardDelete($fails);
        $this->hardDelete($works);
        $this->hardDelete($gone);

        // Craft refuses to save the first Root element after its Stub has been created.
        $refuse = function(ModelEvent $event) {
            if ($event->sender instanceof Entry && $event->sender->title === 'Fails') {
                $event->isValid = false;
            }
        };
        Event::on(Entry::class, Entry::EVENT_BEFORE_SAVE, $refuse);
        try {
            $result = $this->portage()->import($payload, [$goneUid => 'stub']);
        } finally {
            Event::off(Entry::class, Entry::EVENT_BEFORE_SAVE, $refuse);
        }

        $this->assertSame(['failed', 'created'], [$result->roots[0]->status, $result->roots[1]->status]);
        $this->assertSame([], $result->roots[0]->stubs);
        $stubs = Entry::find()->sectionId($topics->id)->status(null)->all();
        $this->assertCount(1, $stubs);
        $this->assertSame($goneUid, $stubs[0]->uid);
        $this->assertSame([
            ['uid' => $goneUid, 'elementType' => 'entry', 'title' => 'Gone', 'elementId' => $stubs[0]->id],
        ], $result->roots[1]->stubs);
        $this->assertSame([$stubs[0]->id], $this->importedEntry($result->roots[1]->elementId)->getFieldValue('relatedTopics')->status(null)->ids());
    }

    public function testAReferenceToAnotherRootElementInThePayloadCanNotBeStubbed(): void
    {
        $article = Schema::entryType('article', [Schema::field(Entries::class, 'relatedArticles')]);
        $news = Schema::section('news', [$article]);
        $target = Schema::entry($news, $article, ['title' => 'Target', 'slug' => 'target']);
        $linking = Schema::entry($news, $article, ['title' => 'Linking', 'relatedArticles' => [$target->id]]);
        $targetUid = $target->uid;

        $payload = $this->portage()->export([$linking, $target]);
        $this->hardDelete($linking);
        $this->hardDelete($target);

        $this->assertSame(['skip', 'replace'], $this->portage()->preview($payload)->references[$targetUid]->availableChoices);
        $this->assertSame(
            'The Reference "Target" can\'t be Stubbed: this Payload imports it as a Root element. Skip it or choose a replacement.',
            $this->portage()->import($payload, [$targetUid => 'stub'])->refusal,
        );
        $this->assertSame(0, (int)Entry::find()->sectionId($news->id)->status(null)->count());
    }

    public function testANaturalKeyMatchingMoreThanOneElementLeavesTheReferenceUnresolved(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $first = Schema::entry($topics, $topic, ['title' => 'Craft', 'slug' => 'craft']);
        $second = Schema::entry($topics, $topic, ['title' => 'Craft again', 'slug' => 'craft']);
        $sourceUid = $first->uid;

        $article = Schema::entryType('article', [Schema::field(Entries::class, 'relatedTopics')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Related', 'relatedTopics' => [$first->id]]);

        $payload = $this->portage()->export([$source]);
        $this->hardDelete($source);
        Schema::changeUid($first);
        Schema::changeUid($second);

        $this->assertFalse($this->portage()->preview($payload)->references[$sourceUid]->resolution->isResolved());
    }

    public function testACategoryStubRolledBackWithItsRootElementIsCreatedAgainForTheNext(): void
    {
        $colours = Schema::categoryGroup('colours');
        $red = Schema::category($colours, ['title' => 'Red', 'slug' => 'red']);
        $redUid = $red->uid;

        $article = Schema::entryType('article', [Schema::field(Categories::class, 'colours', ['source' => "group:$colours->uid"])]);
        $news = Schema::section('news', [$article]);
        $fails = Schema::entry($news, $article, ['title' => 'Fails', 'colours' => [$red->id]]);
        $works = Schema::entry($news, $article, ['title' => 'Works', 'colours' => [$red->id]]);

        $payload = $this->portage()->export([$fails, $works]);
        $this->hardDelete($fails);
        $this->hardDelete($works);
        Craft::$app->getElements()->deleteElement($red, true);

        $refuse = function(ModelEvent $event) {
            if ($event->sender instanceof Entry && $event->sender->title === 'Fails') {
                $event->isValid = false;
            }
        };
        Event::on(Entry::class, Entry::EVENT_BEFORE_SAVE, $refuse);
        try {
            $result = $this->portage()->import($payload, [$redUid => 'stub']);
        } finally {
            Event::off(Entry::class, Entry::EVENT_BEFORE_SAVE, $refuse);
        }

        $this->assertSame(['failed', 'created'], [$result->roots[0]->status, $result->roots[1]->status]);
        $categories = Category::find()->groupId($colours->id)->status(null)->all();
        $this->assertCount(1, $categories);
        $this->assertSame($redUid, $categories[0]->uid);
        $this->assertSame([$categories[0]->id], $this->importedEntry($result->roots[1]->elementId)->getFieldValue('colours')->status(null)->ids());
    }

    private function portage(): Portage
    {
        return Plugin::getInstance()->getPortage();
    }

    private function hardDelete(Entry $entry): void
    {
        Craft::$app->getElements()->deleteElement($entry, true);
    }

    private function importedEntry(?int $id): Entry
    {
        $this->assertNotNull($id, 'The Import result has no element ID');
        $entry = Entry::find()->id($id)->status(null)->one();
        $this->assertNotNull($entry, "No entry with ID $id");

        return $entry;
    }
}
