<?php

namespace simplygoodwork\craftportage\tests\unit;

use Craft;
use craft\elements\Entry;
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
        $this->consoleCommand('portage/import', ['file' => $this->file], true)
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

        $this->consoleCommand('portage/import', [$payload], true)
            ->exitCode(ExitCode::OK)
            ->run();

        $this->assertNotNull(Entry::find()->uid($source->uid)->status(null)->one());
    }

    public function testRefusesAMalformedPayload(): void
    {
        $this->consoleCommand('portage/import', ['not a payload'], true)
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

    private function sourceEntry(string $title): Entry
    {
        $entryType = Schema::entryType('article', [Schema::plainText('summary')]);
        $section = Schema::section('news', [$entryType]);

        return Schema::entry($section, $entryType, ['title' => $title, 'summary' => 'Via the CLI']);
    }
}
