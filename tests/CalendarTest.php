<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class CalendarTest extends TestCase
{
    // Build a minimal Calendar wired to a static ICS string (no HTTP, no cache).
    private function calendarFrom(string $ics, array $opts = []): Calendar
    {
        $cache = tempnam(sys_get_temp_dir(), 'cal_test_');
        file_put_contents($cache, $ics);

        return new Calendar(
            url:         'unused',
            cachePath:   $cache,
            cacheTtl:    PHP_INT_MAX,
            filter:      $opts['filter']      ?? [],
            labelFormat: $opts['labelFormat'] ?? '{date} — {title}',
        );
    }

    // Build a VEVENT block.
    private function vevent(array $props): string
    {
        $lines = ["BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n"];
        foreach ($props as $k => $v) {
            $lines[] = "$k:$v\r\n";
        }
        $lines[] = "END:VEVENT\r\nEND:VCALENDAR";
        return implode('', $lines);
    }

    // -------------------------------------------------------------------------
    // Parsing
    // -------------------------------------------------------------------------

    public function testParsesSimpleEvent(): void
    {
        $ics = $this->vevent([
            'UID'     => 'evt-001',
            'SUMMARY' => 'Séance de gym',
            'DTSTART' => '20260101T100000Z',
            'DTEND'   => '20260101T120000Z',
        ]);

        // past session — use a wide window by faking "now" through the label format
        // (Calendar uses DateTimeImmutable internally; we just check it parses)
        $cal      = $this->calendarFrom($ics);
        $sessions = $cal->getSessions('fr');

        // The event is in the past (>30 days) so it won't appear — test the parser
        // directly via reflection instead.
        $parse = new ReflectionMethod(Calendar::class, 'parseIcs');
        $parse->setAccessible(true);
        $events = $parse->invoke($cal, $ics);

        $this->assertCount(1, $events);
        $this->assertSame('evt-001', $events[0]['uid']);
        $this->assertSame('Séance de gym', $events[0]['title']);
    }

    public function testIgnoresEventsMissingRequiredFields(): void
    {
        $ics = $this->vevent([
            'UID'     => 'evt-incomplete',
            'SUMMARY' => 'Sans dates',
            // DTSTART and DTEND missing intentionally
        ]);

        $parse = new ReflectionMethod(Calendar::class, 'parseIcs');
        $parse->setAccessible(true);
        $cal    = $this->calendarFrom($ics);
        $events = $parse->invoke($cal, $ics);

        $this->assertCount(0, $events);
    }

    public function testUnfoldsMultilineValues(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n"
             . "UID:evt-fold\r\n"
             . "SUMMARY:Long summ\r\n ary here\r\n"  // folded line
             . "DTSTART:20260601T100000Z\r\n"
             . "DTEND:20260601T120000Z\r\n"
             . "END:VEVENT\r\nEND:VCALENDAR";

        $parse = new ReflectionMethod(Calendar::class, 'parseIcs');
        $parse->setAccessible(true);
        $cal    = $this->calendarFrom($ics);
        $events = $parse->invoke($cal, $ics);

        $this->assertSame('Long summary here', $events[0]['title']);
    }

    // -------------------------------------------------------------------------
    // RRULE expansion
    // -------------------------------------------------------------------------

    public function testExpandWeeklyRecurrence(): void
    {
        // Weekly event starting 2026-06-01 — we expect occurrences within the
        // Calendar window (last 30 days up to today 2026-06-26).
        $ics = $this->vevent([
            'UID'     => 'evt-weekly',
            'SUMMARY' => 'Hebdo',
            'DTSTART' => '20260601T100000Z',
            'DTEND'   => '20260601T120000Z',
            'RRULE'   => 'FREQ=WEEKLY;COUNT=10',
        ]);

        $expand = new ReflectionMethod(Calendar::class, 'expand');
        $expand->setAccessible(true);

        $parse = new ReflectionMethod(Calendar::class, 'parseIcs');
        $parse->setAccessible(true);

        $cal    = $this->calendarFrom($ics);
        $events = $parse->invoke($cal, $ics);
        $event  = $events[0];

        $from  = new DateTimeImmutable('2026-05-27');
        $until = new DateTimeImmutable('2026-06-26T23:59:59');

        $occurrences = $expand->invoke($cal, $event, $from, $until);

        // 2026-06-01, 2026-06-08, 2026-06-15, 2026-06-22 = 4 occurrences
        $this->assertCount(4, $occurrences);
        $this->assertSame('evt-weekly_20260601', $occurrences[0]['uid']);
        $this->assertSame('evt-weekly_20260622', $occurrences[3]['uid']);
    }

    public function testExpandWeeklyRespectsCount(): void
    {
        $ics = $this->vevent([
            'UID'     => 'evt-cnt',
            'SUMMARY' => 'Limité',
            'DTSTART' => '20260601T100000Z',
            'DTEND'   => '20260601T110000Z',
            'RRULE'   => 'FREQ=WEEKLY;COUNT=2',
        ]);

        $expand = new ReflectionMethod(Calendar::class, 'expand');
        $expand->setAccessible(true);
        $parse  = new ReflectionMethod(Calendar::class, 'parseIcs');
        $parse->setAccessible(true);

        $cal    = $this->calendarFrom($ics);
        $events = $parse->invoke($cal, $ics);

        $from  = new DateTimeImmutable('2026-05-01');
        $until = new DateTimeImmutable('2026-12-31');

        $occurrences = $expand->invoke($cal, $events[0], $from, $until);
        $this->assertCount(2, $occurrences);
    }

    public function testExpandWeeklyRespectsUntil(): void
    {
        $ics = $this->vevent([
            'UID'     => 'evt-until',
            'SUMMARY' => 'Jusqu\'à',
            'DTSTART' => '20260601T100000Z',
            'DTEND'   => '20260601T110000Z',
            'RRULE'   => 'FREQ=WEEKLY;UNTIL=20260615T235959Z',
        ]);

        $expand = new ReflectionMethod(Calendar::class, 'expand');
        $expand->setAccessible(true);
        $parse  = new ReflectionMethod(Calendar::class, 'parseIcs');
        $parse->setAccessible(true);

        $cal    = $this->calendarFrom($ics);
        $events = $parse->invoke($cal, $ics);

        $from  = new DateTimeImmutable('2026-05-01');
        $until = new DateTimeImmutable('2026-12-31');

        $occurrences = $expand->invoke($cal, $events[0], $from, $until);
        // 2026-06-01, 2026-06-08, 2026-06-15 → 3
        $this->assertCount(3, $occurrences);
    }

    public function testExpandMonthlyRecurrence(): void
    {
        $ics = $this->vevent([
            'UID'     => 'evt-monthly',
            'SUMMARY' => 'Mensuel',
            'DTSTART' => '20260101T100000Z',
            'DTEND'   => '20260101T120000Z',
            'RRULE'   => 'FREQ=MONTHLY',
        ]);

        $expand = new ReflectionMethod(Calendar::class, 'expand');
        $expand->setAccessible(true);
        $parse  = new ReflectionMethod(Calendar::class, 'parseIcs');
        $parse->setAccessible(true);

        $cal    = $this->calendarFrom($ics);
        $events = $parse->invoke($cal, $ics);

        $from  = new DateTimeImmutable('2026-01-01');
        $until = new DateTimeImmutable('2026-06-30');

        $occurrences = $expand->invoke($cal, $events[0], $from, $until);
        $this->assertCount(6, $occurrences);
    }

    public function testNonRecurringEventOutsideWindowIsExcluded(): void
    {
        $ics = $this->vevent([
            'UID'     => 'evt-old',
            'SUMMARY' => 'Vieux',
            'DTSTART' => '20200101T100000Z',
            'DTEND'   => '20200101T120000Z',
        ]);

        $expand = new ReflectionMethod(Calendar::class, 'expand');
        $expand->setAccessible(true);
        $parse  = new ReflectionMethod(Calendar::class, 'parseIcs');
        $parse->setAccessible(true);

        $cal    = $this->calendarFrom($ics);
        $events = $parse->invoke($cal, $ics);

        $from  = new DateTimeImmutable('2026-01-01');
        $until = new DateTimeImmutable('2026-12-31');

        $this->assertCount(0, $expand->invoke($cal, $events[0], $from, $until));
    }

    // -------------------------------------------------------------------------
    // Filtering
    // -------------------------------------------------------------------------

    public function testFilterByTitlePattern(): void
    {
        $sessions = [
            ['uid' => '1', 'title' => 'Gym avancé',  'location' => ''],
            ['uid' => '2', 'title' => 'Yoga débutant','location' => ''],
            ['uid' => '3', 'title' => 'Gym débutant', 'location' => ''],
        ];

        $apply = new ReflectionMethod(Calendar::class, 'applyFilter');
        $apply->setAccessible(true);

        $cal    = $this->calendarFrom('BEGIN:VCALENDAR\r\nEND:VCALENDAR');
        $result = $apply->invoke($cal, $sessions, ['title_patterns' => ['/Gym/']]);

        $this->assertCount(2, $result);
        $this->assertSame('1', $result[0]['uid']);
        $this->assertSame('3', $result[1]['uid']);
    }

    public function testFilterByLocationPattern(): void
    {
        $sessions = [
            ['uid' => '1', 'title' => 'A', 'location' => 'Salle Paris'],
            ['uid' => '2', 'title' => 'B', 'location' => 'Gymnase Lyon'],
            ['uid' => '3', 'title' => 'C', 'location' => 'Dojo Paris'],
        ];

        $apply = new ReflectionMethod(Calendar::class, 'applyFilter');
        $apply->setAccessible(true);

        $cal    = $this->calendarFrom('BEGIN:VCALENDAR\r\nEND:VCALENDAR');
        $result = $apply->invoke($cal, $sessions, ['location_patterns' => ['/Paris/']]);

        $this->assertCount(2, $result);
    }

    public function testEmptyFilterReturnsAll(): void
    {
        $sessions = [
            ['uid' => '1', 'title' => 'A', 'location' => ''],
            ['uid' => '2', 'title' => 'B', 'location' => ''],
        ];

        $apply = new ReflectionMethod(Calendar::class, 'applyFilter');
        $apply->setAccessible(true);

        $cal    = $this->calendarFrom('BEGIN:VCALENDAR\r\nEND:VCALENDAR');
        $result = $apply->invoke($cal, $sessions, []);

        $this->assertCount(2, $result);
    }
}
