<?php

namespace simplygoodwork\craftportage\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use simplygoodwork\craftportage\import\ImportResult;
use simplygoodwork\craftportage\import\RootResult;
use simplygoodwork\craftportage\Plugin;
use yii\console\ExitCode;

/**
 * Imports a Payload, or previews it with --dry-run. New entries are created disabled.
 */
class ImportController extends Controller
{
    /**
     * @var string|null Read the Payload from this file (`-` for stdin).
     */
    public ?string $file = null;

    /**
     * @var bool Print the Preview and write nothing.
     */
    public bool $dryRun = false;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'file', 'dryRun'];
    }

    /**
     * Imports a Payload passed as an argument, with --file, or on stdin.
     */
    public function actionIndex(?string $payload = null): int
    {
        $payload ??= $this->readPayload();
        if ($payload === null) {
            return ExitCode::NOINPUT;
        }

        if ($this->dryRun) {
            return $this->preview($payload);
        }

        $result = Plugin::getInstance()->getPortage()->import($payload);

        if ($result->isRefused()) {
            $this->stderr("Payload refused: $result->refusal" . PHP_EOL, Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $this->report($result);

        return $result->hasFailures() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    private function preview(string $payload): int
    {
        $plan = Plugin::getInstance()->getPortage()->preview($payload);

        if ($plan->isRefused()) {
            $this->stderr("Payload refused: $plan->refusal" . PHP_EOL, Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $this->stdout('Preview only: nothing has been imported.' . PHP_EOL);

        foreach ($plan->drift->all() as $drift) {
            if ($drift->message !== null) {
                $this->stdout("Drift ($drift->state): $drift->message" . PHP_EOL, Console::FG_YELLOW);
            }
        }

        foreach ($plan->roots as $root) {
            $title = $root->title !== null ? "\"$root->title\"" : 'an entry';
            if ($root->isBlocked()) {
                $this->stdout("Can't import $title from $root->sourceUid: $root->blockedBy" . PHP_EOL, Console::FG_RED);
            } else {
                $this->stdout("Would create a disabled entry $title from $root->sourceUid." . PHP_EOL, Console::FG_GREEN);
            }
            $this->details($root->unresolvedReferences, $root->warnings, $root->incompleteFields);
        }

        return ExitCode::OK;
    }

    private function readPayload(): ?string
    {
        if ($this->file !== null && $this->file !== '-') {
            $contents = is_readable($this->file) ? file_get_contents($this->file) : false;
            if ($contents === false) {
                $this->stderr("Couldn't read $this->file." . PHP_EOL, Console::FG_RED);
                return null;
            }
            return $contents;
        }

        if ($this->file === null && stream_isatty(STDIN)) {
            $this->stderr('Pass a Payload as an argument, with --file, or on stdin.' . PHP_EOL, Console::FG_RED);
            return null;
        }

        return (string)stream_get_contents(STDIN);
    }

    private function report(ImportResult $result): void
    {
        foreach ($result->roots as $root) {
            if ($root->status === RootResult::STATUS_FAILED) {
                $this->stdout("Failed to import $root->sourceUid: " . implode(' ', $root->errors) . PHP_EOL, Console::FG_RED);
            } else {
                $this->stdout("Created disabled entry #$root->elementId from $root->sourceUid." . PHP_EOL, Console::FG_GREEN);
            }

            $this->details($root->unresolvedReferences, $root->warnings, $root->incompleteFields);
        }

        $this->stdout("Recorded in the Import log as #$result->logId." . PHP_EOL);
    }

    /**
     * @param list<array{uid: string, elementType: string|null, title: string|null, field: string}> $unresolvedReferences
     * @param list<string> $warnings
     * @param list<string> $incompleteFields
     */
    private function details(array $unresolvedReferences, array $warnings, array $incompleteFields): void
    {
        foreach ($unresolvedReferences as $reference) {
            $title = $reference['title'] ?? $reference['uid'];
            $this->stdout("  Skipped unresolved reference in \"{$reference['field']}\": $title ({$reference['uid']})" . PHP_EOL, Console::FG_YELLOW);
        }
        foreach ($warnings as $warning) {
            $this->stdout("  Warning: $warning" . PHP_EOL, Console::FG_YELLOW);
        }
        foreach ($incompleteFields as $incomplete) {
            $this->stdout("  Incomplete: $incomplete" . PHP_EOL, Console::FG_YELLOW);
        }
    }
}
