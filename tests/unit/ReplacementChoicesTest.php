<?php

namespace simplygoodwork\craftdolly\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use craft\fields\Entries;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\services\Dolly;
use simplygoodwork\craftdolly\tests\support\Schema;

/**
 * Replacements and other choices are checked on the server, not just limited in the control panel's element selector.
 */
class ReplacementChoicesTest extends Unit
{
    protected function _after(): void
    {
        Craft::$app->getUser()->setIdentity(null);
    }

    public function testAReplacementMustBeInTheSectionTheReferenceCameFrom(): void
    {
        [$payload, $goneUid, $other] = $this->payloadWithAGoneTopic();

        $refused = $this->dolly()->preview($payload, [$goneUid => $other->id]);

        $this->assertSame(
            "The replacement for the Reference \"Gone\" can't be used: the entry with the ID $other->id isn't in the same section as the Reference.",
            $refused->refusal,
        );
        $this->assertSame($goneUid, $refused->refusalUid);
    }

    public function testAReplacementTheUserCannotViewIsRefusedWithoutNamingIt(): void
    {
        [$payload, $goneUid, , $kept] = $this->payloadWithAGoneTopic();
        $editor = Schema::user('editor');
        Schema::grantPermissions($editor, ['accessCp']);
        Craft::$app->getUser()->setIdentity($editor);

        $refused = $this->dolly()->preview($payload, [$goneUid => $kept->id]);

        $this->assertSame(
            "The replacement for the Reference \"Gone\" can't be used: you don't have permission to view the entry with the ID $kept->id.",
            $refused->refusal,
        );
        $this->assertStringNotContainsString('Kept', (string)$refused->refusal);
    }

    public function testANestedEntryIsNotAValidReplacement(): void
    {
        $block = Schema::blockType('text', [Schema::plainText('body')]);
        $topic = Schema::entryType('topic', [Schema::matrix('blocks', [$block])]);
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone']);
        $holder = Schema::entry($topics, $topic, ['title' => 'Holder', 'blocks' => ['new1' => ['type' => 'text', 'fields' => ['body' => 'x']]]]);
        $nested = Entry::find()->ownerId($holder->id)->status(null)->one();
        $this->assertNotNull($nested);
        $article = Schema::entryType('article', [Schema::field(Entries::class, 'relatedTopics')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Related', 'relatedTopics' => [$gone->id]]);
        $payload = $this->dolly()->export([$source]);
        $goneUid = $gone->uid;
        Schema::deleteElement($gone);

        $refused = $this->dolly()->preview($payload, [$goneUid => $nested->id]);

        $this->assertSame(
            "The replacement for the Reference \"Gone\" can't be used: the entry with the ID $nested->id is nested in another element.",
            $refused->refusal,
        );
        $this->assertSame($goneUid, $this->dolly()->preview($payload, [$goneUid => $holder->id])->references[$goneUid]->uid);
    }

    public function testAnIncompleteReplaceOrUploadIsPlannedAsSkipAndRemembered(): void
    {
        [$payload, $goneUid] = $this->payloadWithAGoneTopic();

        $plan = $this->dolly()->preview($payload, [$goneUid => ['choice' => 'replace', 'element' => null]]);

        $this->assertFalse($plan->isRefused());
        $this->assertSame(['skip', 'replace'], [$plan->references[$goneUid]->choice, $plan->references[$goneUid]->pending]);
        $this->assertSame($this->dolly()->preview($payload)->fingerprint(), $plan->fingerprint());
    }

    public function testTheSectionsOfferedAreOnlyThoseTheUserCanSaveEntriesIn(): void
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $blog = Schema::section('blog', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Hello']);
        $payload = $this->dolly()->export([$source]);
        $editor = Schema::user('editor');
        Schema::grantPermissions($editor, ['accessCp', "viewEntries:$news->uid", "saveEntries:$news->uid", "createEntries:$news->uid"]);
        Craft::$app->getUser()->setIdentity($editor);

        $plan = $this->dolly()->preview($payload);

        $this->assertSame(['News'], array_map(fn($section) => $section->name, $plan->roots[0]->availableSections));
        $this->assertNotContains($blog->name, array_map(fn($section) => $section->name, $plan->roots[0]->availableSections));
    }

    /**
     * @return array{string, string, Entry, Entry} the Payload, the UID of the gone topic, a topic in another section and a kept topic
     */
    private function payloadWithAGoneTopic(): array
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $elsewhere = Schema::section('elsewhere', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone']);
        $kept = Schema::entry($topics, $topic, ['title' => 'Kept']);
        $other = Schema::entry($elsewhere, $topic, ['title' => 'Other']);
        $article = Schema::entryType('article', [Schema::field(Entries::class, 'relatedTopics')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Related', 'relatedTopics' => [$gone->id]]);
        $payload = $this->dolly()->export([$source]);
        $goneUid = $gone->uid;
        Schema::deleteElement($gone);

        return [$payload, $goneUid, $other, $kept];
    }

    private function dolly(): Dolly
    {
        return Plugin::getInstance()->getDolly();
    }
}
