<?php

namespace simplygoodwork\craftportage\console\controllers;

use craft\console\Controller;
use craft\elements\Entry;
use craft\helpers\Console;
use simplygoodwork\craftportage\Plugin;
use yii\base\InvalidConfigException;
use yii\console\ExitCode;

/**
 * Exports entries as a Payload.
 */
class ExportController extends Controller
{
    /**
     * @var string|null Write the Payload to this file instead of stdout.
     */
    public ?string $output = null;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'output'];
    }

    /**
     * Exports one or more entries (comma-separated IDs) as a Payload.
     *
     * @param string[] $entryIds
     */
    public function actionIndex(array $entryIds): int
    {
        $entries = [];
        foreach ($entryIds as $id) {
            $entry = is_numeric($id) ? Entry::find()->id((int)$id)->status(null)->one() : null;
            if (!$entry) {
                $this->stderr("No entry found with ID $id." . PHP_EOL, Console::FG_RED);
                return ExitCode::DATAERR;
            }
            $entries[] = $entry;
        }

        try {
            $payload = Plugin::getInstance()->getPortage()->export($entries);
        } catch (InvalidConfigException $e) {
            $this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);
            return ExitCode::CONFIG;
        }

        if ($this->output === null) {
            $this->stdout($payload . PHP_EOL);
            return ExitCode::OK;
        }

        if (file_put_contents($this->output, $payload . PHP_EOL) === false) {
            $this->stderr("Couldn't write to $this->output." . PHP_EOL, Console::FG_RED);
            return ExitCode::CANTCREAT;
        }

        $this->stdout("Payload written to $this->output." . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }
}
