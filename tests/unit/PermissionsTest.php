<?php

namespace simplygoodwork\craftdolly\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use craft\elements\User;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\services\Dolly;
use simplygoodwork\craftdolly\tests\support\Schema;

/**
 * Import checks Craft's save permissions for the user running it, through the core service.
 */
class PermissionsTest extends Unit
{
    protected function _after(): void
    {
        Craft::$app->getUser()->setIdentity(null);
    }

    public function testARootElementIsBlockedWhenTheUserCannotCreateEntriesInTheTargetSection(): void
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $blog = Schema::section('blog', [$article]);
        $first = Schema::entry($news, $article, ['title' => 'Allowed']);
        $second = Schema::entry($blog, $article, ['title' => 'Not allowed']);
        $payload = $this->dolly()->export([$first, $second]);
        Schema::deleteElement($first);
        Schema::deleteElement($second);
        $editor = Schema::user('editor');
        Schema::grantPermissions($editor, ['accessCp', "viewEntries:$news->uid", "createEntries:$news->uid"]);
        $this->actAs($editor);

        $plan = $this->dolly()->preview($payload);

        $this->assertNull($plan->roots[0]->blockedBy);
        $this->assertSame(
            'You don\'t have permission to create entries in the "Blog" section, so "Not allowed" can\'t be imported.',
            $plan->roots[1]->blockedBy,
        );

        $result = $this->dolly()->import($payload);
        $this->assertSame(['created', 'failed'], [$result->roots[0]->status, $result->roots[1]->status]);
        $this->assertSame(0, (int)Entry::find()->section('blog')->status(null)->count());
    }

    public function testOverwriteIsUnavailableWhenTheUserCannotViewTheMatchedEntry(): void
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Someone else\'s']);
        $payload = $this->dolly()->export([$source]);
        $editor = Schema::user('editor');
        // They can see and create their own entries, but not other people's.
        Schema::grantPermissions($editor, ['accessCp', "viewEntries:$news->uid", "createEntries:$news->uid"]);
        $this->actAs($editor);

        $plan = $this->dolly()->preview($payload);
        $this->assertNull($plan->roots[0]->blockedBy);
        $this->assertSame(['create'], $plan->roots[0]->availableModes);
        $this->assertSame(
            'you don\'t have permission to view "Someone else\'s" in the "News" section, so you can\'t create a draft on it.',
            $plan->roots[0]->overwriteUnavailable,
        );

        $refused = $this->dolly()->preview($payload, [], [$source->uid => ['mode' => 'overwrite']]);
        $this->assertSame(
            'The Root element "Someone else\'s" can\'t be overwritten: you don\'t have permission to view "Someone else\'s" in the "News" section, so you can\'t create a draft on it.',
            $refused->refusal,
        );
    }

    public function testAStubIsNotOfferedWhenTheUserCannotCreateEntriesInItsSection(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone']);
        $article = Schema::entryType('article', [Schema::field(\craft\fields\Entries::class, 'relatedTopics')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Related', 'relatedTopics' => [$gone->id]]);
        $payload = $this->dolly()->export([$source]);
        $goneUid = $gone->uid;
        Schema::deleteElement($gone);
        Schema::deleteElement($source);
        $editor = Schema::user('editor');
        Schema::grantPermissions($editor, ['accessCp', "viewEntries:$news->uid", "createEntries:$news->uid"]);
        $this->actAs($editor);

        $plan = $this->dolly()->preview($payload);
        $this->assertSame(['skip', 'replace'], $plan->references[$goneUid]->availableChoices);

        $refused = $this->dolly()->preview($payload, [$goneUid => 'stub']);
        $this->assertSame(
            'The Reference "Gone" can\'t be Stubbed: you don\'t have permission to create entries in the "Topics" section. Skip it or choose a replacement.',
            $refused->refusal,
        );
    }

    private function actAs(User $user): void
    {
        Craft::$app->getUser()->setIdentity($user);
    }

    private function dolly(): Dolly
    {
        return Plugin::getInstance()->getDolly();
    }
}
