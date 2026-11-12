<?php

namespace simplygoodwork\craftportage\import;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\PropagationMethod;
use craft\helpers\ArrayHelper;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Site;
use simplygoodwork\craftportage\fields\FieldAdapterRegistry;
use simplygoodwork\craftportage\models\ImportLogEntry;
use simplygoodwork\craftportage\payload\DecodedPayload;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\references\ElementTypes;
use simplygoodwork\craftportage\references\NaturalKey;
use simplygoodwork\craftportage\references\ReferenceResolver;
use simplygoodwork\craftportage\schema\DriftReport;
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
     * @param array<mixed> $choices for Unresolved references, by Reference UID: "skip", "stub", the ID of a
     * replacement element, or for an asset "fetch" (into its own volume and folder) or `['choice' => 'fetch',
     * 'volume' => volume UID or handle, 'folder' => folder path]`. Omitted choices are "skip".
     * @param array<mixed> $rootChoices for Root elements, by Root element UID: `['mode' => 'create'|'overwrite',
     * 'section' => section UID or handle]`. Omitted choices are "create" in the Payload's own section. The key `*`
     * is the default for every Root element: Root elements that can't be overwritten are created instead, while an
     * explicit choice that can't be carried out is refused.
     */
    public function plan(DecodedPayload $payload, array $choices = [], array $rootChoices = []): ImportPlan
    {
        $envelope = $payload->envelope;
        $drift = (new SchemaManifest())->classify($envelope['schema'] ?? null);
        $uids = new UidClaims();
        $references = $this->planReferences($envelope['references'] ?? null);

        try {
            $this->applyChoices($references, $choices, new Stubs($uids, $this->rootUids($envelope['roots'])), $uids);
            $this->planFetchedAssetFields($references, $uids, $drift);
            $rootChoices = $this->parseRootChoices($rootChoices, $this->rootUids($envelope['roots']));
        } catch (InvalidChoiceException $e) {
            return ImportPlan::refused($e->getMessage());
        }

        $plans = [];
        $parentUids = [];

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

            try {
                $explicit = $rootChoices[$plan->sourceUid] ?? [];
                $this->planRoot(
                    $root,
                    new ImportContext($this->adapters, $references, $uids, $drift, $plan),
                    $explicit + ($rootChoices['*'] ?? []),
                    !isset($explicit['mode']),
                );
            } catch (InvalidChoiceException $e) {
                return ImportPlan::refused($e->getMessage());
            }
            $parentUids[] = [$plan, $root['parent'] ?? null];
        }

        $this->planPlacements($parentUids, $references);

        $earlierImports = Plugin::getInstance()->getImportLog()->getEntriesForPayload($payload->identity);

        return new ImportPlan(
            drift: $drift,
            roots: $plans,
            references: $references,
            payloadIdentity: $payload->identity,
            source: is_array($envelope['source'] ?? null) ? $envelope['source'] : [],
            earlierImports: $earlierImports,
            warnings: array_map(fn(ImportLogEntry $import) => $this->duplicateWarning($import), $earlierImports),
            envelope: $envelope,
        );
    }

    /**
     * Decides where each new Structure entry goes, after its new siblings: under its parent when the parent is a Root
     * element of this Payload, otherwise under the entry its parent Reference points at (matched, replacement or
     * Stub). A parent that doesn't exist, can't be imported or isn't in the Target section leaves the entry at the top
     * level, with a warning. Sibling order isn't preserved.
     *
     * @param list<array{RootPlan, mixed}> $parentUids each Root element's plan and the UID of its parent in the Payload
     * @param array<string, ReferencePlan> $references
     */
    private function planPlacements(array $parentUids, array $references): void
    {
        $roots = [];
        foreach ($parentUids as [$plan]) {
            if ($plan->sourceUid !== null) {
                $roots[$plan->sourceUid] ??= $plan;
            }
        }

        foreach ($parentUids as [$plan, $parentUid]) {
            $section = $plan->section;
            if (!$plan->entry || !$section || $section->type !== Section::TYPE_STRUCTURE) {
                continue;
            }
            $moveToEnd = $section->defaultPlacement === Section::DEFAULT_PLACEMENT_BEGINNING;
            $plan->placement = new Placement(moveToEnd: $moveToEnd);
            if (!is_string($parentUid)) {
                continue;
            }

            $parentRoot = $roots[$parentUid] ?? null;
            if ($parentRoot && $parentRoot !== $plan) {
                $this->placeUnderRoot($plan, $parentRoot, $section, $moveToEnd);
                continue;
            }

            $reference = $references[$parentUid] ?? null;
            $target = $reference?->target();
            if (!$reference || !$target instanceof Entry) {
                $plan->warn(sprintf(
                    'The parent entry %s doesn\'t exist in this environment. %s',
                    $reference ? $reference->name() : $parentUid,
                    $this->placedAtTopLevel($section),
                ));
                continue;
            }
            if ($target->sectionId !== $section->id) {
                $plan->warn($this->parentInAnotherSection($reference->name(), $section));
                continue;
            }

            if ($reference->stub) {
                $plan->stubs[$reference->uid] = $reference;
            }
            $plan->placement = new Placement(parent: $target, moveToEnd: $moveToEnd);
        }
    }

    /**
     * Places a new Structure entry under its parent, a Root element of this Payload: under the entry it creates, or
     * the entry it overwrites.
     */
    private function placeUnderRoot(RootPlan $plan, RootPlan $parentRoot, Section $section, bool $moveToEnd): void
    {
        $name = $parentRoot->title !== null ? "\"$parentRoot->title\"" : (string)$parentRoot->sourceUid;

        if ($parentRoot->isBlocked() || $this->isAncestor($plan, $parentRoot)) {
            $plan->warn(sprintf('The parent entry %s can\'t be imported. %s', $name, $this->placedAtTopLevel($section)));
        } elseif ($parentRoot->section?->id !== $section->id) {
            $plan->warn($this->parentInAnotherSection($name, $section));
        } elseif ($parentRoot->overwrite) {
            $plan->placement = new Placement(parent: $parentRoot->overwrite->canonical, moveToEnd: $moveToEnd);
        } else {
            $plan->placement = new Placement(parentRoot: $parentRoot, parentName: $name, moveToEnd: $moveToEnd);
        }
    }

    /**
     * Returns whether a Root element will be placed above another, directly or further up, which would make placing
     * it under the other a loop. Only a damaged Payload has one.
     */
    private function isAncestor(RootPlan $plan, RootPlan $other): bool
    {
        for ($ancestor = $other->placement?->parentRoot; $ancestor; $ancestor = $ancestor->placement?->parentRoot) {
            if ($ancestor === $plan) {
                return true;
            }
        }

        return false;
    }

    private function parentInAnotherSection(string $parentName, Section $section): string
    {
        return sprintf(
            'The parent entry %s isn\'t in the "%s" section in this environment. %s',
            $parentName,
            $section->name,
            $this->placedAtTopLevel($section),
        );
    }

    private function placedAtTopLevel(Section $section): string
    {
        return "The entry will be placed at the top level of the \"$section->name\" section.";
    }

    private function duplicateWarning(ImportLogEntry $import): string
    {
        $date = DateTimeHelper::toDateTime($import->dateCreated);
        $when = $date ? $date->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()))->format('j M Y \a\t H:i') : 'an unknown date';
        $user = $import->userId !== null ? User::find()->id($import->userId)->status(null)->one() : null;
        $who = $user ? sprintf(' by "%s"', $user->getName()) : ' by a user that no longer exists';

        return "This Payload was already imported here on $when$who.";
    }

    /**
     * Checks the user's choices for Root elements, and resolves the sections they chose.
     *
     * @param array<mixed> $choices
     * @param array<string, true> $rootUids the Root elements in the Payload
     * @return array<string, array{mode?: string, section?: Section}>
     * @throws InvalidChoiceException if a choice names something that isn't a Root element, or an unknown mode or section
     */
    private function parseRootChoices(array $choices, array $rootUids): array
    {
        $parsed = [];

        foreach ($choices as $uid => $choice) {
            if (!is_string($uid) || ($uid !== '*' && !isset($rootUids[$uid]))) {
                throw new InvalidChoiceException("The Payload has no Root element with the UID $uid, so its mode and section can't be chosen.");
            }
            if (!is_array($choice)) {
                throw new InvalidChoiceException("The choice for the Root element $uid isn't valid. Choose a \"mode\" and a \"section\".");
            }

            $mode = $choice['mode'] ?? null;
            if ($mode !== null && !in_array($mode, [RootPlan::MODE_CREATE, RootPlan::MODE_OVERWRITE], true)) {
                throw new InvalidChoiceException(sprintf(
                    'The mode %s for the Root element %s isn\'t valid. Choose "create" or "overwrite".',
                    Json::encode($mode),
                    $uid,
                ));
            }
            if ($mode !== null) {
                $parsed[$uid]['mode'] = $mode;
            }

            $section = $choice['section'] ?? null;
            if ($section !== null) {
                $entries = Craft::$app->getEntries();
                $found = is_string($section) ? ($entries->getSectionByUid($section) ?? $entries->getSectionByHandle($section)) : null;
                if (!$found) {
                    throw new InvalidChoiceException(sprintf(
                        'The section %s for the Root element %s doesn\'t exist in this environment, so it can\'t be chosen.',
                        Json::encode($section),
                        $uid,
                    ));
                }
                $parsed[$uid]['section'] = $found;
            }
        }

        return $parsed;
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
     * Sets what happens to each Unresolved reference: skip it unless the user chose a Stub, a replacement or, for an
     * asset, to fetch it.
     *
     * @param array<string, ReferencePlan> $references
     * @param array<mixed> $choices
     * @throws InvalidChoiceException if a choice names an unknown Reference or can't be carried out
     */
    private function applyChoices(array $references, array $choices, Stubs $stubs, UidClaims $uids): void
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

            $isAsset = $reference->elementType === 'asset';
            $whyNoFetch = $isAsset ? $this->whyNoFetch($reference) : null;
            $reference->fetchUnavailable = $whyNoFetch !== null ? "It can't be fetched: $whyNoFetch." : null;
            $reference->availableChoices = [
                ReferencePlan::CHOICE_SKIP,
                ...($stubs->canStub($reference) ? [ReferencePlan::CHOICE_STUB] : []),
                ...($isAsset && $whyNoFetch === null ? [ReferencePlan::CHOICE_FETCH] : []),
                ReferencePlan::CHOICE_REPLACE,
            ];
            $choice = $choices[$uid] ?? ReferencePlan::CHOICE_SKIP;

            if ($choice === ReferencePlan::CHOICE_SKIP) {
                $reference->choice = ReferencePlan::CHOICE_SKIP;
            } elseif ($choice === ReferencePlan::CHOICE_STUB) {
                $reference->stub = $stubs->build($reference) ?? throw new InvalidChoiceException($this->whyNoStub($reference, $stubs));
                $reference->choice = ReferencePlan::CHOICE_STUB;
            } elseif ($choice === ReferencePlan::CHOICE_FETCH || (is_array($choice) && ($choice['choice'] ?? null) === ReferencePlan::CHOICE_FETCH)) {
                $reference->fetch = $this->planFetch($reference, is_array($choice) ? $choice : [], $whyNoFetch, $uids);
                $reference->choice = ReferencePlan::CHOICE_FETCH;
            } elseif (is_int($choice) || (is_string($choice) && ctype_digit($choice))) {
                $reference->replacement = $this->replacement($reference, (int)$choice);
                $reference->choice = ReferencePlan::CHOICE_REPLACE;
            } else {
                throw new InvalidChoiceException(sprintf(
                    'The choice for the Reference %s isn\'t valid. Choose %s.',
                    $reference->name(),
                    $isAsset ? '"skip", "fetch" or the ID of a replacement asset' : '"skip", "stub" or the ID of a replacement element',
                ));
            }
        }
    }

    private function whyNoStub(ReferencePlan $reference, Stubs $stubs): string
    {
        if ($reference->elementType === 'asset') {
            return sprintf(
                'The asset %s can\'t be Stubbed: Portage never Stubs assets. %s',
                $reference->name(),
                $reference->fetchUnavailable === null ? 'Skip it, fetch it or choose a replacement.' : 'Skip it or choose a replacement.',
            );
        }

        if ($stubs->isRoot($reference)) {
            return "The Reference {$reference->name()} can't be Stubbed: this Payload imports it as a Root element. Skip it or choose a replacement.";
        }

        if ($reference->elementType === 'user') {
            return "The user {$reference->name()} can't be Stubbed: Portage never creates users. Skip it or choose a replacement user.";
        }

        $whyForbidden = $stubs->whyForbidden($reference);
        if ($whyForbidden !== null) {
            return "The Reference {$reference->name()} can't be Stubbed: $whyForbidden. Skip it or choose a replacement.";
        }

        return "The Reference {$reference->name()} can't be Stubbed: its section or group doesn't exist in this environment. Skip it or choose a replacement.";
    }

    /**
     * Returns why an unresolved asset's file can't be fetched from its source URL, or null if it can.
     */
    private function whyNoFetch(ReferencePlan $reference): ?string
    {
        $url = $reference->reference['url'] ?? null;
        if (!is_string($url) || $url === '') {
            return 'its volume has no public URL in the Source environment';
        }
        if (!AssetFetcher::isFetchableUrl($url)) {
            return 'only http and https URLs can be fetched';
        }
        $filename = $this->assetFilename($reference);
        if ($filename === null) {
            return 'the Payload doesn\'t carry its filename';
        }
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, Craft::$app->getConfig()->getGeneral()->allowedFileExtensions, true)) {
            return "this environment doesn't allow \".$extension\" files";
        }

        return null;
    }

    private function assetFilename(ReferencePlan $reference): ?string
    {
        $filename = $reference->reference['naturalKey']['filename'] ?? null;

        // A filename is never a path.
        return is_string($filename) && $filename !== '' && basename($filename) === $filename ? $filename : null;
    }

    /**
     * Plans fetching an unresolved asset into the chosen volume and folder, or else its own: an unsaved asset with
     * the title, alt text and focal point the Reference carries. Its custom field values are planned afterwards, once
     * every Reference's choice is known.
     *
     * @param array<mixed> $choice
     * @throws InvalidChoiceException if the asset can't be fetched there
     */
    private function planFetch(ReferencePlan $reference, array $choice, ?string $whyNoFetch, UidClaims $uids): AssetFetch
    {
        $name = $reference->name();
        if ($reference->elementType !== 'asset') {
            throw new InvalidChoiceException("The Reference $name isn't an asset, so it can't be fetched. Choose \"skip\", \"stub\" or the ID of a replacement element.");
        }
        if ($whyNoFetch !== null) {
            throw new InvalidChoiceException("The asset $name can't be fetched: $whyNoFetch. Skip it or choose a replacement.");
        }

        $key = $reference->reference['naturalKey'];
        $volumes = Craft::$app->getVolumes();
        $chosenVolume = $choice['volume'] ?? null;
        if ($chosenVolume !== null) {
            $volume = is_string($chosenVolume) ? ($volumes->getVolumeByUid($chosenVolume) ?? $volumes->getVolumeByHandle($chosenVolume)) : null;
            if (!$volume) {
                throw new InvalidChoiceException(sprintf(
                    'The volume %s chosen for the asset %s doesn\'t exist in this environment.',
                    Json::encode($chosenVolume),
                    $name,
                ));
            }
        } else {
            $volume = is_string($key['volume'] ?? null) ? $volumes->getVolumeByUid($key['volume']) : null;
            if (!$volume) {
                throw new InvalidChoiceException("The asset $name can't be fetched into its own volume: it doesn't exist in this environment. Choose a volume to fetch it into.");
            }
        }

        $folderPath = $choice['folder'] ?? $key['folder'] ?? '';
        $segments = is_string($folderPath) ? array_filter(explode('/', $folderPath), fn(string $segment) => $segment !== '') : null;
        if ($segments === null || array_intersect($segments, ['.', '..']) || str_contains((string)$folderPath, '\\')) {
            throw new InvalidChoiceException(sprintf('The folder %s chosen for the asset %s isn\'t a valid folder path.', Json::encode($folderPath), $name));
        }
        $folderPath = $segments ? implode('/', $segments) . '/' : '';

        $filename = (string)$this->assetFilename($reference);
        $asset = new Asset();
        $asset->setVolumeId($volume->id);
        $asset->setFilename($filename);
        $asset->kind = AssetsHelper::getFileKindByExtension($filename);
        $asset->title = $reference->title;
        $metadata = is_array($reference->reference['asset'] ?? null) ? $reference->reference['asset'] : [];
        if (is_string($metadata['alt'] ?? null)) {
            $asset->alt = $metadata['alt'];
        }
        $focalPoint = $metadata['focalPoint'] ?? null;
        if (is_array($focalPoint) && is_numeric($focalPoint['x'] ?? null) && is_numeric($focalPoint['y'] ?? null)) {
            $asset->setFocalPoint(['x' => (float)$focalPoint['x'], 'y' => (float)$focalPoint['y']]);
        }

        $fetch = new AssetFetch((string)$reference->reference['url'], $volume, $folderPath, $asset);
        $this->checkFetchPermission($fetch, $name);
        $asset->uid = $uids->claim($reference->uid);

        return $fetch;
    }

    /**
     * @throws InvalidChoiceException if the user running the Import may not save assets in the volume, or create
     * the folder when it doesn't exist
     */
    private function checkFetchPermission(AssetFetch $fetch, string $name): void
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return;
        }

        $volume = $fetch->volume;
        $folder = NaturalKey::folder($volume, $fetch->folderPath);
        $fetch->asset->folderId = $folder->id ?? Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)?->id;
        if (!Craft::$app->getElements()->canSave($fetch->asset, $user)) {
            throw new InvalidChoiceException("The asset $name can't be fetched: you don't have permission to save assets in the \"$volume->name\" volume. Skip it or choose a replacement.");
        }
        if (!$folder && !$user->can("createFolders:$volume->uid")) {
            throw new InvalidChoiceException("The asset $name can't be fetched into {$fetch->location()}: the folder doesn't exist here, and you don't have permission to create folders in the \"$volume->name\" volume. Choose another folder.");
        }
    }

    /**
     * Plans the custom field values each fetched asset carries, in its volume's field layout. Their relations may
     * point at any Reference, so this runs once every Reference's choice is known.
     *
     * @param array<string, ReferencePlan> $references
     */
    private function planFetchedAssetFields(array $references, UidClaims $uids, DriftReport $drift): void
    {
        foreach ($references as $reference) {
            $fetch = $reference->fetch;
            $fields = $reference->reference['asset']['fields'] ?? null;
            if (!$fetch || !is_array($fields) || !$fields) {
                continue;
            }
            $context = new ImportContext($this->adapters, $references, $uids, $drift, $fetch->fieldPlan);
            $fetch->asset->setFieldValues($context->fieldValues($fetch->volume, $fields));
        }
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
     * @param array{mode?: string, section?: Section} $choice
     * @param bool $modeIsDefault whether the Import mode comes from the `*` default rather than a choice for this Root element
     * @throws InvalidChoiceException if the choice can't be carried out
     */
    private function planRoot(array $root, ImportContext $context, array $choice, bool $modeIsDefault): void
    {
        $plan = $context->plan;

        if (ElementTypes::classFor($root['elementType'] ?? null) !== Entry::class) {
            $plan->block('Unsupported element type: ' . Json::encode($root['elementType'] ?? null));
            return;
        }

        $chosenSection = $choice['section'] ?? null;
        $section = $chosenSection ?? (is_string($root['section'] ?? null) ? Craft::$app->getEntries()->getSectionByUid($root['section']) : null);
        if (!$section) {
            $plan->block($context->drift->get('sections', $root['section'] ?? null)->message
                ?? 'The section doesn\'t exist in this environment.');
            return;
        }
        $plan->section = $section;
        if (!$chosenSection) {
            $context->noteDrift('sections', $section->uid);
        }

        $entryType = ArrayHelper::firstWhere($section->getEntryTypes(), 'uid', $root['type'] ?? null);
        if (!$entryType instanceof EntryType && $chosenSection) {
            throw new InvalidChoiceException(sprintf(
                '%s isn\'t allowed in the "%s" section, so %s can\'t be imported there. Choose a section that allows it.',
                $this->entryTypeName($root, $context),
                $section->name,
                $plan->label(),
            ));
        }
        if (!$entryType instanceof EntryType) {
            $plan->block(sprintf(
                '%s isn\'t available in the "%s" section in this environment.',
                $this->entryTypeName($root, $context),
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

        $this->matchEntry($root, $section, $entryType, $context);
        $overwrite = ($choice['mode'] ?? RootPlan::MODE_CREATE) === RootPlan::MODE_OVERWRITE;
        if ($overwrite && !in_array(RootPlan::MODE_OVERWRITE, $plan->availableModes, true)) {
            if (!$modeIsDefault) {
                throw new InvalidChoiceException(sprintf(
                    '%s can\'t be overwritten: %s',
                    ucfirst($plan->label()),
                    $plan->overwriteUnavailable ?? 'no entry in this environment matches it by UID or by its section and slug. Create it as a new entry instead.',
                ));
            }
            $overwrite = false;
        }
        if ($overwrite) {
            $this->planOverwrite($root, $sites, $entryType, $context);
            return;
        }

        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $entryType->id,
            'siteId' => $sites[0]['site']->id,
        ]);
        $user = Craft::$app->getUser()->getIdentity();
        if ($user && !Craft::$app->getElements()->canSave($entry, $user)) {
            $plan->block(sprintf(
                'You don\'t have permission to create entries in the "%s" section, so %s can\'t be imported.',
                $section->name,
                $plan->title !== null ? "\"$plan->title\"" : 'the entry',
            ));
            return;
        }
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
     * Names the entry type of a Root element or nested entry in a message.
     *
     * @param array<mixed> $root
     */
    private function entryTypeName(array $root, ImportContext $context): string
    {
        $name = $context->drift->get('entryTypes', $root['type'] ?? null)?->name;

        return $name !== null ? "The entry type \"$name\"" : 'The entry type';
    }

    /**
     * Finds the existing entry the Root element matches: by UID, then by Natural key in the Target section. A UID
     * match that can't be overwritten (it's in another section, or has another entry type) doesn't hide a Natural
     * key match that can. Records the match and the Import modes it allows on the plan.
     *
     * @param array<mixed> $root
     */
    private function matchEntry(array $root, Section $section, EntryType $entryType, ImportContext $context): void
    {
        $plan = $context->plan;
        $resolver = new ReferenceResolver();
        $content = $context->content($root);
        $matches = [
            $resolver->resolve(['elementType' => 'entry', 'uid' => $root['uid'] ?? null]),
            $resolver->resolve([
                'elementType' => 'entry',
                'naturalKey' => [
                    'section' => $section->uid,
                    'slug' => $content['slug'],
                    'path' => is_array($root['path'] ?? null) ? $root['path'] : [],
                ],
            ]),
        ];

        foreach ($matches as $match) {
            if (!$match->isResolved() || $match->elementId === $plan->match?->elementId) {
                continue;
            }

            $existing = Entry::find()->id($match->elementId)->status(null)->site('*')->unique()->one();
            $problem = $existing
                ? $this->overwriteProblem($existing, $section, $entryType)
                : "the entry matching {$plan->label()} can't be loaded.";

            // Keep the first match to show, unless a later one can be overwritten.
            if ($plan->match === null || $problem === null) {
                $plan->match = $match;
                $plan->matchedEntry = $existing;
                $plan->overwriteUnavailable = $problem;
            }
            if ($problem === null) {
                $plan->availableModes = [RootPlan::MODE_CREATE, RootPlan::MODE_OVERWRITE];
                return;
            }
        }
    }

    /**
     * Returns why an entry can't be overwritten by a Root element going into the Target section, or null if it can.
     */
    private function overwriteProblem(Entry $existing, Section $section, EntryType $entryType): ?string
    {
        $name = $existing->title !== null ? "\"$existing->title\"" : "entry #$existing->id";

        if ($existing->sectionId !== $section->id) {
            return sprintf(
                'it matches %s in the "%s" section, not in the "%s" section it will be imported into.',
                $name,
                $existing->getSection()->name,
                $section->name,
            );
        }
        if ($existing->typeId !== $entryType->id) {
            return sprintf(
                'it matches %s, which has the entry type "%s", not "%s".',
                $name,
                $existing->getType()->name,
                $entryType->name,
            );
        }

        $user = Craft::$app->getUser()->getIdentity();
        $elements = Craft::$app->getElements();
        if ($user && !$elements->canView($existing, $user)) {
            return sprintf(
                'you don\'t have permission to view %s in the "%s" section, so you can\'t create a draft on it.',
                $name,
                $section->name,
            );
        }
        if ($user && !$elements->canCreateDrafts($existing, $user)) {
            return sprintf('you don\'t have permission to create drafts on %s in the "%s" section.', $name, $section->name);
        }

        return null;
    }

    /**
     * Plans a draft on the matched entry: the Payload's title, slug and field values for each site the entry exists
     * in. Fields the Payload doesn't carry, or that are skipped, aren't in the plan, so the draft keeps their values.
     *
     * @param array<mixed> $root
     * @param non-empty-list<array{site: Site, uid: string}> $sites
     */
    private function planOverwrite(array $root, array $sites, EntryType $entryType, ImportContext $context): void
    {
        $plan = $context->plan;
        $canonical = $plan->matchedEntry ?? throw new InvalidChoiceException("The entry matching {$plan->label()} can't be loaded.");

        $existingSiteIds = array_map(
            fn(Entry $entry) => (int)$entry->siteId,
            Entry::find()->id($canonical->id)->status(null)->site('*')->all(),
        );
        $sitePlans = [];

        foreach ($sites as $site) {
            if (!in_array($site['site']->id, $existingSiteIds, true)) {
                $plan->warn(sprintf(
                    'The entry "%s" doesn\'t exist in the site "%s" in this environment. Content for that site will be skipped.',
                    $canonical->title,
                    $site['site']->getName(),
                ));
                continue;
            }
            $context->siteUid = $site['uid'];
            $content = $context->content($root);
            $sitePlans[] = new SitePlan(
                $site['site'],
                $content['title'],
                $content['slug'],
                $context->fieldValues($entryType, $content['fields']),
            );
        }

        if (!$sitePlans) {
            $plan->block("The entry \"$canonical->title\" doesn't exist in any of the Payload's sites in this environment.");
            return;
        }

        $plan->mode = RootPlan::MODE_OVERWRITE;
        $plan->overwrite = new OverwritePlan($canonical, $sitePlans);
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
