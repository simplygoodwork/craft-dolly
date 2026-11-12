<?php

namespace simplygoodwork\craftportage\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use simplygoodwork\craftportage\import\ImportResult;
use simplygoodwork\craftportage\import\ReferencePlan;
use simplygoodwork\craftportage\import\RootResult;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\references\Resolution;
use yii\console\ExitCode;

/**
 * Imports a Payload, or previews it with --dry-run. New entries are created disabled. Unresolved references are
 * skipped unless --stub, --replace or --choices say otherwise.
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

    /**
     * @var string|null Comma-separated UIDs of Unresolved references to Stub.
     */
    public ?string $stub = null;

    /**
     * @var string|null Comma-separated `uid=elementId` pairs: Unresolved references to replace with existing elements.
     */
    public ?string $replace = null;

    /**
     * @var string|null Read choices for Unresolved references from this JSON file: an object of Reference UID =>
     * "skip", "stub" or a replacement element ID. --stub and --replace override it.
     */
    public ?string $choices = null;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'file', 'dryRun', 'stub', 'replace', 'choices'];
    }

    /**
     * Imports a Payload passed as an argument, with --file, or on stdin.
     */
    public function actionIndex(?string $payload = null): int
    {
        $payload ??= $this->readPayload();
        $choices = $this->readChoices();
        if ($payload === null || $choices === null) {
            return ExitCode::NOINPUT;
        }

        if ($this->dryRun) {
            return $this->preview($payload, $choices);
        }

        $result = Plugin::getInstance()->getPortage()->import($payload, $choices);

        if ($result->isRefused()) {
            $this->stderr("Payload refused: $result->refusal" . PHP_EOL, Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $this->report($result);

        return $result->hasFailures() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * @param array<string, string|int> $choices
     */
    private function preview(string $payload, array $choices): int
    {
        $plan = Plugin::getInstance()->getPortage()->preview($payload, $choices);

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

        foreach ($plan->references as $reference) {
            $this->previewReference($reference);
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

    private function previewReference(ReferencePlan $reference): void
    {
        $what = "{$reference->name()} (" . ($reference->elementType ?? 'unknown type') . ", $reference->uid)";
        $resolution = $reference->resolution;

        if ($resolution->isResolved()) {
            $rule = $resolution->rule === Resolution::RULE_NATURAL_KEY ? 'Natural key' : 'UID';
            $this->stdout("Reference $what matched by $rule." . PHP_EOL);
            return;
        }

        $outcome = match ($reference->choice) {
            ReferencePlan::CHOICE_STUB => 'will be Stubbed',
            ReferencePlan::CHOICE_REPLACE => "will be replaced with #{$reference->replacement?->id}",
            default => 'will be skipped',
        };
        $this->stdout(
            "Unresolved reference $what: $outcome. Choices: " . implode(', ', $reference->availableChoices) . '.' . PHP_EOL,
            Console::FG_YELLOW,
        );
    }

    /**
     * Returns the choices from --choices, --stub and --replace, or null if they can't be read.
     *
     * @return array<string, string|int>|null
     */
    private function readChoices(): ?array
    {
        $choices = [];

        if ($this->choices !== null) {
            $contents = is_readable($this->choices) ? file_get_contents($this->choices) : false;
            $decoded = $contents !== false ? json_decode($contents, true) : null;
            if (!is_array($decoded)) {
                $this->stderr("Couldn't read choices from $this->choices: it should be a JSON object." . PHP_EOL, Console::FG_RED);
                return null;
            }
            foreach ($decoded as $uid => $choice) {
                if (is_string($choice) || is_int($choice)) {
                    $choices[(string)$uid] = $choice;
                }
            }
        }

        foreach ($this->split($this->stub) as $uid) {
            $choices[$uid] = ReferencePlan::CHOICE_STUB;
        }

        foreach ($this->split($this->replace) as $pair) {
            [$uid, $id] = array_pad(explode('=', $pair, 2), 2, '');
            if (!ctype_digit($id)) {
                $this->stderr("--replace expects uid=elementId pairs, not \"$pair\"." . PHP_EOL, Console::FG_RED);
                return null;
            }
            $choices[$uid] = (int)$id;
        }

        return $choices;
    }

    /**
     * @return list<string>
     */
    private function split(?string $list): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string)$list))));
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
            foreach ($root->stubs as $stub) {
                $title = $stub['title'] ?? $stub['uid'];
                $this->stdout("  Created a disabled Stub #{$stub['elementId']} for $title ({$stub['uid']})." . PHP_EOL);
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
