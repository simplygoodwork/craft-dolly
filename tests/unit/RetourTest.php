<?php

namespace simplygoodwork\craftdolly\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use nystudio107\retour\fields\ShortLink;
use simplygoodwork\craftdolly\Plugin;
use simplygoodwork\craftdolly\services\Dolly;
use simplygoodwork\craftdolly\tests\support\Requires;
use simplygoodwork\craftdolly\tests\support\Schema;

/**
 * Retour short links: the URL travels as it is, and Retour makes the imported entry's redirect when it saves. A short
 * link whose URL is already another entry's, or a manual redirect, or that an earlier Root element of the Payload
 * takes, is skipped with a warning. A short link in a block redirects to the entry that owns the block.
 */
class RetourTest extends Unit
{
    public function testTheShortLinkTravelsAndRetourRedirectsItToTheImportedEntry(): void
    {
        [$news, $article, $field] = $this->newsWithShortLinks();
        $source = Schema::entry($news, $article, ['title' => 'Summer sale', 'shortLink' => 'go/summer']);

        $payload = $this->dolly()->export([$source]);
        $preview = $this->dolly()->preview($payload);
        $this->assertSame('go/summer', $preview->envelope['roots'][0]['fields'][$field->uid] ?? null);
        Schema::deleteElement($source);
        $this->assertSame([], $this->redirects());

        $result = $this->dolly()->import($payload);

        $this->assertSame('created', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $id = $result->roots[0]->elementId;
        $this->assertSame('go/summer', $this->entry($id)->getFieldValue('shortLink'));
        $this->assertSame([['/go/summer', $id]], $this->redirects());
        $this->assertSame([], $result->roots[0]->warnings);
    }

    public function testAShortLinkThatIsAnotherEntrysIsSkipped(): void
    {
        [$news, $article] = $this->newsWithShortLinks();
        $source = Schema::entry($news, $article, ['title' => 'Summer sale', 'shortLink' => 'go/summer']);
        $payload = $this->dolly()->export([$source]);

        $warning = 'The short link "go/summer" in the field "Short Link" is already the short link of the entry "Summer sale" in this environment. That value will be skipped.';
        $this->assertSame([$warning], $this->dolly()->preview($payload)->roots[0]->warnings);

        $result = $this->dolly()->import($payload);

        $this->assertSame('created', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertNull($this->entry($result->roots[0]->elementId)->getFieldValue('shortLink'));
        $this->assertSame([['/go/summer', $source->id]], $this->redirects());
        $this->assertSame([$warning], $result->roots[0]->warnings);
    }

    public function testAShortLinkThatIsAManualRedirectIsSkipped(): void
    {
        [$news, $article] = $this->newsWithShortLinks();
        $source = Schema::entry($news, $article, ['title' => 'Summer sale', 'shortLink' => 'go/summer']);
        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        Schema::retourRedirect('go/summer', 'offers/summer');

        $result = $this->dolly()->import($payload);

        $this->assertSame('created', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertNull($this->entry($result->roots[0]->elementId)->getFieldValue('shortLink'));
        $this->assertSame([['/go/summer', 0]], $this->redirects());
        $this->assertSame(
            ['The short link "go/summer" in the field "Short Link" is already a redirect in Retour in this environment. That value will be skipped.'],
            $result->roots[0]->warnings,
        );
    }

    public function testAFullUrlShortLinkIsCheckedAgainstTheUrlRetourSaves(): void
    {
        [$news, $article] = $this->newsWithShortLinks(['redirectSrcMatch' => 'fullurl']);
        $source = Schema::entry($news, $article, ['title' => 'Summer sale', 'shortLink' => 'go/summer']);
        $payload = $this->dolly()->export([$source]);
        // Retour saves the redirect from the site's full URL, which its own validation doesn't look up.
        $this->assertSame([['http://test.craftcms.test/index.php?p=go/summer', $source->id]], $this->redirects());

        $result = $this->dolly()->import($payload);

        $this->assertNull($this->entry($result->roots[0]->elementId)->getFieldValue('shortLink'));
        $this->assertSame([['http://test.craftcms.test/index.php?p=go/summer', $source->id]], $this->redirects());
        $this->assertSame(
            ['The short link "go/summer" in the field "Short Link" is already the short link of the entry "Summer sale" in this environment. That value will be skipped.'],
            $result->roots[0]->warnings,
        );
    }

    public function testOverwritingTheEntryKeepsItsOwnShortLink(): void
    {
        [$news, $article] = $this->newsWithShortLinks();
        $source = Schema::entry($news, $article, ['title' => 'Summer sale', 'shortLink' => 'go/summer']);
        $payload = $this->dolly()->export([$source]);

        $result = $this->dolly()->import($payload, [], [$source->uid => ['mode' => 'overwrite']]);

        $root = $result->roots[0];
        $this->assertSame('overwritten', $root->status, implode(' ', $root->errors));
        $this->assertSame([], $root->warnings);
        $this->assertSame('go/summer', Entry::find()->draftId($root->draftId)->status(null)->one()?->getFieldValue('shortLink'));

        Schema::applyDraft((int)$root->draftId);

        $this->assertSame('go/summer', $this->entry($source->id)->getFieldValue('shortLink'));
        $this->assertSame([['/go/summer', $source->id]], $this->redirects());
    }

    public function testOverwritingTheEntryChangesItsShortLinkWhenTheDraftIsApplied(): void
    {
        [$news, $article] = $this->newsWithShortLinks();
        $source = Schema::entry($news, $article, ['title' => 'Summer sale', 'shortLink' => 'go/summer']);
        $payload = $this->dolly()->export([$source]);
        Schema::updateEntry($source, ['shortLink' => 'go/sale']);

        $result = $this->dolly()->import($payload, [], [$source->uid => ['mode' => 'overwrite']]);

        $root = $result->roots[0];
        $this->assertSame([], $root->warnings);
        $this->assertSame('go/summer', Entry::find()->draftId($root->draftId)->status(null)->one()?->getFieldValue('shortLink'));
        $this->assertSame('go/sale', $this->entry($source->id)->getFieldValue('shortLink'));

        Schema::applyDraft((int)$root->draftId);

        $this->assertSame('go/summer', $this->entry($source->id)->getFieldValue('shortLink'));
        $this->assertSame([['/go/summer', $source->id]], $this->redirects());
    }

    public function testOverwritingTheEntryKeepsItsShortLinkWhenThePayloadsIsAnotherEntrys(): void
    {
        [$news, $article] = $this->newsWithShortLinks();
        $source = Schema::entry($news, $article, ['title' => 'Summer sale', 'shortLink' => 'go/summer']);
        $payload = $this->dolly()->export([$source]);
        Schema::updateEntry($source, ['shortLink' => 'go/sale']);
        $other = Schema::entry($news, $article, ['title' => 'Summer offers', 'shortLink' => 'go/summer']);

        $result = $this->dolly()->import($payload, [], [$source->uid => ['mode' => 'overwrite']]);

        $root = $result->roots[0];
        $this->assertSame('overwritten', $root->status, implode(' ', $root->errors));
        $this->assertSame(
            ['The short link "go/summer" in the field "Short Link" is already the short link of the entry "Summer offers" in this environment. That value will be skipped.'],
            $root->warnings,
        );
        $this->assertSame('go/sale', Entry::find()->draftId($root->draftId)->status(null)->one()?->getFieldValue('shortLink'));

        Schema::applyDraft((int)$root->draftId);

        $this->assertSame('go/sale', $this->entry($source->id)->getFieldValue('shortLink'));
        $this->assertSame('go/summer', $this->entry($other->id)->getFieldValue('shortLink'));
        $this->assertSame([['/go/sale', $source->id], ['/go/summer', $other->id]], $this->redirects());
    }

    public function testAShortLinkInABlockRedirectsToTheEntryThatOwnsIt(): void
    {
        [$news, $article] = $this->newsWithShortLinksInBlocks();
        $source = Schema::entry($news, $article, ['title' => 'Summer sale', 'blocks' => $this->promo('go/summer')]);
        // Retour ties the redirect to the entry at the top, not to the block.
        $this->assertSame([['/go/summer', $source->id]], $this->redirects());
        $payload = $this->dolly()->export([$source]);
        Schema::deleteElement($source);
        // Retour keeps the redirect of a short link in a block when its entry is deleted.
        Schema::deleteRetourRedirect('/go/summer');

        $result = $this->dolly()->import($payload);

        $root = $result->roots[0];
        $this->assertSame('created', $root->status, implode(' ', $root->errors));
        $this->assertSame([], $root->warnings);
        $this->assertSame(['go/summer'], $this->shortLinksInBlocks($this->entry($root->elementId)));
        $this->assertSame([['/go/summer', $root->elementId]], $this->redirects());
    }

    public function testOverwritingTheEntryKeepsTheShortLinkInItsBlock(): void
    {
        [$news, $article] = $this->newsWithShortLinksInBlocks();
        $source = Schema::entry($news, $article, ['title' => 'Summer sale', 'blocks' => $this->promo('go/summer')]);
        $payload = $this->dolly()->export([$source]);

        $result = $this->dolly()->import($payload, [], [$source->uid => ['mode' => 'overwrite']]);

        $root = $result->roots[0];
        $this->assertSame('overwritten', $root->status, implode(' ', $root->errors));
        $this->assertSame([], $root->warnings);
        $this->assertSame(['go/summer'], $this->shortLinksInBlocks(Entry::find()->draftId($root->draftId)->status(null)->one()));

        Schema::applyDraft((int)$root->draftId);

        $this->assertSame(['go/summer'], $this->shortLinksInBlocks($this->entry($source->id)));
        $this->assertSame([['/go/summer', $source->id]], $this->redirects());
    }

    public function testPastingABlockIntoItsOwnEntryKeepsItsShortLink(): void
    {
        $this->pastingAsAnAdmin();
        [$news, $article] = $this->newsWithShortLinksInBlocks();
        $source = Schema::entry($news, $article, ['title' => 'Summer sale', 'blocks' => $this->promo('go/summer')]);
        $payload = $this->dolly()->export($this->blocks($source));
        $draft = Schema::draft($source, [], provisional: true);

        $result = $this->dolly()->import($payload, target: Schema::blockTarget($draft, 'blocks'));

        $root = $result->roots[0];
        $this->assertSame('pasted', $root->status, implode(' ', $root->errors));
        $this->assertSame([], $root->warnings);
        $this->assertSame(['go/summer', 'go/summer'], $this->shortLinksInBlocks($this->draftOf($draft)));
        $this->assertSame([['/go/summer', $source->id]], $this->redirects());
    }

    public function testPastingABlockIntoAnotherEntrySkipsItsShortLink(): void
    {
        $this->pastingAsAnAdmin();
        [$news, $article] = $this->newsWithShortLinksInBlocks();
        $source = Schema::entry($news, $article, ['title' => 'Summer sale', 'blocks' => $this->promo('go/summer')]);
        $payload = $this->dolly()->export($this->blocks($source));
        $other = Schema::entry($news, $article, ['title' => 'Winter sale']);
        $draft = Schema::draft($other, [], provisional: true);

        $result = $this->dolly()->import($payload, target: Schema::blockTarget($draft, 'blocks'));

        $root = $result->roots[0];
        $this->assertSame('pasted', $root->status, implode(' ', $root->errors));
        $this->assertSame(
            ['The short link "go/summer" in the field "Short Link" is already the short link of the entry "Summer sale" in this environment. That value will be skipped.'],
            $root->warnings,
        );
        $this->assertSame([null], $this->shortLinksInBlocks($this->draftOf($draft)));
        $this->assertSame([['/go/summer', $source->id]], $this->redirects());
    }

    public function testPastingABlockIntoANestedEntryOfItsOwnEntryKeepsItsShortLink(): void
    {
        $this->pastingAsAnAdmin();
        $promo = Schema::blockType('promo', [Schema::field(ShortLink::class, 'shortLink', ['name' => 'Short Link'])]);
        $group = Schema::blockType('group', [Schema::matrix('blocks', [$promo])]);
        $article = Schema::entryType('article', [Schema::matrix('groups', [$group])]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Summer sale', 'groups' => [
            'new1' => ['type' => 'group', 'fields' => ['blocks' => $this->promo('go/summer')]],
        ]]);
        $this->assertSame([['/go/summer', $source->id]], $this->redirects());
        $groupEntry = $source->getFieldValue('groups')->status(null)->one();
        $payload = $this->dolly()->export($this->blocks($groupEntry));
        $draft = Schema::draft($groupEntry, [], provisional: true);

        $result = $this->dolly()->import($payload, target: Schema::blockTarget($draft, 'blocks'));

        $root = $result->roots[0];
        $this->assertSame('pasted', $root->status, implode(' ', $root->errors));
        $this->assertSame([], $root->warnings);
        $this->assertSame(['go/summer', 'go/summer'], $this->shortLinksInBlocks($this->draftOf($draft)));
        $this->assertSame([['/go/summer', $source->id]], $this->redirects());
    }

    public function testTwoRootElementsWithTheSameShortLinkKeepItForTheFirst(): void
    {
        [$news, $article] = $this->newsWithShortLinks();
        $first = Schema::entry($news, $article, ['title' => 'Summer sale', 'shortLink' => 'go/summer']);
        $second = Schema::entry($news, $article, ['title' => 'Summer offers', 'shortLink' => 'go/offers']);
        // Applying a draft skips Retour's validation, so the second entry takes the first one's short link over.
        Schema::applyDraft((int)Schema::draft($second, ['shortLink' => 'go/summer'])->draftId);
        $payload = $this->dolly()->export([$first, $this->entry($second->id)]);
        Schema::deleteElement($first);
        Schema::deleteElement($second);
        $this->assertSame([], $this->redirects());

        $warning = 'The short link "go/summer" in the field "Short Link" is also the short link of the Root element "Summer sale" in this Payload, which is imported first. That value will be skipped.';
        $preview = $this->dolly()->preview($payload);
        $this->assertSame([], $preview->roots[0]->warnings);
        $this->assertSame([$warning], $preview->roots[1]->warnings);

        $result = $this->dolly()->import($payload);

        $this->assertSame(['created', 'created'], array_map(fn($root) => $root->status, $result->roots));
        $this->assertSame('go/summer', $this->entry($result->roots[0]->elementId)->getFieldValue('shortLink'));
        $this->assertNull($this->entry($result->roots[1]->elementId)->getFieldValue('shortLink'));
        $this->assertSame([['/go/summer', $result->roots[0]->elementId]], $this->redirects());
        $this->assertSame([$warning], $result->roots[1]->warnings);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{0: Section, 1: EntryType, 2: ShortLink}
     */
    private function newsWithShortLinks(array $config = []): array
    {
        $field = Schema::field(ShortLink::class, 'shortLink', ['name' => 'Short Link'] + $config);
        $this->assertInstanceOf(ShortLink::class, $field);
        $article = Schema::entryType('article', [$field]);

        return [Schema::section('news', [$article]), $article, $field];
    }

    /**
     * A section whose entries have a Matrix field "blocks" of promo blocks, each with a short link.
     *
     * @return array{0: Section, 1: EntryType}
     */
    private function newsWithShortLinksInBlocks(): array
    {
        $promo = Schema::blockType('promo', [Schema::field(ShortLink::class, 'shortLink', ['name' => 'Short Link'])]);
        $article = Schema::entryType('article', [Schema::matrix('blocks', [$promo])]);

        return [Schema::section('news', [$article]), $article];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function promo(string $shortLink): array
    {
        return ['new1' => ['type' => 'promo', 'fields' => ['shortLink' => $shortLink]]];
    }

    /**
     * @return list<Entry>
     */
    private function blocks(Entry $owner): array
    {
        return array_values($owner->getFieldValue('blocks')->status(null)->all());
    }

    /**
     * @return list<string|null>
     */
    private function shortLinksInBlocks(?Entry $owner): array
    {
        $this->assertNotNull($owner);

        return array_map(fn(Entry $block) => $block->getFieldValue('shortLink'), $this->blocks($owner));
    }

    /**
     * Blocks are pasted by a user with permission to edit the draft, on Craft 5.7+.
     */
    private function pastingAsAnAdmin(): void
    {
        Requires::craft('5.7.0', 'Block copy/paste');
        Craft::$app->getUser()->setIdentity(User::find()->admin()->one());
    }

    /**
     * Returns the saved draft again, as the editor would load it.
     */
    private function draftOf(Entry $draft): Entry
    {
        return Entry::find()->id($draft->id)->siteId($draft->siteId)->drafts(null)->provisionalDrafts(null)->status(null)->one();
    }

    /**
     * Returns Retour's redirects, as their source URL and the ID of the element they belong to (0 for a manual one).
     *
     * @return list<array{0: string, 1: int}>
     */
    private function redirects(): array
    {
        $rows = (new Query())
            ->select(['redirectSrcUrl', 'associatedElementId'])
            ->from(['{{%retour_static_redirects}}'])
            ->orderBy(['redirectSrcUrl' => SORT_ASC])
            ->all();

        return array_map(fn(array $row) => [(string)$row['redirectSrcUrl'], (int)$row['associatedElementId']], $rows);
    }

    private function entry(?int $id): Entry
    {
        $this->assertNotNull($id, 'The Import result has no element ID');
        $entry = Entry::find()->id($id)->status(null)->one();
        $this->assertNotNull($entry, "No entry with ID $id");

        return $entry;
    }

    private function dolly(): Dolly
    {
        return Plugin::getInstance()->getDolly();
    }
}
