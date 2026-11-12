<?php

namespace simplygoodwork\craftportage\import;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\PropagationMethod;
use craft\helpers\ArrayHelper;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\models\EntryType;
use craft\models\Section;
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

    /**
     * @param array<mixed> $choices for Unresolved references, by Reference UID: "skip", "stub", or the ID of a
     * replacement element. Omitted choices are "skip".
     */
    public function plan(DecodedPayload $payload, array $choices = []): ImportPlan
    {
        $envelope = $payload->envelope;
        $drift = (new SchemaManifest())->classify($envelope['schema'] ?? null);
        $uids = new UidClaims();
        $references = $this->planReferences($envelope['references'] ?? null);

        try {
            $this->applyChoices($references, $choices, new Stubs($uids, $this->rootUids($envelope['roots'])));
        } catch (InvalidChoiceException $e) {
            return ImportPlan::refused($e->getMessage());
        }

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

            $this->planRoot($root, new ImportContext($this->adapters, $references, $uids, $drift, $plan));
        }

        return new ImportPlan(
            drift: $drift,
            roots: $plans,
            references: $references,
            payloadIdentity: $payload->identity,
            source: is_array($envelope['source'] ?? null) ? $envelope['source'] : [],
        );
    }

    /**
     * Resolves every Reference in the Payload.
     *
     * @return array<string, ReferencePlan> by Reference UID
     */
    private function planReferences(mixed $references): array
    {
        $resolver = new ReferenceResolver();
        $plans = [];

        foreach (is_array($references) ? $references : [] as $uid => $reference) {
            if (!is_string($uid) || !is_array($reference)) {
                continue;
            }
            $plans[$uid] = new ReferencePlan(
                $uid,
                is_string($reference['elementType'] ?? null) ? $reference['elementType'] : null,
                is_string($reference['title'] ?? null) ? $reference['title'] : null,
                $resolver->resolve($reference),
                $reference,
            );
        }

        return $plans;
    }

    /**
     * @return array<string, true>
     */
    private function rootUids(mixed $roots): array
    {
        $uids = [];
        foreach (is_array($roots) ? $roots : [] as $root) {
            if (is_array($root) && is_string($root['uid'] ?? null)) {
                $uids[$root['uid']] = true;
            }
        }

        return $uids;
    }

    /**
     * Sets what happens to each Unresolved reference: skip it unless the user chose a Stub or a replacement.
     *
     * @param array<string, ReferencePlan> $references
     * @param array<mixed> $choices
     * @throws InvalidChoiceException if a choice names an unknown Reference or can't be carried out
     */
    private function applyChoices(array $references, array $choices, Stubs $stubs): void
    {
        foreach ($choices as $uid => $choice) {
            if (!isset($references[$uid])) {
                throw new InvalidChoiceException("The Payload has no Reference with the UID $uid, so its choice can't be applied.");
            }
        }

        foreach ($references as $uid => $reference) {
            if ($reference->resolution->isResolved()) {
                continue;
            }

            $reference->availableChoices = [
                ReferencePlan::CHOICE_SKIP,
                ...($stubs->canStub($reference) ? [ReferencePlan::CHOICE_STUB] : []),
                ReferencePlan::CHOICE_REPLACE,
            ];
            $choice = $choices[$uid] ?? ReferencePlan::CHOICE_SKIP;

            if ($choice === ReferencePlan::CHOICE_SKIP) {
                $reference->choice = ReferencePlan::CHOICE_SKIP;
            } elseif ($choice === ReferencePlan::CHOICE_STUB) {
                $reference->stub = $stubs->build($reference) ?? throw new InvalidChoiceException($this->whyNoStub($reference, $stubs));
                $reference->choice = ReferencePlan::CHOICE_STUB;
            } elseif (is_int($choice) || (is_string($choice) && ctype_digit($choice))) {
                $reference->replacement = $this->replacement($reference, (int)$choice);
                $reference->choice = ReferencePlan::CHOICE_REPLACE;
            } else {
                throw new InvalidChoiceException(sprintf(
                    'The choice for the Reference %s isn\'t valid. Choose "skip", "stub" or the ID of a replacement element.',
                    $reference->name(),
                ));
            }
        }
    }

    private function whyNoStub(ReferencePlan $reference, Stubs $stubs): string
    {
        if ($stubs->isRoot($reference)) {
            return "The Reference {$reference->name()} can't be Stubbed: this Payload imports it as a Root element. Skip it or choose a replacement.";
        }

        if ($reference->elementType === 'user') {
            return "The user {$reference->name()} can't be Stubbed: Portage never creates users. Skip it or choose a replacement user.";
        }

        return "The Reference {$reference->name()} can't be Stubbed: its section or group doesn't exist in this environment. Skip it or choose a replacement.";
    }

    /**
     * @throws InvalidChoiceException if there's no element of the Reference's type with that ID
     */
    private function replacement(ReferencePlan $reference, int $id): ElementInterface
    {
        $class = ElementTypes::classFor($reference->elementType);
        $element = $class ? $class::find()->id($id)->status(null)->site('*')->unique()->one() : null;

        return $element ?? throw new InvalidChoiceException(sprintf(
            'The replacement for the Reference %s doesn\'t exist: there\'s no %s with the ID %d in this environment.',
            $reference->name(),
            $reference->elementType ?? 'element',
            $id,
        ));
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

        $sites = $this->targetSites($root['sites'] ?? null, $section, $context);
        if (!$sites) {
            $plan->block("None of the entry's sites exist in this environment, or the \"$section->name\" section isn't enabled for them.");
            return;
        }

        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $entryType->id,
            'siteId' => $sites[0]['site']->id,
        ]);
        if ($section->propagationMethod === PropagationMethod::Custom) {
            // The entry is created only in the Payload's sites.
            $entry->setEnabledForSite(array_fill_keys(array_map(fn(array $site) => $site['site']->id, $sites), true));
        }
        $sites = $this->propagatedSites($entry, $sites, $section, $plan);

        $entry->uid = $context->claimUid($root['uid'] ?? null);
        $entry->enabled = false;
        $context->siteUid = $sites[0]['uid'];
        $content = $context->content($root);
        if ($content['title'] !== null) {
            $entry->title = $content['title'];
        }
        if ($content['slug'] !== null) {
            $entry->slug = $content['slug'];
        }
        $entry->setFieldValues($context->fieldValues($entryType, $content['fields']));
        if ($section->type !== Section::TYPE_SINGLE) {
            $entry->setAuthors($this->authors($root['authors'] ?? null, $section, $context));
        }

        foreach (array_slice($sites, 1) as $site) {
            $context->siteUid = $site['uid'];
            $content = $context->content($root);
            $plan->sites[] = new SitePlan(
                $site['site'],
                $content['title'],
                $content['slug'],
                $context->fieldValues($entryType, $content['fields']),
            );
        }

        $plan->entry = $entry;
        $plan->incompleteFields = $this->incompleteFields($entry);
    }

    /**
     * Returns the Payload sites the entry can be imported into: those that exist in this environment and that the
     * section is enabled for, in Payload order. The others are skipped with a warning.
     *
     * @return list<array{site: Site, uid: string}>
     */
    private function targetSites(mixed $sites, Section $section, ImportContext $context): array
    {
        $targetSites = ArrayHelper::index(Craft::$app->getSites()->getAllSites(), 'uid');
        $sectionSites = ArrayHelper::index($section->getSiteSettings(), 'siteId');
        $found = [];

        foreach (is_array($sites) ? $sites : [] as $siteUid => $content) {
            if (!is_string($siteUid) || !is_array($content)) {
                continue;
            }
            $context->noteDrift('sites', $siteUid);
            $site = $targetSites[$siteUid] ?? null;
            if (!$site) {
                continue;
            }
            if (!isset($sectionSites[$site->id])) {
                $context->plan->warn(sprintf(
                    'The "%s" section isn\'t enabled for the site "%s" in this environment. Content for that site will be skipped.',
                    $section->name,
                    $site->getName(),
                ));
                continue;
            }
            $found[] = ['site' => $site, 'uid' => $siteUid];
        }

        return $found;
    }

    /**
     * Applies the section's propagation method to the Payload sites: the sites the entry won't be saved in are skipped,
     * and sites the entry will be saved in without content in the Payload get the first site's content. Both warn.
     *
     * @param non-empty-list<array{site: Site, uid: string}> $sites
     * @return non-empty-list<array{site: Site, uid: string}>
     */
    private function propagatedSites(Entry $entry, array $sites, Section $section, RootPlan $plan): array
    {
        $propagated = array_map(fn(array $site) => (int)$site['siteId'], ElementHelper::supportedSitesForElement($entry));
        $first = $sites[0]['site'];
        $kept = [];

        foreach ($sites as $site) {
            if (in_array($site['site']->id, $propagated, true)) {
                $kept[] = $site;
                continue;
            }
            $plan->warn(sprintf(
                'The "%s" section doesn\'t propagate entries from the site "%s" to "%s" in this environment. Content for "%s" will be skipped.',
                $section->name,
                $first->getName(),
                $site['site']->getName(),
                $site['site']->getName(),
            ));
        }

        $payloadSiteIds = array_map(fn(array $site) => $site['site']->id, $sites);
        foreach ($propagated as $siteId) {
            if (!in_array($siteId, $payloadSiteIds, true)) {
                $plan->warn(sprintf(
                    'The "%s" section propagates entries to the site "%s" in this environment, so the entry will be created there too, with the content from "%s".',
                    $section->name,
                    Craft::$app->getSites()->getSiteById($siteId)?->getName() ?? (string)$siteId,
                    $first->getName(),
                ));
            }
        }

        /** @var non-empty-list<array{site: Site, uid: string}> $kept the first site always propagates */
        return $kept;
    }

    /**
     * Resolves the Root entry's authors, each unresolved one falling back to the user running the Import, up to the
     * section's author limit.
     *
     * @return User[]
     */
    private function authors(mixed $uids, Section $section, ImportContext $context): array
    {
        $authors = [];
        foreach (is_array($uids) ? $uids : [] as $uid) {
            $author = is_string($uid) ? $context->resolveAuthor($uid) : null;
            if ($author) {
                $authors[$author->id] = $author;
            }
        }

        return array_slice(array_values($authors), 0, $section->maxAuthors ?? null);
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
