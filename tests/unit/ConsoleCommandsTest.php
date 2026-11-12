<?php

namespace simplygoodwork\craftportage\tests\unit;

use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Entries;
use craft\fields\Users;
use craft\test\console\ConsoleTest;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\tests\support\Schema;
use yii\console\ExitCode;

/**
 * Light coverage of `portage/export` and `portage/import`: the behaviour itself is covered through the core service.
 */
class ConsoleCommandsTest extends ConsoleTest
{
    private string $file;

    protected function _before(): void
    {
        parent::_before();
        $this->file = sys_get_temp_dir() . '/portage-test-' . uniqid() . '.portage';
    }

    protected function _after(): void
    {
        if (file_exists($this->file)) {
            unlink($this->file);
        }
        parent::_after();
    }

    public function testExportsToAFileAndImportsFromTheFile(): void
    {
        $source = $this->sourceEntry('From a file');

        $this->consoleCommand('portage/export', [(string)$source->id, 'output' => $this->file], true)
            ->exitCode(ExitCode::OK)
            ->run();
        $this->assertStringStartsWith('portage:v1:', (string)file_get_contents($this->file));

        Craft::$app->getElements()->deleteElement($source, true);
        $this->consoleCommand('portage/import', ['file' => $this->file, 'user' => $this->importerEmail()], true)
            ->exitCode(ExitCode::OK)
            ->run();

        $imported = Entry::find()->uid($source->uid)->status(null)->one();
        $this->assertNotNull($imported);
        $this->assertSame('From a file', $imported->title);
        $this->assertFalse($imported->enabled);
    }

    public function testImportsAPayloadPassedAsAnArgument(): void
    {
        $source = $this->sourceEntry('From an argument');
        $payload = Plugin::getInstance()->getPortage()->export([$source]);
        Craft::$app->getElements()->deleteElement($source, true);

        $this->consoleCommand('portage/import', [$payload, 'user' => $this->importerEmail()], true)
            ->exitCode(ExitCode::OK)
            ->run();

        $this->assertNotNull(Entry::find()->uid($source->uid)->status(null)->one());
    }

    public function testPreviewFlagPrintsThePreviewAndWritesNothing(): void
    {
        $source = $this->sourceEntry('Preview only');
        $payload = Plugin::getInstance()->getPortage()->export([$source]);
        Craft::$app->getElements()->deleteElement($source, true);

        $this->consoleCommand('portage/import', [$payload, 'dryRun' => true, 'user' => $this->importerEmail()], false)
            ->stdout('Preview only: nothing has been imported.' . PHP_EOL)
            ->stdout("Would create a disabled entry \"Preview only\" from $source->uid." . PHP_EOL)
            ->stdout('  No existing entry matches. Modes: create. Chosen: create.' . PHP_EOL)
            ->stdout('  Target section: "News".' . PHP_EOL)
            ->exitCode(ExitCode::OK)
            ->run();

        $this->assertNull(Entry::find()->uid($source->uid)->status(null)->one());
        $this->assertSame([], Plugin::getInstance()->getImportLog()->getEntries());
    }

    public function testRefusesAMalformedPayload(): void
    {
        $this->consoleCommand('portage/import', ['not a payload', 'user' => $this->importerEmail()], true)
            ->stderr('Payload refused: This is not a Portage Payload: it should start with "portage:v1:".' . PHP_EOL)
            ->exitCode(ExitCode::DATAERR)
            ->run();
    }

    public function testExportFailsForAnUnknownEntryId(): void
    {
        $this->consoleCommand('portage/export', ['999999'], true)
            ->stderr('No entry found with ID 999999.' . PHP_EOL)
            ->exitCode(ExitCode::DATAERR)
            ->run();
    }

    public function testPreviewFlagPrintsTheResolutionOfEachReferenceAndTheChoiceForUnresolvedOnes(): void
    {
        [$payload, $kept, $gone] = $this->payloadRelatingTo('Kept', 'Gone');

        $this->consoleCommand('portage/import', [$payload, 'dryRun' => true, 'stub' => $gone, 'user' => $this->importerEmail()], false)
            ->stdout('Preview only: nothing has been imported.' . PHP_EOL)
            ->stdout("Reference \"Kept\" (entry, $kept) matched by UID." . PHP_EOL)
            ->stdout("Unresolved reference \"Gone\" (entry, $gone): will be Stubbed. Choices: skip, stub, replace." . PHP_EOL)
            ->stdout('Would create a disabled entry "Related" from ' . $this->sourceUid . '.' . PHP_EOL)
            ->stdout('  No existing entry matches. Modes: create. Chosen: create.' . PHP_EOL)
            ->stdout('  Target section: "News".' . PHP_EOL)
            ->exitCode(ExitCode::OK)
            ->run();
    }

