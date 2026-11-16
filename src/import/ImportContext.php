<?php

namespace simplygoodwork\craftportage\import;

use benf\neo\models\BlockType;
use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\User;
use craft\fields\Assets as AssetsField;
use craft\helpers\ArrayHelper;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Site;
use craft\models\Volume;
use simplygoodwork\craftportage\fields\FieldAdapterRegistry;
use simplygoodwork\craftportage\fields\RequiresOwner;
use simplygoodwork\craftportage\fields\SkippedValue;
use simplygoodwork\craftportage\references\ElementTypes;
use simplygoodwork\craftportage\schema\Drift;
use simplygoodwork\craftportage\schema\DriftReport;
use verbb\hyper\base\Link as HyperLink;

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
     * @var int|null the ID of the existing entry the Root element's content goes to (the entry being overwritten, or
     * the entry whose draft blocks are pasted into; for a draft of a nested entry, the entry at the top that owns it),
     * or null when the Import creates a new entry
     */
    public ?int $existingEntryId = null;

    /**
     * @var FieldLayout|null the field layout of the element whose field values are being planned
     */
    private ?FieldLayout $layout = null;

    /**
     * @param array<string, ReferencePlan> $references the plan for each of the Payload's References, by UID
     */
    public function __construct(
        private readonly FieldAdapterRegistry $adapters,
        private readonly array $references,
        private readonly UidClaims $uids,
        private readonly ValueClaims $claims,
        public readonly DriftReport $drift,
        public readonly RootPlan $plan,
    ) {
    }

    /**
     * Claims a value that only one element may take in the Target environment, for the element this Root element's
     * content goes to (the existing entry, or the one it creates). Returns null if the value is free or already
     * claimed for that element, or else the plan of the Root element of this Import that claimed it first.
     *
     * @param string $value the value, prefixed with what kind of value it is: "retour:/go/summer"
     */
    public function claim(string $value): ?RootPlan
    {
        return $this->claims->claim($value, $this->existingEntryId ?? $this->plan, $this->plan);
    }

    /**
     * Returns the element a relation to a Reference will point at (matched, replacement or Stub), or null after
     * reporting it as a skipped Unresolved reference.
     *
     * @param list<string>|null $allowedKinds the kinds of file an asset may be fetched as, for a field that only takes some
     * kinds (SEO images); null leaves it to an Assets field's own restrictions
     */
    public function resolveReference(string $uid, FieldInterface $field, ?array $allowedKinds = null): ?ElementInterface
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
        if ($reference->fetch) {
            if (!$this->allowsKind($field, $reference->fetch, $allowedKinds)) {
                return null;
            }
            $this->plan->addFetch($reference);
        }

        return $target;
    }

    /**
     * Returns the ID of an element a Reference resolved to, for a field value that stores a bare ID (a Hyper link), or
     * a token the Importer replaces with the ID of the Stub or fetched asset it saves. A token renders as nothing if
     * the element isn't saved (a failed fetch).
     */
    public function elementIdFor(ElementInterface $target): int|string
    {
        if ($target->id !== null) {
            return $target->id;
        }

        $pending = PendingRefTag::bareId($target);
        $this->plan->pendingRefTags[$pending->token] = $pending;

        return $pending->token;
    }

    /**
     * Resolves a Reference that a field value embeds by element ID (a Hyper link, a SEO image) to the ID of its element
     * in the Target environment, or a token for the ID of a Stub or fetched asset. Returns null, after warning, if the
     * Payload carries no Reference of the element class, or it doesn't resolve.
     *
     * @param class-string<ElementInterface> $elementClass
     * @param string $notCarried the warning when the Payload carries no such Reference
     * @param string $usage how the field uses the element, in the warning for one that doesn't resolve: "linked"
     * @param string $outcome what happens to it, in that warning
     * @param list<string>|null $allowedKinds see `resolveReference()`
     */
    public function embeddedId(string $uid, string $elementClass, FieldInterface $field, string $notCarried, string $usage, string $outcome, ?array $allowedKinds = null): int|string|null
    {
        $reference = $this->reference($uid);
        if (!$reference || ElementTypes::classFor($reference->elementType) !== $elementClass) {
            $this->plan->warn($notCarried);
            return null;
        }

        $target = $this->resolveReference($uid, $field, $allowedKinds);
        if (!$target) {
            $this->plan->warn(sprintf('The %s %s %s in the field "%s" doesn\'t exist in this environment. %s', $reference->elementType, $reference->name(), $usage, $field->name, $outcome));
            return null;
        }

        return $this->elementIdFor($target);
    }

    /**
     * Returns whether the element whose field values are being planned has a custom field with the handle in its field
     * layout, or null if that isn't known.
     */
    public function layoutHasField(string $handle): ?bool
    {
        return $this->layout?->getFieldByHandle($handle) !== null ?: ($this->layout === null ? null : false);
    }

    /**
     * Returns the site with the given UID in the Target environment, or null after warning that it doesn't exist.
     *
     * @param string $subject where the site is named, in the warning: "of a link in the field "Links""
     * @param string $outcome what happens because it's missing, in the warning
     */
    public function site(string $uid, string $subject, string $outcome): ?Site
    {
        $site = ArrayHelper::firstWhere(Craft::$app->getSites()->getAllSites(true), 'uid', $uid);
        if (!$site) {
            $this->plan->warn(sprintf(
                'The site "%s" %s doesn\'t exist in this environment. %s',
                $this->drift->get('sites', $uid)->name ?? $uid,
                $subject,
                $outcome,
            ));
        }

        return $site;
    }

    /**
     * Returns the plan for one of the Payload's References, or null if the Payload has no such Reference.
     */
    public function reference(string $uid): ?ReferencePlan
    {
        return $this->references[$uid] ?? null;
    }

    /**
     * Returns whether an Assets field allows the kind of file being fetched, warning when it doesn't.
     */
    private function allowsKind(FieldInterface $field, AssetFetch $fetch, ?array $allowedKinds): bool
    {
        $kind = (string)$fetch->asset->kind;
        if ($allowedKinds === null) {
            $allowedKinds = $field instanceof AssetsField && $field->restrictFiles ? ($field->allowedKinds ?? []) : null;
        }
        if ($allowedKinds === null || in_array($kind, $allowedKinds, true)) {
            return true;
        }

        $this->plan->warn(sprintf(
            'The asset "%s" is a file of the kind "%s", which the field "%s" doesn\'t allow. It won\'t be related there.',
            $fetch->asset->title ?? $fetch->asset->getFilename(),
            AssetsHelper::getFileKindLabel($kind),
            $field->name,
        ));

        return false;
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
     * Returns the UID a nested entry or Neo block will have in the Target environment: its source UID if that's free,
     * otherwise a new one (ADR-0003). It's the same in every site, so each site's content goes to the same nested
     * entry or block.
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
     * @param array<mixed> $element a Root element, nested entry or Neo block from the Payload
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
     * Returns the entry type of a nested entry if the field allows it, or null after warning that it will be skipped.
     *
     * @param array<string, EntryType> $allowed the field's entry types, by UID
     * @param string $subject what is skipped, in the warning: "Blocks" or "A nested entry"
     * @param string $pronoun "their" or "its", to match the subject
     */
    public function allowedEntryType(FieldInterface $field, array $allowed, mixed $typeUid, string $subject, string $pronoun): ?EntryType
    {
        return $this->allowedType('entryTypes', $field, $allowed, $typeUid, fn(string $ofType) => "$subject$ofType in \"$field->name\" will be skipped: $pronoun entry type isn't allowed in that field in this environment.");
    }

    /**
     * Returns the block type of a Neo block if the field has it, or null after warning that the block and the blocks
     * nested under it will be skipped.
     *
     * @param array<string, BlockType> $allowed the field's block types, by UID
     */
    public function allowedBlockType(FieldInterface $field, array $allowed, mixed $typeUid): ?BlockType
    {
        return $this->allowedType('neoBlockTypes', $field, $allowed, $typeUid, fn(string $ofType) => "A block$ofType in \"$field->name\" will be skipped, with the blocks nested under it: its block type doesn't exist in that field in this environment.");
    }

    /**
     * Returns the link type of a Hyper link if the field has it, or null after warning that the link will be skipped.
     *
     * @param array<string, HyperLink> $allowed the field's link types, by field layout UID
     */
    public function allowedLinkType(FieldInterface $field, array $allowed, mixed $typeUid): ?HyperLink
    {
        $type = $this->allowedType('hyperLinkTypes', $field, $allowed, $typeUid, fn(string $ofType) => "A link$ofType in \"$field->name\" will be skipped: its link type doesn't exist in that field in this environment.");

        // A link type whose type changed is skipped; the Drift has warned about it.
        return $type && !$this->drift->get('hyperLinkTypes', $typeUid)?->isSkipped() ? $type : null;
    }

    /**
     * Returns the field's type with the given UID, noting its Drift, or null after warning that it will be skipped.
     *
     * @template T of EntryType|BlockType|HyperLink
     * @param array<string, T> $allowed the field's types, by UID
     * @param callable(string): string $skipWarning builds the warning from " of type "Name"", or an empty string
     * @return T|null
     */
    private function allowedType(string $kind, FieldInterface $field, array $allowed, mixed $typeUid, callable $skipWarning): EntryType|BlockType|HyperLink|null
    {
        $typeUid = is_string($typeUid) ? $typeUid : null;
        $drift = $this->noteDrift($kind, $typeUid);
        $type = $typeUid !== null ? ($allowed[$typeUid] ?? null) : null;
        if (!$type) {
            $this->plan->warn($skipWarning($drift ? " of type \"$drift->name\"" : ''));
        }

        return $type;
    }

    /**
     * Returns what a nested entry of the given type is imported with, for the site being planned: whether it's
     * enabled, its title and slug (when its entry type has them) and its `setFieldValues()` input.
     *
     * @param array<mixed> $nested a nested entry from the Payload
     * @return array{enabled: bool, title?: string, slug?: string, fields: array<string, mixed>}
     */
    public function nestedEntryData(EntryType $entryType, array $nested): array
    {
        $content = $this->content($nested);
        $data = [
            'enabled' => (bool)($nested['enabled'] ?? true),
            'fields' => $this->fieldValues($entryType, $content['fields']),
        ];
        if ($entryType->hasTitleField && $content['title'] !== null) {
            $data['title'] = $content['title'];
        }
        if ($entryType->showSlugField && $content['slug'] !== null) {
            $data['slug'] = $content['slug'];
        }

        return $data;
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
     * Converts portable field values into `setFieldValues()` input for an entry type, a Neo block type, a Hyper link type,
     * or a volume's assets, keyed by handle.
     *
     * Values for missing or type-changed fields, and for fields that aren't in the field layout of the entry type or block type, are
     * skipped with a warning, as are values a Field adapter skips. Values for drifted fields are imported with a warning.
     *
     * @param array<mixed> $portable field UID => portable value
     * @param bool $ownerless whether nothing owns the values (a Hyper link), so fields that hold Owned content are skipped
     * with a warning
     * @return array<string, mixed>
     */
    public function fieldValues(EntryType|Volume|BlockType|HyperLink $owner, array $portable, bool $ownerless = false): array
    {
        $layoutFields = [];
        foreach ($owner->getFieldLayout()->getCustomFields() as $field) {
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
                    $this->plan->warn(sprintf(
                        'The field "%s" isn\'t in the "%s" %s\'s field layout in this environment. Its values will be skipped.',
                        $drift->name,
                        $owner instanceof HyperLink ? $owner->label : $owner->name,
                        match (true) {
                            $owner instanceof Volume => 'volume',
                            $owner instanceof BlockType => 'block type',
                            $owner instanceof HyperLink => 'link type',
                            default => 'entry type',
                        },
                    ));
                }
                continue;
            }

            $adapter = $this->adapters->adapterFor($field);
            if ($ownerless && $adapter instanceof RequiresOwner) {
                $this->plan->warn(sprintf(
                    'The field "%s" in the "%s" link type holds nested content, which needs an element to belong to. Its values will be skipped.',
                    $field->name,
                    $owner instanceof HyperLink ? $owner->label : $owner->name,
                ));
                continue;
            }
            if ($adapter) {
                $outer = $this->layout;
                $this->layout = $owner->getFieldLayout();
                try {
                    $imported = $adapter->import($field, $value, $this);
                } finally {
                    $this->layout = $outer;
                }
                if ($imported !== SkippedValue::Skipped) {
                    $values[$field->handle] = $imported;
                }
            }
        }

        return $values;
    }
}
