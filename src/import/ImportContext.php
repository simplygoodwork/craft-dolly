<?php

namespace simplygoodwork\craftportage\import;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\User;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use simplygoodwork\craftportage\fields\FieldAdapterRegistry;
use simplygoodwork\craftportage\schema\Drift;
use simplygoodwork\craftportage\schema\DriftReport;

/**
 * State for planning one Root element. Field adapters receive it to rebuild Owned content and resolve References.
 *
 * Planning only reads the Target environment; everything that will be skipped or imported imperfectly is
 * recorded on the Root element's plan.
 */
final class ImportContext
{
    /**
     * @var string|null UID of the Payload site whose content is being planned
     */
    public ?string $siteUid = null;

    /**
     * @var array<string, string> source UID => UID in the Target environment, for nested entries, so the same nested
     * entry is matched in every site
     */
    private array $nestedUids = [];

    /**
     * @param array<string, ReferencePlan> $references the plan for each of the Payload's References, by UID
     */
    public function __construct(
        private readonly FieldAdapterRegistry $adapters,
        private readonly array $references,
        private readonly UidClaims $uids,
        public readonly DriftReport $drift,
        public readonly RootPlan $plan,
    ) {
    }

    /**
     * Returns the element a relation to a Reference will point at (matched, replacement or Stub), or null after
     * reporting it as a skipped Unresolved reference.
     */
    public function resolveReference(string $uid, FieldInterface $field): ?ElementInterface
    {
        $reference = $this->references[$uid] ?? null;
        $target = $reference?->target();

        if (!$reference || !$target) {
            $this->plan->addUnresolvedReference($uid, $reference?->elementType, $reference?->title, $field->name);
            return null;
        }

        if ($reference->stub) {
            $this->plan->stubs[$uid] = $reference;
        }

        return $target;
    }

    /**
     * Returns the author a Reference will credit: the matched user or the replacement. An unresolved author falls
     * back to the user running the Import, with a warning; with nobody logged in, it's dropped.
     */
    public function resolveAuthor(string $uid): ?User
    {
        $reference = $this->references[$uid] ?? null;
        $target = $reference?->target();
        if ($target instanceof User) {
            return $target;
        }

        $name = $reference ? $reference->name() : 'An author';
        $currentUser = Craft::$app->getUser()->getIdentity();
        $this->plan->warn($currentUser
            ? sprintf('The author %s doesn\'t exist in this environment. "%s" will be credited instead.', $name, $currentUser->getName())
            : sprintf('The author %s doesn\'t exist in this environment, and nobody is logged in to credit instead. The entry won\'t credit them.', $name));

        return $currentUser;
    }

    /**
     * Returns the source UID if it's free in the Target environment, or null so that Craft generates one (ADR-0003).
     */
    public function claimUid(mixed $sourceUid): ?string
    {
        return $this->uids->claim($sourceUid);
    }

    /**
     * Returns the UID a nested entry will have in the Target environment: its source UID if that's free, otherwise a
     * new one (ADR-0003). It's the same in every site, so each site's content goes to the same nested entry.
     */
    public function nestedUid(mixed $sourceUid): string
    {
        if (!is_string($sourceUid)) {
            return StringHelper::UUID();
        }

        return $this->nestedUids[$sourceUid] ??= $this->uids->claim($sourceUid) ?? StringHelper::UUID();
    }

    /**
     * Returns an element's content for the site being planned: its title, slug and translatable field values for that
     * site, plus the values of its non-translatable fields. An element with no content for that site gets its
     * first site's content.
     *
     * @param array<mixed> $element a Root element or nested entry from the Payload
     * @return array{title: string|null, slug: string|null, fields: array<mixed>}
     */
    public function content(array $element): array
    {
        $sites = is_array($element['sites'] ?? null) ? $element['sites'] : [];
        $site = ($this->siteUid !== null ? $sites[$this->siteUid] ?? null : null) ?? reset($sites);
        $site = is_array($site) ? $site : [];

        return [
            'title' => is_string($site['title'] ?? null) ? $site['title'] : null,
            'slug' => is_string($site['slug'] ?? null) ? $site['slug'] : null,
            'fields' => array_replace(
                is_array($element['fields'] ?? null) ? $element['fields'] : [],
                is_array($site['fields'] ?? null) ? $site['fields'] : [],
            ),
        ];
    }

    /**
     * Warns about a Schema reference the Root element depends on, unless it's matched.
     */
    public function noteDrift(string $kind, mixed $uid): ?Drift
    {
        $drift = $this->drift->get($kind, $uid);
        if ($drift?->message !== null) {
            $this->plan->warn($drift->message);
        }

        return $drift;
    }

    /**
     * Converts portable field values into `setFieldValues()` input for an entry type, keyed by handle.
     *
     * Values for missing or type-changed fields, and for fields that aren't in the entry type's field layout, are
     * skipped with a warning. Values for drifted fields are imported with a warning.
     *
     * @param array<mixed> $portable field UID => portable value
     * @return array<string, mixed>
     */
    public function fieldValues(EntryType $entryType, array $portable): array
    {
        $layoutFields = [];
        foreach ($entryType->getFieldLayout()->getCustomFields() as $field) {
            $layoutFields[$field->uid] = $field;
        }

        $values = [];
        foreach ($portable as $uid => $value) {
            $drift = $this->noteDrift('fields', $uid);
            if ($drift?->isSkipped()) {
                continue;
            }

            $field = $layoutFields[$uid] ?? null;
            if (!$field) {
                if ($drift) {
                    $this->plan->warn("The field \"$drift->name\" isn't in the \"$entryType->name\" entry type's field layout in this environment. Its values will be skipped.");
                }
                continue;
            }

            $adapter = $this->adapters->adapterFor($field);
            if ($adapter) {
                $values[$field->handle] = $adapter->import($field, $value, $this);
            }
        }

        return $values;
    }
}
