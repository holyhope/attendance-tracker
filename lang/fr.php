<?php
// Traductions françaises de l'interface de pointage.
//
// Format choisi : tableaux PHP plutôt que gettext (.po/.mo) pour les raisons suivantes :
//   - Aucune dépendance : pas d'extension PHP `gettext`, pas de `setlocale`, pas de
//     compilation .mo à chaque modification.
//   - Compatible OVH mutualisé et tout hébergeur PHP sans configuration système.
//   - Pour ce volume (~20 chaînes, 2 langues), la complexité de gettext est
//     disproportionnée. Si le projet grandit (3+ langues, traducteurs externes),
//     envisager gettext/gettext via Composer qui parse les .po en pur PHP.
//
// Les chaînes avec interpolation utilisent le marqueur {name}, remplacé côté PHP
// par str_replace('{name}', $value, $t['clé']) et côté JS par
// t.clé.replace('{name}', value). Ce format commun permet de sérialiser $t en JSON
// et de le passer au JS via data-i18n sans dupliquer les traductions.
//
// Ajouter une langue : créer lang/{code}.php avec les mêmes clés,
// puis déclarer le code dans la liste des langues supportées (public/index.php).

return [
    // ── Page de pointage ─────────────────────────────────────────────────────
    'title'          => 'Pointage {name}',
    'session_label'  => 'Séance',
    'nickname_label' => 'Pseudonyme',
    'nickname_ph'    => 'Pseudo',
    'remember'       => 'Mémoriser mon pseudonyme',
    'btn_checkin'    => 'Pointer la présence',
    'btn_cancel'     => 'Annuler le pointage',
    'checked_in'     => 'Présence enregistrée pour {name}.',
    'cancelled'      => 'Pointage annulé pour {name}.',
    'fill_nickname'  => 'Entrez un pseudonyme.',
    'already'        => 'Déjà pointé pour cette séance.',
    'not_checked_in' => 'Aucun pointage trouvé pour cette séance.',
    'err_generic'    => 'Une erreur est survenue.',
    'admin_link'     => 'Administration',
    'map_notice'     => 'En cliquant, des données de localisation seront chargées depuis openstreetmap.org.',

    // ── Page d'administration ────────────────────────────────────────────────
    'back_home'      => '← Accueil',
    'admin_title'    => 'Administration',
    'export'         => 'Exporter',
    'view'           => 'Voir',
    'nickname_col'   => 'Pseudonyme',
    'date_col'       => 'Date',
    'delete'         => 'Supprimer',
    'deleted'        => 'Entrée supprimée.',
    'delete_confirm'       => 'Supprimer cette entrée ?',
    'checkin_admin_label'  => 'Pointer un membre',
    'nav_menu_label'       => 'Menu de navigation',
    'lang_switcher_label'  => 'Langue',
    'back_home_label'      => 'Retour à l\'accueil',
    'actions_col'          => 'Actions',
    'confirm_delete'       => 'Confirmer ?',
    'cancel_action'        => 'Annuler',
    'map_label'            => 'Carte du lieu',
];
