<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Entry;
use craft\fields\Entries;
use craft\fields\Number;
use craft\models\Section;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\tests\support\Schema;

/**
 * Import modes: overwrite, choosing the target section, and the duplicate warning, through the core service.
 */
class ImportModesTest extends Unit
{
    public function testOverwriteCreatesADraftWithThePayloadsFieldsAndLeavesTheCanonicalEntryAlone(): void
    {
        $article = Schema::entryType('article', [Schema::plainText('summary'), Schema::plainText('teaser')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, [
            'title' => 'Staging title',
            'slug' => 'staging-slug',
            'summary' => 'Staging summary',
            'teaser' => 'Staging teaser',
        ]);
        $payload = $this->portage()->export([$source]);
        Schema::updateEntry($source, [
            'title' => 'Prod title',
            'slug' => 'prod-slug',
            'summary' => 'Prod summary',
            'teaser' => 'Prod teaser',
        ]);

        $result = $this->portage()->import($payload, [], [$source->uid => ['mode' => 'overwrite']]);

        $root = $result->roots[0];
        $this->assertSame('overwritten', $root->status, implode(' ', $root->errors));
        $this->assertSame($source->id, $root->elementId);
        $this->assertNotNull($root->draftId);

        $canonical = Entry::find()->id($source->id)->status(null)->one();
        $this->assertSame('Prod title', $canonical->title);
        $this->assertSame('prod-slug', $canonical->slug);
        $this->assertSame('Prod summary', $canonical->getFieldValue('summary'));
        $this->assertSame('Prod teaser', $canonical->getFieldValue('teaser'));
        $this->assertSame(1, (int)Entry::find()->section('news')->status(null)->count());

        $draft = Entry::find()->draftId($root->draftId)->status(null)->one();
        $this->assertNotNull($draft);
        $this->assertSame($source->id, $draft->getCanonicalId());
        $this->assertSame('Staging title', $draft->title);
        $this->assertSame('staging-slug', $draft->slug);
        $this->assertSame('Staging summary', $draft->getFieldValue('summary'));
        $this->assertSame('Staging teaser', $draft->getFieldValue('teaser'));
    }

    public function testOverwriteLeavesFieldsTheDraftDoesNotCarryOrSkipsUnchanged(): void
    {
        $summary = Schema::plainText('summary');
        $teaser = Schema::plainText('teaser');
        $article = Schema::entryType('article', [$summary, $teaser]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Staging', 'summary' => 'Staging summary', 'teaser' => 'Staging teaser']);
        $payload = $this->portage()->export([$source]);
        // After the Export, the Target environment gains a field the Payload doesn't carry, and changes the type of another.
        Schema::addFieldToEntryType($article, Schema::plainText('notes'));
        Schema::changeFieldType($teaser->uid, Number::class);
        Schema::updateEntry($source, ['summary' => 'Prod summary', 'notes' => 'Prod notes']);

        $plan = $this->portage()->preview($payload, [], [$source->uid => ['mode' => 'overwrite']]);
        $this->assertSame(
            [
                'The entry type "Article" has different settings in this environment than where it was exported. Its content will be imported, but check it.',
                'The field "Teaser" is a Number field in this environment but was a Plain Text field where it was exported. Its values will be skipped.',
            ],
            $plan->roots[0]->warnings,
        );

        $result = $this->portage()->import($payload, [], [$source->uid => ['mode' => 'overwrite']]);

        $this->assertSame('overwritten', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $draft = Entry::find()->draftId($result->roots[0]->draftId)->status(null)->one();
        $this->assertSame('Staging summary', $draft->getFieldValue('summary'));
        $this->assertSame('Prod notes', $draft->getFieldValue('notes'));
        $canonical = Entry::find()->id($source->id)->status(null)->one();
        $this->assertSame('Prod summary', $canonical->getFieldValue('summary'));
        $this->assertSame('Prod notes', $canonical->getFieldValue('notes'));
    }

    public function testOverwriteReplacesAMatrixFieldsBlocksAsAWholeWithoutReusingTheCanonicalBlocksUids(): void
    {
        $textBlock = Schema::blockType('textBlock', [Schema::plainText('body')]);
        $article = Schema::entryType('article', [Schema::matrix('contentBlocks', [$textBlock])]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Page', 'contentBlocks' => [
            'new1' => ['type' => 'textBlock', 'fields' => ['body' => 'First']],
            'new2' => ['type' => 'textBlock', 'fields' => ['body' => 'Second']],
        ]]);
        $payload = $this->portage()->export([$source]);
        $blockIds = $source->getFieldValue('contentBlocks')->status(null)->ids();
        Schema::updateEntry($source, ['contentBlocks' => [
            $blockIds[0] => [],
            $blockIds[1] => [],
            'new3' => ['type' => 'textBlock', 'fields' => ['body' => 'Prod only']],
        ]]);
        $canonical = Entry::find()->id($source->id)->status(null)->one();
        $this->assertSame(['First', 'Second', 'Prod only'], $this->bodies($canonical));

        $result = $this->portage()->import($payload, [], [$source->uid => ['mode' => 'overwrite']]);

        $this->assertSame('overwritten', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $draft = Entry::find()->draftId($result->roots[0]->draftId)->status(null)->one();
        $this->assertSame(['First', 'Second'], $this->bodies($draft));
        $this->assertSame(['First', 'Second', 'Prod only'], $this->bodies(Entry::find()->id($source->id)->status(null)->one()));
        $canonicalUids = array_map(fn(Entry $block) => $block->uid, $canonical->getFieldValue('contentBlocks')->status(null)->all());
        foreach ($draft->getFieldValue('contentBlocks')->status(null)->all() as $block) {
            $this->assertNotContains($block->uid, $canonicalUids);
        }
    }

    public function testCreateNewWithACollidingUidGetsAFreshUidForTheRootAndItsOwnedContent(): void
    {
        $textBlock = Schema::blockType('textBlock', [Schema::plainText('body')]);
        $article = Schema::entryType('article', [Schema::matrix('contentBlocks', [$textBlock])]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Page', 'contentBlocks' => [
            'new1' => ['type' => 'textBlock', 'fields' => ['body' => 'First']],
        ]]);
        $sourceBlockUid = $source->getFieldValue('contentBlocks')->status(null)->one()->uid;
        $payload = $this->portage()->export([$source]);

        $plan = $this->portage()->preview($payload);
        $this->assertSame('create', $plan->roots[0]->mode);

        $result = $this->portage()->import($payload);

        $this->assertSame('created', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertNotSame($source->id, $result->roots[0]->elementId);
        $created = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $this->assertNotSame($source->uid, $created->uid);
        $this->assertTrue(\craft\helpers\StringHelper::isUUID($created->uid));
        $blocks = $created->getFieldValue('contentBlocks')->status(null)->all();
        $this->assertCount(1, $blocks);
        $this->assertNotSame($sourceBlockUid, $blocks[0]->uid);
        $this->assertSame(['First'], $this->bodies($created));
        $this->assertSame(['First'], $this->bodies(Entry::find()->id($source->id)->status(null)->one()));
        $this->assertSame($sourceBlockUid, Entry::find()->id($source->getFieldValue('contentBlocks')->status(null)->ids()[0])->status(null)->one()->uid);
    }

    public function testThePreviewShowsTheMatchTheAvailableModesAndTheChosenMode(): void
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $byUid = Schema::entry($news, $article, ['title' => 'By UID', 'slug' => 'by-uid']);
        $byKey = Schema::entry($news, $article, ['title' => 'By key', 'slug' => 'by-key']);
        $unmatched = Schema::entry($news, $article, ['title' => 'Unmatched', 'slug' => 'unmatched']);
        $payload = $this->portage()->export([$byUid, $byKey, $unmatched]);
        $byKeyUid = $byKey->uid;
        Schema::changeUid($byKey);
        Schema::changeUid($unmatched);
        Schema::deleteElement($unmatched);

        $plan = $this->portage()->preview($payload, [], [$byKeyUid => ['mode' => 'overwrite']]);

        $this->assertSame([$byUid->id, 'uid'], [$plan->roots[0]->match->elementId, $plan->roots[0]->match->rule]);
        $this->assertSame(['create', 'overwrite'], $plan->roots[0]->availableModes);
        $this->assertSame('create', $plan->roots[0]->mode);
        $this->assertSame([$byKey->id, 'naturalKey'], [$plan->roots[1]->match->elementId, $plan->roots[1]->match->rule]);
        $this->assertSame('overwrite', $plan->roots[1]->mode);
        $this->assertNull($plan->roots[2]->match);
        $this->assertSame(['create'], $plan->roots[2]->availableModes);
        $this->assertSame('News', $plan->roots[0]->section->name);
    }

