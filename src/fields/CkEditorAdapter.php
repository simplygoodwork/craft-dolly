<?php

namespace simplygoodwork\craftportage\fields;

use Craft;
use craft\base\FieldInterface;
use craft\ckeditor\data\Entry as EntryChunk;
use craft\ckeditor\data\FieldData;
use craft\ckeditor\Field;
use craft\elements\Entry;
use craft\helpers\ArrayHelper;
use simplygoodwork\craftportage\export\ExportContext;
use simplygoodwork\craftportage\import\ImportContext;
use simplygoodwork\craftportage\import\PendingNestedEntry;

/**
 * CKEditor: its HTML goes through the ref-tag translator, and the nested entries embedded in it are Owned content that
 * travel with full content, recursively.
 *
 * The portable value is `['html' => …, 'entries' => […]]`. In the HTML, each nested entry's marker names the entry by
 * its UID (`<craft-entry data-entry-uid="…">`) instead of its ID.
 */
final class CkEditorAdapter implements RequiresOwner
{
    private const MARKER_PATTERN = '/<craft-entry\b[^>]*?data-entry-id="(\d+)"[^>]*>.*?<\/craft-entry>/is';
    private const PORTABLE_MARKER_PATTERN = '/<craft-entry\b[^>]*?data-entry-uid="([^"]*)"[^>]*>.*?<\/craft-entry>/is';

    public function __construct(private readonly RefTags $refTags = new RefTags())
    {
    }

    public function export(FieldInterface $field, mixed $value, ExportContext $context): mixed
    {
        if (!$value instanceof FieldData) {
            return null;
        }

        $entries = [];
        foreach ($value->getChunks(false) as $chunk) {
            if ($chunk instanceof EntryChunk && ($entry = $chunk->getEntry())) {
                $entries[$chunk->entryId] = $entry;
            }
        }

        $nested = [];
        $html = $this->refTags->export($value->getRawContent(), $context);
        $html = (string)preg_replace_callback(self::MARKER_PATTERN, function(array $match) use ($entries, $context, &$nested): string {
            $entry = $entries[(int)$match[1]] ?? null;
            if (!$entry instanceof Entry) {
                return '';
            }
            $nested[$entry->uid] ??= $context->exportNested($entry);

            return PendingNestedEntry::marker('entry-uid', $entry->uid);
        }, $html);

        return ['html' => $html, 'entries' => array_values($nested)];
    }

    /**
     * Returns the field's HTML with a token where each nested entry goes, which the Importer replaces with the entry's
     * marker once it has saved the entry. A nested entry that can't be imported is removed, and the HTML around it kept.
     */
    public function import(FieldInterface $field, mixed $portable, ImportContext $context): mixed
    {
        /** @var Field $field */
        if (!is_array($portable) || !is_string($portable['html'] ?? null)) {
            return null;
        }

        $nested = [];
        foreach (is_array($portable['entries'] ?? null) ? $portable['entries'] : [] as $entry) {
            if (is_array($entry) && is_string($entry['uid'] ?? null)) {
                $nested[$entry['uid']] = $entry;
            }
        }
        $entryTypes = ArrayHelper::index($field->getEntryTypes(), 'uid');

        $html = $this->refTags->import($portable['html'], $field, $context);

        return (string)preg_replace_callback(self::PORTABLE_MARKER_PATTERN, function(array $match) use ($nested, $entryTypes, $field, $context): string {
            $entry = $nested[$match[1]] ?? null;
            if ($entry === null) {
                return '';
            }

            $entryType = $context->allowedEntryType($field, $entryTypes, $entry['type'] ?? null, 'A nested entry', 'its');
            if (!$entryType) {
                return '';
            }

            $data = $context->nestedEntryData($entryType, $entry);
            $uid = $context->nestedUid($entry['uid']);
            $pending = $context->plan->pendingNestedEntries[$uid] ??= new PendingNestedEntry(
                new Entry(['typeId' => $entryType->id, 'fieldId' => $field->id, 'uid' => $uid]),
                $data,
            );
            $siteId = $context->siteUid !== null ? Craft::$app->getSites()->getSiteByUid($context->siteUid)->id : null;
            if ($siteId !== null) {
                $pending->addSite($siteId, $data);
            }

            return PendingNestedEntry::token($uid);
        }, $html);
    }
}
