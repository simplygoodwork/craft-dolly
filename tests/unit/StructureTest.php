<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Entry;
use craft\models\Section;
use simplygoodwork\craftportage\import\RootResult;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\tests\support\Schema;

/**
 * Structure placement: imported entries go under their parent, through the core service.
 */
class StructureTest extends Unit
{
    public function testAChildIsPlacedUnderItsParentMatchedByUidAfterItsExistingSiblings(): void
    {
        $page = Schema::entryType('page');
        $pages = Schema::section('pages', [$page], ['type' => Section::TYPE_STRUCTURE]);
        $about = Schema::entry($pages, $page, ['title' => 'About', 'slug' => 'about']);
        Schema::entry($pages, $page, ['title' => 'History', 'slug' => 'history', 'parentId' => $about->id]);
        $team = Schema::entry($pages, $page, ['title' => 'Team', 'slug' => 'team', 'parentId' => $about->id]);
        $payload = $this->portage()->export([$team]);
        Schema::deleteElement($team);
        Schema::entry($pages, $page, ['title' => 'Careers', 'slug' => 'careers', 'parentId' => $about->id]);

        $result = $this->portage()->import($payload);

        $this->assertSame('created', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertSame([], $result->roots[0]->warnings);
        $this->assertSame(['History', 'Careers', 'Team'], $this->childTitles($about));
    }

    public function testEntriesArePlacedAfterTheirSiblingsWhenTheSectionPlacesNewEntriesFirst(): void
    {
        $page = Schema::entryType('page');
        $pages = Schema::section('pages', [$page], [
            'type' => Section::TYPE_STRUCTURE,
            'defaultPlacement' => Section::DEFAULT_PLACEMENT_BEGINNING,
        ]);
        $home = Schema::entry($pages, $page, ['title' => 'Home', 'slug' => 'home']);
        $about = Schema::entry($pages, $page, ['title' => 'About', 'slug' => 'about']);
        $team = Schema::entry($pages, $page, ['title' => 'Team', 'slug' => 'team', 'parentId' => $about->id]);
        $payload = $this->portage()->export([$home, $team]);
        Schema::deleteElement($home);
        Schema::deleteElement($team);
        Schema::entry($pages, $page, ['title' => 'History', 'slug' => 'history', 'parentId' => $about->id]);
        Schema::entry($pages, $page, ['title' => 'Contact', 'slug' => 'contact']);

        $result = $this->portage()->import($payload);

        $this->assertSame(['created', 'created'], array_map(fn(RootResult $root) => $root->status, $result->roots));
        $this->assertSame(['Contact', 'About', 'Home'], $this->topLevelTitles($pages));
        $this->assertSame(['History', 'Team'], $this->childTitles($about));
    }

    public function testAChildIsPlacedUnderItsParentMatchedBySlugPath(): void
    {
        $page = Schema::entryType('page');
        $pages = Schema::section('pages', [$page], ['type' => Section::TYPE_STRUCTURE]);
        $company = Schema::entry($pages, $page, ['title' => 'Company', 'slug' => 'company']);
        $about = Schema::entry($pages, $page, ['title' => 'About', 'slug' => 'about', 'parentId' => $company->id]);
        $team = Schema::entry($pages, $page, ['title' => 'Team', 'slug' => 'team', 'parentId' => $about->id]);
        $payload = $this->portage()->export([$team]);
        Schema::deleteElement($team);
        Schema::changeUid($about);

        $result = $this->portage()->import($payload);

        $this->assertSame('created', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertSame([], $result->roots[0]->warnings);
        $this->assertSame(['Team'], $this->childTitles($about));
    }

    public function testAChildWhoseParentIsMissingIsPlacedAtTheTopLevelWithAWarning(): void
    {
        $page = Schema::entryType('page');
        $pages = Schema::section('pages', [$page], ['type' => Section::TYPE_STRUCTURE]);
        $about = Schema::entry($pages, $page, ['title' => 'About', 'slug' => 'about']);
        $team = Schema::entry($pages, $page, ['title' => 'Team', 'slug' => 'team', 'parentId' => $about->id]);
        $payload = $this->portage()->export([$team]);
        Schema::deleteElement($team);
        Schema::deleteElement($about);
        Schema::entry($pages, $page, ['title' => 'Contact', 'slug' => 'contact']);
        $warning = 'The parent entry "About" doesn\'t exist in this environment. The entry will be placed at the top level of the "Pages" section.';

        $plan = $this->portage()->preview($payload);
        $this->assertSame([$warning], $plan->roots[0]->warnings);

        $result = $this->portage()->import($payload);

        $this->assertSame('created', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertSame([$warning], $result->roots[0]->warnings);
        $this->assertSame(['Contact', 'Team'], $this->topLevelTitles($pages));
    }

    public function testAChildIsPlacedUnderTheStubChosenForItsMissingParent(): void
    {
        $page = Schema::entryType('page');
        $pages = Schema::section('pages', [$page], ['type' => Section::TYPE_STRUCTURE]);
        $about = Schema::entry($pages, $page, ['title' => 'About', 'slug' => 'about']);
        $team = Schema::entry($pages, $page, ['title' => 'Team', 'slug' => 'team', 'parentId' => $about->id]);
        $payload = $this->portage()->export([$team]);
        $aboutUid = $about->uid;
        Schema::deleteElement($team);
        Schema::deleteElement($about);

        $plan = $this->portage()->preview($payload);
        $this->assertSame(['skip', 'stub', 'replace'], $plan->references[$aboutUid]->availableChoices);

        $result = $this->portage()->import($payload, [$aboutUid => 'stub']);

        $this->assertSame('created', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertSame([], $result->roots[0]->warnings);
        $stub = Entry::find()->uid($aboutUid)->status(null)->one();
        $this->assertNotNull($stub);
        $this->assertSame([[$aboutUid, 'About']], array_map(fn(array $s) => [$s['uid'], $s['title']], $result->roots[0]->stubs));
        $this->assertSame(['Team'], $this->childTitles($stub));
    }

    public function testAChildWhoseParentIsOutsideTheTargetSectionIsPlacedAtTheTopLevelWithAWarning(): void
    {
        $page = Schema::entryType('page');
        $pages = Schema::section('pages', [$page], ['type' => Section::TYPE_STRUCTURE]);
        $docs = Schema::section('docs', [$page], ['type' => Section::TYPE_STRUCTURE]);
        $about = Schema::entry($pages, $page, ['title' => 'About', 'slug' => 'about']);
        $team = Schema::entry($pages, $page, ['title' => 'Team', 'slug' => 'team', 'parentId' => $about->id]);
        $payload = $this->portage()->export([$team]);

        $result = $this->portage()->import($payload, [], [$team->uid => ['section' => 'docs']]);

        $this->assertSame('created', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertSame(
            ['The parent entry "About" isn\'t in the "Docs" section in this environment. The entry will be placed at the top level of the "Docs" section.'],
            $result->roots[0]->warnings,
        );
        $this->assertSame(['Team'], $this->topLevelTitles($docs));
        $this->assertSame(['Team'], $this->childTitles($about));
    }

    public function testRootEntriesInAStructureImportAtTheTopLevelWithoutAWarning(): void
    {
        $page = Schema::entryType('page');
        $pages = Schema::section('pages', [$page], ['type' => Section::TYPE_STRUCTURE]);
        $about = Schema::entry($pages, $page, ['title' => 'About', 'slug' => 'about']);
        $payload = $this->portage()->export([$about]);
        Schema::deleteElement($about);
        Schema::entry($pages, $page, ['title' => 'Contact', 'slug' => 'contact']);

        $result = $this->portage()->import($payload);

        $this->assertSame('created', $result->roots[0]->status, implode(' ', $result->roots[0]->errors));
        $this->assertSame([], $result->roots[0]->warnings);
        $this->assertSame(['Contact', 'About'], $this->topLevelTitles($pages));
    }

    public function testAChildExportedWithItsParentIsPlacedUnderTheNewlyImportedParent(): void
    {
        $page = Schema::entryType('page');
        $pages = Schema::section('pages', [$page], ['type' => Section::TYPE_STRUCTURE]);
        $about = Schema::entry($pages, $page, ['title' => 'About', 'slug' => 'about']);
        $team = Schema::entry($pages, $page, ['title' => 'Team', 'slug' => 'team', 'parentId' => $about->id]);
        // The child comes first, so the parent has to be imported before it.
        $payload = $this->portage()->export([$team, $about]);

        $result = $this->portage()->import($payload);

        [$importedTeam, $importedAbout] = $result->roots;
        $this->assertSame('created', $importedTeam->status, implode(' ', $importedTeam->errors));
        $this->assertSame('created', $importedAbout->status, implode(' ', $importedAbout->errors));
        $this->assertSame([], $importedTeam->warnings);
        $this->assertNotSame($about->id, $importedAbout->elementId);
        $this->assertSame([$importedTeam->elementId], $this->childIds($importedAbout->elementId));
        $this->assertSame(['Team'], $this->childTitles($about));
    }

    public function testAChildIsPlacedUnderAParentThatIsMissingButExportedWithIt(): void
    {
        $page = Schema::entryType('page');
        $pages = Schema::section('pages', [$page], ['type' => Section::TYPE_STRUCTURE]);
        $about = Schema::entry($pages, $page, ['title' => 'About', 'slug' => 'about']);
        $team = Schema::entry($pages, $page, ['title' => 'Team', 'slug' => 'team', 'parentId' => $about->id]);
        $payload = $this->portage()->export([$about, $team]);
        Schema::deleteElement($team);
        Schema::deleteElement($about);

        // The parent is imported, so the Preview has no Unresolved reference to it to make a choice about.
        $this->assertSame([], $this->portage()->preview($payload)->references);

        $result = $this->portage()->import($payload);

        [$importedAbout, $importedTeam] = $result->roots;
        $this->assertSame('created', $importedTeam->status, implode(' ', $importedTeam->errors));
        $this->assertSame([], $importedTeam->warnings);
        $this->assertSame([$importedTeam->elementId], $this->childIds($importedAbout->elementId));
    }

    /**
     * @return list<int>
     */
    private function childIds(?int $parentId): array
    {
        $this->assertNotNull($parentId, 'The parent has no element ID');

        return array_map('intval', Entry::find()->descendantOf($parentId)->descendantDist(1)->status(null)->ids());
    }

    /**
     * @return list<string>
     */
    private function topLevelTitles(Section $section): array
    {
        return array_map(
            fn(Entry $entry) => (string)$entry->title,
            Entry::find()->sectionId($section->id)->level(1)->status(null)->all(),
        );
    }

    /**
     * @return list<string>
     */
    private function childTitles(Entry $parent): array
    {
        return array_map(
            fn(Entry $child) => (string)$child->title,
            Entry::find()->descendantOf($parent->id)->descendantDist(1)->status(null)->all(),
        );
    }

    private function portage(): Portage
    {
        return Plugin::getInstance()->getPortage();
    }
}
