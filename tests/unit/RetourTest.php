<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use craft\db\Query;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use nystudio107\retour\fields\ShortLink;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\tests\support\Schema;

/**
 * Retour short links: the URL travels as it is, and Retour makes the imported entry's redirect when it saves. A short
 * link whose URL is already another entry's, or a manual redirect, is skipped with a warning.
 */
class RetourTest extends Unit
{
    public function testTheShortLinkTravelsAndRetourRedirectsItToTheImportedEntry(): void
    {
        [$news, $article, $field] = $this->newsWithShortLinks();
        $source = Schema::entry($news, $article, ['title' => 'Summer sale', 'shortLink' => 'go/summer']);

        $payload = $this->portage()->export([$source]);
        $preview = $this->portage()->preview($payload);
        $this->assertSame('go/summer', $preview->envelope['roots'][0]['fields'][$field->uid] ?? null);
        Schema::deleteElement($source);
        $this->assertSame([], $this->redirects());

        $result = $this->portage()->import($payload);

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
        $payload = $this->portage()->export([$source]);

        $warning = 'The short link "go/summer" in the field "Short Link" is already the short link of the entry "Summer sale" in this environment. That value will be skipped.';
        $this->assertSame([$warning], $this->portage()->preview($payload)->roots[0]->warnings);

        $result = $this->portage()->import($payload);

        $this->assertSame('created', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertNull($this->entry($result->roots[0]->elementId)->getFieldValue('shortLink'));
        $this->assertSame([['/go/summer', $source->id]], $this->redirects());
        $this->assertSame([$warning], $result->roots[0]->warnings);
    }

    public function testAShortLinkThatIsAManualRedirectIsSkipped(): void
    {
        [$news, $article] = $this->newsWithShortLinks();
        $source = Schema::entry($news, $article, ['title' => 'Summer sale', 'shortLink' => 'go/summer']);
        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::retourRedirect('go/summer', 'offers/summer');

        $result = $this->portage()->import($payload);

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
        $payload = $this->portage()->export([$source]);
        // Retour saves the redirect from the site's full URL, which its own validation doesn't look up.
        $this->assertSame([['http://test.craftcms.test/index.php?p=go/summer', $source->id]], $this->redirects());

        $result = $this->portage()->import($payload);

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
        $payload = $this->portage()->export([$source]);

        $result = $this->portage()->import($payload, [], [$source->uid => ['mode' => 'overwrite']]);

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
        $payload = $this->portage()->export([$source]);
        Schema::updateEntry($source, ['shortLink' => 'go/sale']);

        $result = $this->portage()->import($payload, [], [$source->uid => ['mode' => 'overwrite']]);

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
        $payload = $this->portage()->export([$source]);
        Schema::updateEntry($source, ['shortLink' => 'go/sale']);
        $other = Schema::entry($news, $article, ['title' => 'Summer offers', 'shortLink' => 'go/summer']);

        $result = $this->portage()->import($payload, [], [$source->uid => ['mode' => 'overwrite']]);

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

    private function portage(): Portage
    {
        return Plugin::getInstance()->getPortage();
    }
}
