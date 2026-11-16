<?php

namespace simplygoodwork\craftportage\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Entry;
use simplygoodwork\craftportage\Plugin;
use simplygoodwork\craftportage\services\Portage;
use simplygoodwork\craftportage\tests\support\Schema;
use Solspace\Calendar\Elements\Event;
use Solspace\Calendar\FieldTypes\EventFieldType;

/**
 * Solspace Calendar events, a registered element type: a Calendar event field's References match by UID, then by
 * calendar UID + slug, and are limited to their calendar.
 */
class CalendarEventsTest extends Unit
{
    public function testAnEventFieldsReferencesResolveByUidThenByCalendarAndSlug(): void
    {
        $conferences = Schema::calendar('conferences');
        $summit = Schema::calendar('summit');
        $launch = Schema::calendarEvent($conferences, '2026-12-01 09:00', ['title' => 'Launch', 'slug' => 'launch']);
        $meetup = Schema::calendarEvent($conferences, '2026-12-02 18:00', [
            'title' => 'Meetup',
            'slug' => 'meetup',
            'rrule' => 'FREQ=WEEKLY;INTERVAL=1;COUNT=5',
            'freq' => 'WEEKLY',
            'interval' => 1,
            'count' => 5,
        ]);
        // The same slug in another calendar isn't a match.
        Schema::calendarEvent($summit, '2026-12-03 09:00', ['title' => 'Meetup', 'slug' => 'meetup']);
        $meetupSourceUid = $meetup->uid;
        $article = Schema::entryType('article', [Schema::field(EventFieldType::class, 'events')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Events', 'events' => [$launch->id, $meetup->id]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::changeUid($meetup);

        $plan = $this->portage()->preview($payload);
        $this->assertNull($plan->refusal);
        $this->assertSame('calendarEvent', $plan->references[$launch->uid]->elementType);
        $this->assertSame(['calendar' => $conferences->uid, 'slug' => 'meetup'], $plan->references[$meetupSourceUid]->reference['naturalKey']);
        $this->assertSame('uid', $plan->references[$launch->uid]->resolution->rule);
        $this->assertSame('naturalKey', $plan->references[$meetupSourceUid]->resolution->rule);
        $this->assertSame($meetup->id, $plan->references[$meetupSourceUid]->resolution->elementId);

        $result = $this->portage()->import($payload);

        $this->assertSame([], $result->roots[0]->warnings);
        $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $this->assertSame([$launch->id, $meetup->id], $this->eventIds($imported));
    }

    public function testAnEventIsMatchedByItsSlugInTheSiteItWasExportedFrom(): void
    {
        $german = Schema::site('german', ['language' => 'de']);
        $conferences = Schema::calendar('conferences');
        $festival = Schema::calendarEvent($conferences, '2026-12-01 09:00', ['title' => 'Festival', 'slug' => 'festival']);
        Schema::translate($festival, $german, ['title' => 'Fest', 'slug' => 'fest']);
        $festivalSourceUid = $festival->uid;
        $article = Schema::entryType('article', [Schema::field(EventFieldType::class, 'events')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Events', 'events' => [$festival->id]]);

        $payload = $this->portage()->export([$source], [$german->id]);
        Schema::deleteElement($source);
        Schema::changeUid($festival);

        $reference = $this->portage()->preview($payload)->references[$festivalSourceUid];
        $this->assertSame(['calendar' => $conferences->uid, 'slug' => 'fest'], $reference->reference['naturalKey']);
        $this->assertSame('naturalKey', $reference->resolution->rule);
        $this->assertSame($festival->id, $reference->resolution->elementId);
    }

    public function testAnUnresolvedEventCanBeReplacedOnlyByAnEventInItsCalendarAndIsNeverStubbed(): void
    {
        $conferences = Schema::calendar('conferences');
        $workshops = Schema::calendar('workshops');
        $gone = Schema::calendarEvent($conferences, '2026-12-01 09:00', ['title' => 'Gone', 'slug' => 'gone']);
        $goneUid = $gone->uid;
        $instead = Schema::calendarEvent($conferences, '2026-12-02 09:00', ['title' => 'Instead', 'slug' => 'instead']);
        $elsewhere = Schema::calendarEvent($workshops, '2026-12-03 09:00', ['title' => 'Elsewhere', 'slug' => 'elsewhere']);
        $article = Schema::entryType('article', [Schema::field(EventFieldType::class, 'events')]);
        $news = Schema::section('news', [$article]);
        $source = Schema::entry($news, $article, ['title' => 'Events', 'events' => [$gone->id]]);

        $payload = $this->portage()->export([$source]);
        Schema::deleteElement($source);
        Schema::deleteElement($gone);

        $plan = $this->portage()->preview($payload);
        $this->assertNull($plan->references[$goneUid]->resolution->elementId);
        $this->assertSame(['skip', 'replace'], $plan->references[$goneUid]->availableChoices);
        $this->assertSame(
            'The Reference "Gone" can\'t be Stubbed: Portage never Stubs events. Skip it or choose a replacement.',
            $this->portage()->preview($payload, [$goneUid => 'stub'])->refusal,
        );
        $this->assertSame(
            "The replacement for the Reference \"Gone\" can't be used: the calendarEvent with the ID $elsewhere->id isn't in the same calendar as the Reference.",
            $this->portage()->preview($payload, [$goneUid => $elsewhere->id])->refusal,
        );

        $result = $this->portage()->import($payload, [$goneUid => $instead->id]);

        $imported = Entry::find()->id($result->roots[0]->elementId)->status(null)->one();
        $this->assertSame([$instead->id], $this->eventIds($imported));
        $this->assertNull(Event::find()->uid($goneUid)->status(null)->one());
    }

    /**
     * @return list<int>
     */
    private function eventIds(Entry $entry): array
    {
        return array_map(fn(Event $event) => $event->id, $entry->getFieldValue('events')->status(null)->all());
    }

    private function portage(): Portage
    {
        return Plugin::getInstance()->getPortage();
    }
}
