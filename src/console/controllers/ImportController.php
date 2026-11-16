<?php

namespace simplygoodwork\craftportage\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\User;
use craft\helpers\Console;
use craft\helpers\Db;
use simplygoodwork\craftportage\import\ImportResult;
use simplygoodwork\craftportage\import\ReferencePlan;
use simplygoodwork\craftportage\import\RootPlan;
use simplygoodwork\craftportage\import\RootResult;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\references\Resolution;
use yii\console\ExitCode;

/**
 * Imports a Payload, or previews it with --dry-run, as the user given with --user. New entries are created disabled.
 * Unresolved references are skipped unless --stub, --replace, --fetch or --choices say otherwise. A Root element that matches an
 * existing entry is created as a new entry unless --overwrite chooses the overwrite Import mode, and goes to its own section unless --section chooses a Target section.
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
     * @var string|null Comma-separated UIDs of unresolved assets to fetch from their source URL into their own volume
     * and folder. To fetch into another volume or folder, use --choices.
     */
    public ?string $fetch = null;

    /**
     * @var string|null Read choices for Unresolved references from this JSON file: an object of Reference UID =>
     * "skip", "stub", "fetch", a replacement element ID, or `{"choice": "fetch", "volume": "<handle>", "folder":
     * "<path>"}` to fetch an asset into another volume or folder, or `{"choice": "upload", "path": "<file>"}` to create it from a file. --stub, --replace and --fetch override it.
     */
    public ?string $choices = null;

    /**
     * @var string|null Comma-separated UIDs of Root elements to overwrite the matching entry for, or `*` for every Root
     * element that matches an entry (the rest are created). Overwrite creates a draft on the matched entry.
     */
    public ?string $overwrite = null;

    /**
     * @var string|null The handle of the Target section to import every Root entry into, or comma-separated `uid=handle` pairs
     * to choose a Target section for individual Root elements.
     */
    public ?string $section = null;

    /**
     * @var string|null The email of the active user to run the Import as. Unresolved authors are credited to them, and
     * the Import log records them. Required.
     */
    public ?string $user = null;

    public function options($actionID): array
    {
        return [...parent::options($actionID), 'file', 'dryRun', 'stub', 'replace', 'fetch', 'choices', 'overwrite', 'section', 'user'];
    }

    /**
     * Imports a Payload passed as an argument, with --file, or on stdin.
     */
    public function actionIndex(?string $payload = null): int
    {
        $user = $this->findUser();
        if ($user === null) {
            return ExitCode::USAGE;
        }

        $session = Craft::$app->getUser();
        $previous = $session->getIdentity();
        $session->setIdentity($user);
        try {
            return $this->importPayload($payload);
        } finally {
            $session->setIdentity($previous);
        }
    }

    private function findUser(): ?User
    {
        if ($this->user === null || trim($this->user) === '') {
            $this->stderr('Pass --user with the email of the user to run the Import as. Unresolved authors are credited to them.' . PHP_EOL, Console::FG_RED);
            return null;
        }

        $user = User::find()->email(Db::escapeParam(trim($this->user)))->status(User::STATUS_ACTIVE)->one();
        if (!$user) {
            $this->stderr("No active user has the email $this->user." . PHP_EOL, Console::FG_RED);
        }

        return $user;
    }

    private function importPayload(?string $payload): int
    {
        $payload ??= $this->readPayload();
        $choices = $this->readChoices();
        if ($payload === null || $choices === null) {
            return ExitCode::NOINPUT;
        }

        $rootChoices = $this->readRootChoices();
        if ($rootChoices === null) {
            return ExitCode::USAGE;
        }

        if ($this->dryRun) {
            return $this->preview($payload, $choices, $rootChoices);
        }

        $result = Plugin::getInstance()->getPortage()->import($payload, $choices, $rootChoices);

        if ($result->isRefused()) {
            $this->stderr("Payload refused: $result->refusal" . PHP_EOL, Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $this->report($result);

        return $result->hasFailures() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * @param array<string, string|int|array<string, string>> $choices
     * @param array<string, array{mode?: string, section?: string}> $rootChoices
     */
    private function preview(string $payload, array $choices, array $rootChoices): int
    {
        $plan = Plugin::getInstance()->getPortage()->preview($payload, $choices, $rootChoices);

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

        foreach ($plan->warnings as $warning) {
            $this->stdout("Warning: $warning" . PHP_EOL, Console::FG_YELLOW);
        }

        foreach ($plan->references as $reference) {
            $this->previewReference($reference);
        }

        foreach ($plan->roots as $root) {
            $title = $root->title !== null ? "\"$root->title\"" : 'an entry';
            if ($root->isBlocked()) {
                $this->stdout("Can't import $title from $root->sourceUid: $root->blockedBy" . PHP_EOL, Console::FG_RED);
            } elseif ($root->mode === RootPlan::MODE_OVERWRITE) {
                $this->stdout("Would create a draft on entry #{$root->match?->elementId} from $root->sourceUid." . PHP_EOL, Console::FG_GREEN);
            } else {
                $this->stdout("Would create a disabled entry $title from $root->sourceUid." . PHP_EOL, Console::FG_GREEN);
            }
            $this->previewMatch($root);
            $this->details($root->unresolvedReferences, $root->warnings, $root->incompleteFields);
        }

        return ExitCode::OK;
    }

    private function previewMatch(RootPlan $root): void
    {
        if ($root->isBlocked()) {
            return;
        }

        $match = $root->match;
        $found = match (true) {
            $match === null => 'No existing entry matches',
            $match->rule === Resolution::RULE_NATURAL_KEY => "Matches entry #$match->elementId by Natural key",
            default => "Matches entry #$match->elementId by UID",
        };
        $this->stdout(sprintf('  %s. Modes: %s. Chosen: %s.', $found, implode(', ', $root->availableModes), $root->mode) . PHP_EOL);
        if ($root->section) {
            $this->stdout("  Target section: \"{$root->section->name}\"." . PHP_EOL);
        }
        if ($root->overwriteUnavailable !== null) {
            $this->stdout("  Can't overwrite: $root->overwriteUnavailable" . PHP_EOL, Console::FG_YELLOW);
        }
    }

    private function previewReference(ReferencePlan $reference): void
    {
        $what = "{$reference->name()} ({$reference->typeName()}, $reference->uid)";
        $resolution = $reference->resolution;

        if ($resolution->isResolved()) {
            $rule = $resolution->rule === Resolution::RULE_NATURAL_KEY ? 'Natural key' : 'UID';
            $this->stdout("Reference $what matched by $rule." . PHP_EOL);
            return;
        }

        $outcome = match ($reference->choice) {
            ReferencePlan::CHOICE_STUB => 'will be Stubbed',
            ReferencePlan::CHOICE_REPLACE => "will be replaced with #{$reference->replacement?->id}",
            ReferencePlan::CHOICE_FETCH => $reference->fetch
                ? "will be fetched from {$reference->fetch->source->describe()} into {$reference->fetch->location()}"
                : 'will be fetched',
            ReferencePlan::CHOICE_UPLOAD => $reference->fetch
                ? "will be created from {$reference->fetch->source->describe()} into {$reference->fetch->location()}"
                : 'will be created from the uploaded file',
            default => match (true) {
                $reference->elementType === 'user' => sprintf(
                    'will be skipped in user fields; as an author, "%s" is credited instead',
                    Craft::$app->getUser()->getIdentity()?->getName(),
                ),
                $reference->fetchUnavailable !== null => "will be skipped. $reference->fetchUnavailable",
                default => 'will be skipped',
            },
        };
        $this->stdout(
            "Unresolved reference $what: $outcome. Choices: " . implode(', ', $reference->availableChoices) . '.' . PHP_EOL,
            Console::FG_YELLOW,
        );
    }

    /**
     * Returns the choices from --choices, --stub and --replace, or null if they can't be read.
     *
     * @return array<string, string|int|array<string, string>>|null
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
                if (is_string($choice) || is_int($choice) || is_array($choice)) {
                    $choices[(string)$uid] = $choice;
                }
            }
        }

        foreach ($this->split($this->stub) as $uid) {
            $choices[$uid] = ReferencePlan::CHOICE_STUB;
        }

        foreach ($this->split($this->fetch) as $uid) {
            $choices[$uid] = ReferencePlan::CHOICE_FETCH;
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
     * Returns the choices from --overwrite and --section, by Root element UID (`*` for every Root element), or null
     * if they can't be read.
     *
     * @return array<string, array{mode?: string, section?: string}>|null
     */
    private function readRootChoices(): ?array
    {
        $rootChoices = [];

        foreach ($this->split($this->overwrite) as $uid) {
            $rootChoices[$uid]['mode'] = RootPlan::MODE_OVERWRITE;
        }

        foreach ($this->split($this->section) as $item) {
            if (!str_contains($item, '=')) {
                $rootChoices['*']['section'] = $item;
                continue;
            }
            [$uid, $handle] = array_map('trim', explode('=', $item, 2));
            if ($uid === '' || $handle === '') {
                $this->stderr("--section expects a section handle, or uid=handle pairs, not \"$item\"." . PHP_EOL, Console::FG_RED);
                return null;
            }
            $rootChoices[$uid]['section'] = $handle;
        }

        return $rootChoices;
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
            } elseif ($root->status === RootResult::STATUS_OVERWRITTEN) {
                $this->stdout("Created a draft #$root->draftId on entry #$root->elementId from $root->sourceUid." . PHP_EOL, Console::FG_GREEN);
            } else {
                $this->stdout("Created disabled entry #$root->elementId from $root->sourceUid." . PHP_EOL, Console::FG_GREEN);
            }
            foreach ($root->stubs as $stub) {
                $title = $stub['title'] ?? $stub['uid'];
                $this->stdout("  Created a disabled Stub #{$stub['elementId']} for $title ({$stub['uid']})." . PHP_EOL);
            }
            foreach ($root->fetchedAssets as $asset) {
                $title = $asset['title'] ?? $asset['uid'];
                $this->stdout("  Fetched asset #{$asset['elementId']} for $title ({$asset['uid']})." . PHP_EOL);
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
