<?php
declare(strict_types=1);

class Calendar
{
    public function __construct(
        private string $url,
        private string $cachePath,
        private int    $cacheTtl    = 900,
        private array  $filter      = [],
        private string $labelFormat = '{date} — {title}',
    ) {}

    public function getSessions(string $lang = 'fr'): array
    {
        $ics       = $this->fetchIcs();
        $events    = $this->parseIcs($ics);
        $now       = new DateTimeImmutable();
        $past      = $now->modify('-30 days');
        $endOfDay  = $now->setTime(23, 59, 59);

        $sessions = [];
        foreach ($events as $e) {
            foreach ($this->expand($e, $past, $endOfDay) as $occurrence) {
                $sessions[] = [
                    'uid'        => $occurrence['uid'],
                    'title'      => $occurrence['title'],
                    'location'   => $occurrence['location'] ?? '',
                    'start'      => $occurrence['start']->format('c'),
                    'end'        => $occurrence['end']->format('c'),
                    'is_current' => $now >= $occurrence['start'] && $now <= $occurrence['end'],
                    'label'      => $this->formatLabel($occurrence['start'], $occurrence['title'], $occurrence['location'] ?? '', $lang),
                ];
            }
        }

        $sessions = $this->applyFilter($sessions, $this->filter);

        usort($sessions, fn($a, $b) => strcmp($b['start'], $a['start']));

        return array_values(array_slice($sessions, 0, 10));
    }

    /**
     * Expands a parsed event into occurrences within [from, until].
     * Handles RRULE FREQ=WEEKLY and FREQ=MONTHLY only.
     * Non-recurring events return a single-element array (or empty if out of range).
     */
    private function expand(array $e, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $duration = $e['start']->diff($e['end']);

        if (!isset($e['rrule'])) {
            if ($e['start'] >= $from && $e['start'] <= $until) {
                return [['uid' => $e['uid'], 'title' => $e['title'], 'location' => $e['location'] ?? '', 'start' => $e['start'], 'end' => $e['end']]];
            }
            return [];
        }

        $rrule  = $e['rrule'];
        $freq   = $rrule['FREQ'] ?? '';
        $rrUntil = isset($rrule['UNTIL']) ? $this->parseDate('DTSTART:' . $rrule['UNTIL']) : null;
        $count  = isset($rrule['COUNT']) ? (int) $rrule['COUNT'] : PHP_INT_MAX;
        $interval = (int) ($rrule['INTERVAL'] ?? 1);

        $step = match ($freq) {
            'WEEKLY'  => "P{$interval}W",
            'MONTHLY' => "P{$interval}M",
            default   => null,
        };

        if ($step === null) {
            return [];
        }

        // Advance DTSTART to the first occurrence on or after $from
        $cursor = $e['start'];
        $n = 0;
        while ($cursor < $from) {
            $cursor = $cursor->add(new DateInterval($step));
            $n++;
        }

        $occurrences = [];
        while ($cursor <= $until && $n < $count) {
            if ($rrUntil !== null && $cursor > $rrUntil) break;
            $occurrences[] = [
                'uid'      => $e['uid'] . '_' . $cursor->format('Ymd'),
                'title'    => $e['title'],
                'location' => $e['location'] ?? '',
                'start'    => $cursor,
                'end'      => $cursor->add($duration),
            ];
            $cursor = $cursor->add(new DateInterval($step));
            $n++;
        }

        return $occurrences;
    }

    private function formatLabel(DateTimeImmutable $start, string $title, string $location, string $lang): string
    {
        $format = $this->labelFormat;

        // {date:PATTERN} — custom ICU pattern
        $format = preg_replace_callback('/\{date:([^}]+)\}/', function ($m) use ($start, $lang) {
            if (extension_loaded('intl')) {
                return IntlDateFormatter::formatObject($start, $m[1], $lang) ?: $start->format('d/m/Y H:i');
            }
            return $start->format('d/m/Y H:i');
        }, $format);

        // {date} — default locale format (date + time)
        if (str_contains($format, '{date}')) {
            if (extension_loaded('intl')) {
                $date = (new IntlDateFormatter($lang, IntlDateFormatter::LONG, IntlDateFormatter::SHORT))->format($start);
            } else {
                $date = $start->format('d/m/Y H:i');
            }
            $format = str_replace('{date}', $date, $format);
        }

        return str_replace(
            ['{title}', '{location}'],
            [$title,    $location],
            $format
        );
    }

