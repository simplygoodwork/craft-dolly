<?php

namespace simplygoodwork\craftdolly\references\definitions;

use craft\base\ElementInterface;
use craft\helpers\Db;
use simplygoodwork\craftdolly\references\ElementTypeDefinition;
use Solspace\Calendar\Calendar;
use Solspace\Calendar\Elements\Db\EventQuery;
use Solspace\Calendar\Elements\Event;
use Solspace\Calendar\Models\CalendarModel;

/**
 * Solspace Calendar events, registered only when Calendar is installed. Natural key: calendar UID + slug, where the slug
 * is the event's in the site it was exported from and may match the event in any site, since slugs can differ per
 * site. Limited to their calendar. Never Stubbed.
 */
final class CalendarEventDefinition extends ElementTypeDefinition
{
    public function elementClass(): string
    {
        return Event::class;
    }

    public function naturalKey(ElementInterface $element): ?array
    {
        $calendar = $element instanceof Event && $element->calendarId !== null
            ? Calendar::getInstance()->calendars->getCalendarById($element->calendarId)
            : null;
        if (!$calendar || $element->slug === null) {
            return null;
        }

        return ['calendar' => $calendar->uid, 'slug' => $element->slug];
    }

    public function find(array $naturalKey): ?int
    {
        $calendar = self::calendar($naturalKey);
        $slug = $naturalKey['slug'] ?? null;
        if (!$calendar || !is_string($slug) || $slug === '') {
            return null;
        }

        /** @var EventQuery $query */
        $query = Event::find();
        // As arrays, so Calendar's query doesn't expand recurring events into occurrences.
        $rows = $query
            ->setCalendarId($calendar->id)
            ->slug(Db::escapeParam($slug))
            ->status(null)
            ->site('*')
            ->unique()
            ->asArray()
            ->limit(2)
            ->all();

        return count($rows) === 1 ? (int)$rows[0]['id'] : null;
    }

    public function sourceFor(array $naturalKey): ?string
    {
        $calendar = self::calendar($naturalKey);

        return $calendar ? "calendar:$calendar->id" : null;
    }

    public function isInSource(ElementInterface $element, string $source): bool
    {
        return $element instanceof Event && $element->calendarId !== null && $source === "calendar:$element->calendarId";
    }

    public function sourceName(): string
    {
        return 'calendar';
    }

    /**
     * @param array<mixed> $naturalKey
     */
    private static function calendar(array $naturalKey): ?CalendarModel
    {
        $uid = $naturalKey['calendar'] ?? null;

        return is_string($uid) ? Calendar::getInstance()->calendars->getCalendarByUid($uid) : null;
    }
}