    public function testRefusesRootChoicesThatCantBeCarriedOut(): void
    {
        $article = Schema::entryType('article');
        $other = Schema::entryType('other');
        $news = Schema::section('news', [$article]);
        Schema::section('pages', [$other]);
        $source = Schema::entry($news, $article, ['title' => 'Hello']);
        $payload = $this->portage()->export([$source]);
        $uid = $source->uid;
        Schema::deleteElement($source);

        $refusals = [
            [['no-such-uid' => ['mode' => 'overwrite']], 'The Payload has no Root element with the UID no-such-uid, so its mode and section can\'t be chosen.'],
            [[$uid => ['mode' => 'replace']], "The mode \"replace\" for the Root element $uid isn't valid. Choose \"create\" or \"overwrite\"."],
            [[$uid => ['mode' => 'overwrite']], 'The Root element "Hello" can\'t be overwritten: no entry in this environment matches it by UID or by its section and slug. Create it as a new entry instead.'],
            [[$uid => ['section' => 'nowhere']], "The section \"nowhere\" for the Root element $uid doesn't exist in this environment, so it can't be chosen."],
            [[$uid => ['section' => 'pages']], 'The entry type "Article" isn\'t allowed in the "Pages" section, so the Root element "Hello" can\'t be imported there. Choose a section that allows it.'],
        ];
        foreach ($refusals as [$choices, $message]) {
            $plan = $this->portage()->preview($payload, [], $choices);
            $this->assertSame($message, $plan->refusal);
            $result = $this->portage()->import($payload, [], $choices);
            $this->assertSame($message, $result->refusal);
        }

        $this->assertSame(0, (int)Entry::find()->status(null)->count());
        $this->assertSame([], Plugin::getInstance()->getImportLog()->getEntries());
    }