    private function applyFilter(array $sessions, array $filter): array
    {
        $titlePatterns    = $filter['title_patterns']    ?? [];
        $locationPatterns = $filter['location_patterns'] ?? [];

        if (!$titlePatterns && !$locationPatterns) {
            return $sessions;
        }

        return array_values(array_filter($sessions, function ($s) use ($titlePatterns, $locationPatterns) {
            if ($titlePatterns && !$this->matchesAny($s['title'], $titlePatterns)) {
                return false;
            }
            if ($locationPatterns && !$this->matchesAny($s['location'] ?? '', $locationPatterns)) {
                return false;
            }
            return true;
        }));
    }

    private function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, $value)) return true;
        }
        return false;
    }

    private function fetchIcs(): string
    {
        $cacheValid = file_exists($this->cachePath)
            && time() - filemtime($this->cachePath) < $this->cacheTtl;

        if ($cacheValid) {
            return file_get_contents($this->cachePath);
        }

        $ics = @file_get_contents($this->url);

        if ($ics !== false) {
            file_put_contents($this->cachePath, $ics);
            return $ics;
        }

        if (file_exists($this->cachePath)) {
            return file_get_contents($this->cachePath);
        }

        throw new RuntimeException('Calendar unavailable and no cache found.');
    }

    private function parseIcs(string $ics): array
    {
        // Unfold multi-line values (RFC 5545 §3.1)
        $ics   = preg_replace('/\r?\n[ \t]/', '', $ics);
        $lines = preg_split('/\r?\n/', $ics);

        $events  = [];
        $current = null;

        foreach ($lines as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $current = [];
                continue;
            }
            if ($line === 'END:VEVENT') {
                if (isset($current['uid'], $current['title'], $current['start'], $current['end'])) {
                    $events[] = $current;
                }
                $current = null;
                continue;
            }
            if ($current === null) {
                continue;
            }

            $colonPos = strpos($line, ':');
            if ($colonPos === false) continue;

            $rawKey = strtoupper(substr($line, 0, $colonPos));
            $key    = explode(';', $rawKey)[0];

            match ($key) {
                'UID'      => $current['uid']      = trim(substr($line, $colonPos + 1)),
                'SUMMARY'  => $current['title']    = trim(substr($line, $colonPos + 1)),
                'LOCATION' => $current['location'] = trim(substr($line, $colonPos + 1)),
                'DTSTART'  => $current['start']    = $this->parseDate($line),
                'DTEND'    => $current['end']       = $this->parseDate($line),
                'RRULE'    => $current['rrule']    = $this->parseRrule(trim(substr($line, $colonPos + 1))),
                default    => null,
            };
        }

        return $events;
    }

    private function parseRrule(string $value): array
    {
        $parts = [];
        foreach (explode(';', $value) as $part) {
            [$k, $v]    = array_pad(explode('=', $part, 2), 2, '');
            $parts[$k]  = $v;
        }
        return $parts;
    }

    private function parseDate(string $line): DateTimeImmutable
    {
        $colonPos = strrpos($line, ':');
        $params   = substr($line, 0, $colonPos);
        $value    = trim(substr($line, $colonPos + 1));

        $tz = new DateTimeZone('UTC');
        if (preg_match('/TZID=([^;:]+)/', $params, $m)) {
            try {
                $tz = new DateTimeZone($m[1]);
            } catch (\Exception) {}
        }

        if (strlen($value) === 8) {
            return DateTimeImmutable::createFromFormat('Ymd', $value, $tz)->setTime(0, 0);
        }

        if (str_ends_with($value, 'Z')) {
            return DateTimeImmutable::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
        }

        return DateTimeImmutable::createFromFormat('Ymd\THis', $value, $tz);
    }
}
