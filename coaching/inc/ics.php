<?php
/*
 * Generation de fichiers calendrier (.ics) : compatibles iPhone, Android/Google Agenda, Outlook.
 */

function ics_text(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $s);
}

function ics_utc(string $local): string
{
    return gmdate('Ymd\THis\Z', strtotime($local));
}

function ics_event(array $b, string $summary, string $start, string $end, string $description = ''): string
{
    $loc = $b['address'] !== '' ? $b['address'] : $b['location_name'];
    $lines = [
        'BEGIN:VEVENT',
        'UID:' . $b['token'] . '@coaching',
        'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        'DTSTART:' . ics_utc($start),
        'DTEND:' . ics_utc($end),
        'SUMMARY:' . ics_text($summary),
        'LOCATION:' . ics_text($loc),
        'STATUS:' . ($b['status'] === 'confirmed' ? 'CONFIRMED' : 'TENTATIVE'),
    ];
    if ($description !== '') {
        $lines[] = 'DESCRIPTION:' . ics_text($description);
    }
    $lines[] = 'END:VEVENT';
    return implode("\r\n", $lines);
}

function ics_calendar(array $events, string $name = ''): string
{
    $head = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Coaching//RDV//FR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];
    if ($name !== '') {
        $head[] = 'X-WR-CALNAME:' . ics_text($name);
    }
    return implode("\r\n", array_merge($head, $events, ['END:VCALENDAR'])) . "\r\n";
}