    public function testTheTargetSectionOverrideImportsIntoASectionThatAllowsTheEntryType(): void
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $blog = Schema::section('blog', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Moved']);
        $payload = $this->portage()->export([$source]);

        $byHandle = $this->portage()->import($payload, [], [$source->uid => ['section' => 'blog']]);
        $byUid = $this->portage()->import($payload, [], [$source->uid => ['section' => $blog->uid]]);

        $this->assertSame('Blog', $this->portage()->preview($payload, [], [$source->uid => ['section' => 'blog']])->roots[0]->section->name);
        foreach ([$byHandle, $byUid] as $result) {
            $this->assertSame('created', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
            $created = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
            $this->assertSame('blog', $created->getSection()->handle);
            $this->assertSame('Moved', $created->title);
        }
        $this->assertSame(['news' => 1, 'blog' => 2], [
            'news' => (int)Entry::find()->section('news')->status(null)->count(),
            'blog' => (int)Entry::find()->section('blog')->status(null)->count(),
        ]);
    }

    public function testTheTargetSectionOverrideCanRescueARootWhoseSectionIsMissing(): void
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $blog = Schema::section('blog', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Rescued']);
        $payload = $this->portage()->export([$source]);
        Schema::deleteSection($news);

        $this->assertSame(
            'The section "News" doesn\'t exist in this environment. Root elements in it can\'t be imported.',
            $this->portage()->preview($payload)->roots[0]->blockedBy,
        );
        $result = $this->portage()->import($payload, [], [$source->uid => ['section' => 'blog']]);

        $this->assertSame('created', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertSame($blog->id, Entry::find()->id($result->roots[0]->elementId)->status(null)->one()->sectionId);
    }

    public function testTheSecondPreviewOfAPayloadWarnsWhoImportedItAndWhenWithoutBlockingTheImport(): void
    {
        $jane = Schema::user('jane', ['firstName' => 'Jane', 'lastName' => 'Doe']);
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Twice']);
        $payload = $this->portage()->export([$source]);

        $this->assertSame([], $this->portage()->preview($payload)->warnings);

        Craft::$app->getUser()->setIdentity($jane);
        $first = $this->portage()->import($payload);
        Schema::setImportDate($first->logId, '2026-11-12 10:15:00');

        $plan = $this->portage()->preview($payload);
        $this->assertFalse($plan->isRefused());
        $this->assertSame(['This Payload was already imported here on 12 Nov 2026 at 10:15 by "Jane Doe".'], $plan->warnings);

        $second = $this->portage()->import($payload);
        $this->assertSame('created', $second->roots[0]->status);
        $this->assertCount(2, Plugin::getInstance()->getImportLog()->getEntries());

        $other = $this->portage()->export([Schema::entry($news, $article, ['title' => 'Other'])]);
        $this->assertSame([], $this->portage()->preview($other)->warnings);
    }

    public function testTheDuplicateWarningNamesEachEarlierImportAndCopesWithADeletedUser(): void
    {
        $jane = Schema::user('jane', ['firstName' => 'Jane', 'lastName' => 'Doe']);
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $payload = $this->portage()->export([Schema::entry($news, $article, ['title' => 'Thrice'])]);

        Craft::$app->getUser()->setIdentity($jane);
        $first = $this->portage()->import($payload);
        Schema::setImportDate($first->logId, '2026-11-10 08:05:00');
        $second = $this->portage()->import($payload);
        Schema::setImportDate($second->logId, '2026-11-12 10:15:00');
        Craft::$app->getUser()->setIdentity(null);
        Schema::deleteElement($jane);

        $this->assertSame(
            [
                'This Payload was already imported here on 12 Nov 2026 at 10:15 by a user that no longer exists.',
                'This Payload was already imported here on 10 Nov 2026 at 08:05 by a user that no longer exists.',
            ],
            $this->portage()->preview($payload)->warnings,
        );
    }

    public function testOverwriteWorksOnAnEntryMatchedByNaturalKeyAndIsRefusedWhenItMatchesInAnotherSection(): void
    {
        $article = Schema::entryType('article', [Schema::plainText('summary')]);
        $news = Schema::section('news', [$article]);
        Schema::section('blog', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Same slug', 'slug' => 'same-slug', 'summary' => 'Staging']);
        $payload = $this->portage()->export([$source]);
        $sourceUid = $source->uid;
        $refusal = 'The Root element "Same slug" can\'t be overwritten: it matches "Same slug" in the "News" section, not in the "Blog" section it will be imported into.';
        $this->assertSame($refusal, $this->portage()->preview($payload, [], [$sourceUid => ['mode' => 'overwrite', 'section' => 'blog']])->refusal);

        Schema::changeUid($source);
        Schema::updateEntry($source, ['summary' => 'Prod']);

        $result = $this->portage()->import($payload, [], [$sourceUid => ['mode' => 'overwrite']]);

        $this->assertSame('overwritten', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertSame($source->id, $result->roots[0]->elementId);
        $this->assertSame('Staging', Entry::find()->draftId($result->roots[0]->draftId)->status(null)->one()->getFieldValue('summary'));
        $this->assertSame('Prod', Entry::find()->id($source->id)->status(null)->one()->getFieldValue('summary'));
    }

    public function testOverwriteMatchesAStructureEntryByItsParentSlugPath(): void
    {
        $page = Schema::entryType('page', [Schema::plainText('summary')]);
        $pages = Schema::section('pages', [$page], ['type' => Section::TYPE_STRUCTURE]);
        $about = Schema::entry($pages, $page, ['title' => 'About', 'slug' => 'about']);
        $contact = Schema::entry($pages, $page, ['title' => 'Contact', 'slug' => 'contact']);
        $aboutTeam = Schema::entry($pages, $page, ['title' => 'Team', 'slug' => 'team', 'summary' => 'Staging about team', 'parentId' => $about->id]);
        Schema::entry($pages, $page, ['title' => 'Team', 'slug' => 'team', 'summary' => 'Staging contact team', 'parentId' => $contact->id]);
        $payload = $this->portage()->export([$aboutTeam]);
        $uid = $aboutTeam->uid;
        Schema::changeUid($aboutTeam);

        $plan = $this->portage()->preview($payload, [], [$uid => ['mode' => 'overwrite']]);

        $this->assertSame([$aboutTeam->id, 'naturalKey'], [$plan->roots[0]->match->elementId, $plan->roots[0]->match->rule]);
        $this->assertSame('overwrite', $plan->roots[0]->mode);
    }

    public function testOverwriteSavesTheStubChosenForAnUnresolvedReference(): void
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $gone = Schema::entry($topics, $topic, ['title' => 'Gone']);
        $article = Schema::entryType('article', [Schema::field(Entries::class, 'relatedTopics')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Related', 'relatedTopics' => [$gone->id]]);
        $payload = $this->portage()->export([$source]);
        $goneUid = $gone->uid;
        Schema::deleteElement($gone);

        $result = $this->portage()->import($payload, [$goneUid => 'stub'], [$source->uid => ['mode' => 'overwrite']]);

        $this->assertSame('overwritten', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $stub = Entry::find()->uid($goneUid)->status(null)->one();
        $this->assertNotNull($stub);
        $this->assertSame([[$goneUid, 'Gone', $stub->id]], array_map(fn(array $s) => [$s['uid'], $s['title'], $s['elementId']], $result->roots[0]->stubs));
        $draft = Entry::find()->draftId($result->roots[0]->draftId)->status(null)->one();
        $this->assertSame([$stub->id], $draft->getFieldValue('relatedTopics')->status(null)->ids());
    }

    public function testAUidMatchInAnotherSectionDoesNotHideANaturalKeyMatchInTheTargetSection(): void
    {
        $article = Schema::entryType('article', [Schema::plainText('summary')]);
        $news = Schema::section('news', [$article]);
        Schema::section('blog', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Moved', 'slug' => 'moved', 'summary' => 'Staging']);
        $payload = $this->portage()->export([$source]);
        $choices = [$source->uid => ['section' => 'blog']];
        $created = $this->portage()->import($payload, [], $choices);
        Schema::updateEntry(Entry::find()->id($created->roots[0]->elementId)->status(null)->one(), ['summary' => 'Prod']);

        $plan = $this->portage()->preview($payload, [], $choices);

        $this->assertSame(['create', 'overwrite'], $plan->roots[0]->availableModes);
        $this->assertSame([$created->roots[0]->elementId, 'naturalKey'], [$plan->roots[0]->match->elementId, $plan->roots[0]->match->rule]);

        $result = $this->portage()->import($payload, [], [$source->uid => ['section' => 'blog', 'mode' => 'overwrite']]);

        $this->assertSame('overwritten', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertSame($created->roots[0]->elementId, $result->roots[0]->elementId);
    }

    public function testTheStarChoiceIsTheDefaultForEveryRootElementAndPerUidChoicesOverrideIt(): void
    {
        $article = Schema::entryType('article', [Schema::plainText('summary')]);
        $news = Schema::section('news', [$article]);
        $blog = Schema::section('blog', [$article]);
        $matched = Schema::entry($news, $article, ['title' => 'Matched', 'slug' => 'matched', 'summary' => 'Staging']);
        $unmatched = Schema::entry($news, $article, ['title' => 'Unmatched', 'slug' => 'unmatched']);
        $payload = $this->portage()->export([$matched, $unmatched]);
        $unmatchedUid = $unmatched->uid;
        Schema::deleteElement($unmatched);

        $plan = $this->portage()->preview($payload, [], ['*' => ['mode' => 'overwrite']]);
        $this->assertFalse($plan->isRefused(), (string)$plan->refusal);
        $this->assertSame(['overwrite', 'create'], [$plan->roots[0]->mode, $plan->roots[1]->mode]);

        $explicit = $this->portage()->preview($payload, [], ['*' => ['mode' => 'overwrite'], $matched->uid => ['mode' => 'create']]);
        $this->assertSame(['create', 'create'], [$explicit->roots[0]->mode, $explicit->roots[1]->mode]);

        $refused = $this->portage()->preview($payload, [], ['*' => ['mode' => 'overwrite'], $unmatchedUid => ['mode' => 'overwrite']]);
        $this->assertSame(
            'The Root element "Unmatched" can\'t be overwritten: no entry in this environment matches it by UID or by its section and slug. Create it as a new entry instead.',
            $refused->refusal,
        );

        $inBlog = $this->portage()->preview($payload, [], ['*' => ['mode' => 'overwrite', 'section' => 'blog']]);
        $this->assertFalse($inBlog->isRefused(), (string)$inBlog->refusal);
        $this->assertSame([$blog->id, $blog->id], [$inBlog->roots[0]->section->id, $inBlog->roots[1]->section->id]);
        $this->assertSame(['create', 'create'], [$inBlog->roots[0]->mode, $inBlog->roots[1]->mode]);

        $result = $this->portage()->import($payload, [], ['*' => ['mode' => 'overwrite']]);
        $this->assertSame(['overwritten', 'created'], [$result->roots[0]->status, $result->roots[1]->status]);
    }

    public function testTheImportLogRecordsEachRootsModeAndTargetSection(): void
    {
        $article = Schema::entryType('article');
        $news = Schema::section('news', [$article]);
        $blog = Schema::section('blog', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Logged']);
        $payload = $this->portage()->export([$source]);

        $this->portage()->import($payload, [], [$source->uid => ['mode' => 'overwrite']]);
        $this->portage()->import($payload, [], [$source->uid => ['section' => 'blog']]);

        $entries = Plugin::getInstance()->getImportLog()->getEntries();
        $this->assertSame(['overwrite', $news->uid], [$entries[1]->results[0]['mode'], $entries[1]->results[0]['section']]);
        $this->assertSame(['create', $blog->uid], [$entries[0]->results[0]['mode'], $entries[0]->results[0]['section']]);
    }

    /**
     * @return list<string> the bodies of an entry's text blocks, in order
     */
    private function bodies(Entry $entry): array
    {
        return array_map(fn(Entry $block) => $block->getFieldValue('body'), $entry->getFieldValue('contentBlocks')->status(null)->all());
    }

    private function portage(): Portage
    {
        return Plugin::getInstance()->getPortage();
    }
}
