<?php
return [
    'association_name' => 'My Association',
    'db_dsn'           => 'sqlite:' . __DIR__ . '/data/attendance.db',
    'db_user'          => null,
    'db_password'      => null,
    'cache_path'       => __DIR__ . '/cache/agenda.ics.cache',
    'calendar_url'     => 'https://calendar.google.com/calendar/ical/CALENDAR_ID/public/basic.ics',

    // Optionnel — format du libellé des séances dans le sélecteur.
    // Variables : {date}, {date:PATTERN}, {title}, {location}
    // {date} utilise le format long localisé (ex : "23 juin 2026 à 19:00").
    // {date:PATTERN} accepte un pattern ICU (ex : {date:EEEE d MMMM yyyy HH:mm}).
    'session_label_format' => '{date} — {title}',

    // Optionnel — filtre les événements du calendrier (regex PHP).
    // Une séance est affichée si son titre correspond à au moins un pattern de titre
    // ET (si renseigné) si son lieu correspond à au moins un pattern de lieu.
    // Supprimer la clé ou laisser les listes vides pour tout afficher.
    'event_filter' => [
        'title_patterns'    => [],   // ex : ['/séance/i', '/réunion/i']
        'location_patterns' => [],   // ex : ['/salle A/i']
    ],
];