    public function testImportTakesChoicesFromOptionsAndFromAChoicesFile(): void
    {
        [$payload, $kept, $gone] = $this->payloadRelatingTo('Kept', 'Gone');
        $keptId = Entry::find()->uid($kept)->one()->id;
        $topics = Craft::$app->getEntries()->getSectionByHandle('topics');
        $instead = Schema::entry($topics, $topics->getEntryTypes()[0], ['title' => 'Instead']);
        file_put_contents($this->file, json_encode([$gone => $instead->id]));

        $this->consoleCommand('portage/import', [$payload, 'choices' => $this->file, 'user' => $this->importerEmail()], true)
            ->exitCode(ExitCode::OK)
            ->run();
        $this->assertSame([$keptId, $instead->id], $this->relatedIds());

        Craft::$app->getElements()->deleteElement(Entry::find()->uid($this->sourceUid)->status(null)->one(), true);
        $this->consoleCommand('portage/import', [$payload, 'stub' => $gone, 'user' => $this->importerEmail()], true)
            ->exitCode(ExitCode::OK)
            ->run();
        $stub = Entry::find()->uid($gone)->status(null)->one();
        $this->assertNotNull($stub);
        $this->assertSame([$keptId, $stub->id], $this->relatedIds());
    }

    public function testRefusesAStubForAUser(): void
    {
        $user = Schema::user('gone');
        $entryType = Schema::entryType('article', [Schema::field(Users::class, 'contacts')]);
        $section = Schema::section('news', [$entryType]);
        $source = Schema::entry($section, $entryType, ['title' => 'Contacts', 'contacts' => [$user->id]]);
        $payload = Plugin::getInstance()->getPortage()->export([$source]);
        Craft::$app->getElements()->deleteElement($source, true);
        Craft::$app->getElements()->deleteElement($user, true);

        $this->consoleCommand('portage/import', [$payload, 'stub' => $user->uid, 'user' => $this->importerEmail()], true)
            ->stderr('Payload refused: The user "gone" can\'t be Stubbed: Portage never creates users. Skip it or choose a replacement user.' . PHP_EOL)
            ->exitCode(ExitCode::DATAERR)
            ->run();
    }

    public function testImportRequiresTheActiveUserItRunsAs(): void
    {
        $source = $this->sourceEntry('Needs a user');
        $payload = Plugin::getInstance()->getPortage()->export([$source]);
        Craft::$app->getElements()->deleteElement($source, true);

        $this->consoleCommand('portage/import', [$payload], true)
            ->stderr('Pass --user with the email of the user to run the Import as. Unresolved authors are credited to them.' . PHP_EOL)
            ->exitCode(ExitCode::USAGE)
            ->run();
        $this->consoleCommand('portage/import', [$payload, 'user' => 'nobody@example.com'], true)
            ->stderr('No active user has the email nobody@example.com.' . PHP_EOL)
            ->exitCode(ExitCode::USAGE)
            ->run();

        $this->assertNull(Entry::find()->uid($source->uid)->status(null)->one());
    }

    public function testCreditsAnUnresolvedAuthorToTheUserTheImportRunsAs(): void
    {
        $gone = Schema::user('gone');
        $entryType = Schema::entryType('article');
        $section = Schema::section('news', [$entryType]);
        $source = Schema::entry($section, $entryType, ['title' => 'Authored', 'authorIds' => [$gone->id]]);
        $payload = Plugin::getInstance()->getPortage()->export([$source]);
        Craft::$app->getElements()->deleteElement($source, true);
        Craft::$app->getElements()->deleteElement($gone, true);
        $importer = Schema::user('importer', ['admin' => true]);

        $this->consoleCommand('portage/import', [$payload, 'dryRun' => true, 'user' => 'importer@example.com'], false)
            ->stdout('Preview only: nothing has been imported.' . PHP_EOL)
            ->stdout("Unresolved reference \"gone\" (user, $gone->uid): will be skipped in user fields; as an author, \"importer\" is credited instead. Choices: skip, replace." . PHP_EOL)
            ->stdout("Would create a disabled entry \"Authored\" from $source->uid." . PHP_EOL)
            ->stdout('  No existing entry matches. Modes: create. Chosen: create.' . PHP_EOL)
            ->stdout('  Target section: "News".' . PHP_EOL)
            ->stdout('  Warning: The author "gone" doesn\'t exist in this environment. "importer" will be credited instead.' . PHP_EOL)
            ->exitCode(ExitCode::OK)
            ->run();

        $this->consoleCommand('portage/import', [$payload, 'user' => 'importer@example.com'], true)
            ->exitCode(ExitCode::OK)
            ->run();

        $imported = Entry::find()->uid($source->uid)->status(null)->one();
        $this->assertSame([$importer->id], $imported->getAuthorIds());
        $this->assertSame($importer->id, Plugin::getInstance()->getImportLog()->getEntries()[0]->userId);
    }

