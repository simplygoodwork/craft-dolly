<?php

namespace simplygoodwork\craftportage\import;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craft\models\EntryType;
use craft\models\Site;
use simplygoodwork\craftportage\fields\FieldAdapterRegistry;
use simplygoodwork\craftportage\payload\DecodedPayload;
use simplygoodwork\craftportage\references\ElementTypes;
use simplygoodwork\craftportage\references\ReferenceResolver;
use simplygoodwork\craftportage\schema\SchemaManifest;

/**
 * Decides what an Import will do with a decoded Payload, without writing anything.
 *
 * Both the Preview and the Import come from here, so they can't disagree. Planning must never write: it runs
 * outside any transaction, and a Preview must leave the Target environment untouched. Anything that writes (such as
 * creating Stubs) belongs in the Importer, inside the Root element's transaction.
 */
final class ImportPlanner
{
    public function __construct(private readonly FieldAdapterRegistry $adapters)
    {
    }

    public function plan(DecodedPayload $payload): ImportPlan
    {
        $envelope = $payload->envelope;
        $drift = (new SchemaManifest())->classify($envelope['schema'] ?? null);
        $uids = new UidClaims();
        $resolver = new ReferenceResolver();
        $references = is_array($envelope['references'] ?? null) ? $envelope['references'] : [];
        $plans = [];

        foreach ($envelope['roots'] as $root) {
            $plan = new RootPlan(
                is_array($root) && is_string($root['uid'] ?? null) ? $root['uid'] : null,
                is_array($root) ? $this->title($root) : null,
            );
            $plans[] = $plan;

            if (!is_array($root)) {
                $plan->block('The Root element data is damaged.');
                continue;
            }

            $this->planRoot($root, new ImportContext($this->adapters, $resolver, $references, $uids, $drift, $plan));
        }

        return new ImportPlan(
            drift: $drift,
            roots: $plans,
            payloadIdentity: $payload->identity,
            source: is_array($envelope['source'] ?? null) ? $envelope['source'] : [],
        );
    }

    /**
     * @param array<mixed> $root
     */
    private function planRoot(array $root, ImportContext $context): void
    {
        $plan = $context->plan;

        if (ElementTypes::classFor($root['elementType'] ?? null) !== Entry::class) {
            $plan->block('Unsupported element type: ' . Json::encode($root['elementType'] ?? null));
            return;
        }

        $section = is_string($root['section'] ?? null) ? Craft::$app->getEntries()->getSectionByUid($root['section']) : null;
        if (!$section) {
            $plan->block($context->drift->get('sections', $root['section'] ?? null)->message
                ?? 'The section doesn\'t exist in this environment.');
            return;
        }
        $context->noteDrift('sections', $section->uid);

        $entryType = ArrayHelper::firstWhere($section->getEntryTypes(), 'uid', $root['type'] ?? null);
        if (!$entryType instanceof EntryType) {
            $name = $context->drift->get('entryTypes', $root['type'] ?? null)?->name;
            $plan->block(sprintf(
                '%s isn\'t available in the "%s" section in this environment.',
                $name !== null ? "The entry type \"$name\"" : 'The entry type',
                $section->name,
            ));
            return;
        }
        $context->noteDrift('entryTypes', $entryType->uid);

        [$site, $content] = $this->firstAvailableSite($root['sites'] ?? null, $context);
        if (!$site) {
            $plan->block('None of the entry\'s sites exist in this environment.');
            return;
        }

        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $entryType->id,
            'siteId' => $site->id,
        ]);
        $entry->uid = $context->claimUid($root['uid'] ?? null);
        $entry->enabled = false;
        if (is_string($content['title'] ?? null)) {
            $entry->title = $content['title'];
        }
        if (is_string($content['slug'] ?? null)) {
            $entry->slug = $content['slug'];
        }
        $entry->setFieldValues($context->fieldValues($entryType, is_array($content['fields'] ?? null) ? $content['fields'] : []));

        $plan->entry = $entry;
        $plan->incompleteFields = $this->incompleteFields($entry);
    }

    /**
     * Returns the required fields that the entry, or any enabled nested entry, would leave empty.
     *
     * New entries are saved disabled, so these don't stop the save, but the user needs to fill them in before the
     * entry can go live. Nested fields are named by their path, e.g. "Content Blocks › Body".
     *
     * @return list<string>
     */
    private function incompleteFields(ElementInterface $element, string $path = ''): array
    {
        $incomplete = [];

        foreach ($element->getFieldLayout()?->getCustomFieldElements() ?? [] as $layoutElement) {
            $field = $layoutElement->getField();
            $value = $element->getFieldValue($field->handle);
            $name = $path . $field->name;

            if ($layoutElement->required && $field->isValueEmpty($value, $element)) {
                $incomplete[] = $name;
            }

            // Owned content that isn't saved yet is cached on the query; only enabled nested entries must be complete.
            if ($value instanceof ElementQueryInterface) {
                foreach ($value->getCachedResult() ?? [] as $nested) {
                    if ($nested->enabled) {
                        $incomplete = [...$incomplete, ...$this->incompleteFields($nested, "$name › ")];
                    }
                }
            }
        }

        return array_values(array_unique($incomplete));
    }

    /**
     * @return array{0: Site|null, 1: array<mixed>}
     */
    private function firstAvailableSite(mixed $sites, ImportContext $context): array
    {
        if (!is_array($sites)) {
            return [null, []];
        }

        $targetSites = ArrayHelper::index(Craft::$app->getSites()->getAllSites(), 'uid');
        $found = [null, []];
        foreach ($sites as $siteUid => $content) {
            $context->noteDrift('sites', $siteUid);
            $site = $targetSites[$siteUid] ?? null;
            if (!$found[0] && $site && is_array($content)) {
                $found = [$site, $content];
            }
        }

        return $found;
    }

    /**
     * @param array<mixed> $root
     */
    private function title(array $root): ?string
    {
        foreach (is_array($root['sites'] ?? null) ? $root['sites'] : [] as $content) {
            if (is_string($content['title'] ?? null)) {
                return $content['title'];
            }
        }

        return null;
    }
}
