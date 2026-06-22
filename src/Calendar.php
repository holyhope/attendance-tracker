<?php
declare(strict_types=1);

class Calendar
{
    public function __construct(
        private string $url,
        private string $cachePath,
        private int    $cacheTtl = 900
    ) {}

    public function getSessions(): array
    {
        $ics    = $this->fetchIcs();
        $events = $this->parseIcs($ics);
        $now    = new DateTimeImmutable();
        $past   = $now->modify('-30 days');
        $future = $now->modify('+90 days');

        $sessions = [];
        foreach ($events as $e) {
            foreach ($this->expand($e, $past, $future) as $occurrence) {
                $sessions[] = [
                    'uid'        => $occurrence['uid'],
                    'title'      => $occurrence['title'],
                    'start'      => $occurrence['start']->format('c'),
                    'end'        => $occurrence['end']->format('c'),
                    'is_current' => $now >= $occurrence['start'] && $now <= $occurrence['end'],
                ];
            }
        }

        usort($sessions, fn($a, $b) => strcmp($b['start'], $a['start']));

        return array_values($sessions);
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
                return [$e];
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
                'uid'   => $e['uid'] . '_' . $cursor->format('Ymd'),
                'title' => $e['title'],
                'start' => $cursor,
                'end'   => $cursor->add($duration),
            ];
            $cursor = $cursor->add(new DateInterval($step));
            $n++;
        }

        return $occurrences;
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
                'UID'     => $current['uid']   = trim(substr($line, $colonPos + 1)),
                'SUMMARY' => $current['title'] = trim(substr($line, $colonPos + 1)),
                'DTSTART' => $current['start'] = $this->parseDate($line),
                'DTEND'   => $current['end']   = $this->parseDate($line),
                'RRULE'   => $current['rrule'] = $this->parseRrule(trim(substr($line, $colonPos + 1))),
                default   => null,
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