    public function testOverwriteCreatesADraftOnTheMatchedEntryAndThePreviewShowsTheMatch(): void
    {
        $source = $this->sourceEntry('Staging title');
        $payload = Plugin::getInstance()->getPortage()->export([$source]);
        Schema::updateEntry($source, ['title' => 'Prod title']);

        $this->consoleCommand('portage/import', [$payload, 'dryRun' => true, 'overwrite' => '*', 'user' => $this->importerEmail()], false)
            ->stdout('Preview only: nothing has been imported.' . PHP_EOL)
            ->stdout("Would create a draft on entry #$source->id from $source->uid." . PHP_EOL)
            ->stdout("  Matches entry #$source->id by UID. Modes: create, overwrite. Chosen: overwrite." . PHP_EOL)
            ->stdout('  Target section: "News".' . PHP_EOL)
            ->exitCode(ExitCode::OK)
            ->run();

        $this->consoleCommand('portage/import', [$payload, 'overwrite' => $source->uid, 'user' => $this->importerEmail()], true)
            ->exitCode(ExitCode::OK)
            ->run();

        $logged = Plugin::getInstance()->getImportLog()->getEntries()[0]->results[0];
        $this->assertSame(['overwritten', $source->id], [$logged['status'], $logged['elementId']]);
        $this->assertNotNull($logged['draftId']);

        $this->assertSame('Prod title', Entry::find()->id($source->id)->status(null)->one()->title);
        $this->assertSame('Staging title', Entry::find()->drafts()->draftOf($source->id)->status(null)->one()->title);
    }

    public function testSectionImportsEveryRootEntryIntoThatSectionAndThePreviewWarnsAboutADuplicate(): void
    {
        $source = $this->sourceEntry('Moved');
        $article = Craft::$app->getEntries()->getEntryTypeByHandle('article');
        Schema::section('blog', [$article]);
        $payload = Plugin::getInstance()->getPortage()->export([$source]);

        $this->consoleCommand('portage/import', [$payload, 'section' => 'blog', 'user' => $this->importerEmail()], true)
            ->exitCode(ExitCode::OK)
            ->run();
        $this->assertSame(1, (int)Entry::find()->section('blog')->status(null)->count());
        Schema::setImportDate(Plugin::getInstance()->getImportLog()->getEntries()[0]->id, '2026-11-05 09:30:00');
        $blogEntryId = (int)Entry::find()->section('blog')->status(null)->one()->id;

        $this->consoleCommand('portage/import', [$payload, 'dryRun' => true, 'section' => "$source->uid=blog", 'user' => $this->importerEmail()], false)
            ->stdout('Preview only: nothing has been imported.' . PHP_EOL)
            ->stdout('Warning: This Payload was already imported here on 5 Nov 2026 at 09:30 by "importer".' . PHP_EOL)
            ->stdout("Would create a disabled entry \"Moved\" from $source->uid." . PHP_EOL)
            ->stdout("  Matches entry #$blogEntryId by Natural key. Modes: create, overwrite. Chosen: create." . PHP_EOL)
            ->stdout('  Target section: "Blog".' . PHP_EOL)
            ->exitCode(ExitCode::OK)
            ->run();
    }

    private string $sourceUid = '';

    /**
     * Exports an entry related to two topics, then hard-deletes the entry and the second topic.
     *
     * @return array{0: string, 1: string, 2: string} the Payload, and the UIDs of the kept and the deleted topic
     */
    private function payloadRelatingTo(string $kept, string $gone): array
    {
        $topic = Schema::entryType('topic');
        $topics = Schema::section('topics', [$topic]);
        $keptTopic = Schema::entry($topics, $topic, ['title' => $kept]);
        $goneTopic = Schema::entry($topics, $topic, ['title' => $gone]);
        $entryType = Schema::entryType('article', [Schema::field(Entries::class, 'relatedTopics')]);
        $section = Schema::section('news', [$entryType]);
        $source = Schema::entry($section, $entryType, ['title' => 'Related', 'relatedTopics' => [$keptTopic->id, $goneTopic->id]]);
        $this->sourceUid = $source->uid;

        $payload = Plugin::getInstance()->getPortage()->export([$source]);
        Craft::$app->getElements()->deleteElement($source, true);
        Craft::$app->getElements()->deleteElement($goneTopic, true);

        return [$payload, $keptTopic->uid, $goneTopic->uid];
    }

    /**
     * @return int[] IDs related by the imported entry, including disabled Stubs
     */
    private function relatedIds(): array
    {
        $imported = Entry::find()->uid($this->sourceUid)->status(null)->one();
        $this->assertNotNull($imported);

        return $imported->getFieldValue('relatedTopics')->status(null)->ids();
    }

    private function sourceEntry(string $title): Entry
    {
        $entryType = Schema::entryType('article', [Schema::plainText('summary')]);
        $section = Schema::section('news', [$entryType]);

        return Schema::entry($section, $entryType, ['title' => $title, 'summary' => 'Via the CLI']);
    }

    /**
     * The email of the user the Import runs as, created on first use.
     */
    private function importerEmail(): string
    {
        return (User::find()->username('importer')->one() ?? Schema::user('importer', ['admin' => true]))->email;
    }
}
