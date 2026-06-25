<img src="public/assets/icon.svg" alt="SPS" width="64" height="64">

# Système de Pointage Simple (SPS)

Une application web légère pour enregistrer les présences aux séances de votre
association ou de votre salle. Pas de compte à créer, pas d'application à
installer : les participants s'identifient avec un pseudonyme depuis n'importe
quel navigateur.

---

## Fonctionnalités

- **Pointage en un clic** — le participant choisit sa séance, saisit son pseudo
  et valide. Un ✅ confirme les séances déjà pointées.
- **Annulation** — possibilité d'annuler un pointage par erreur.
- **Mémorisation du pseudo** — option pour retrouver son pseudo au prochain
  passage.
- **Page d'administration** — liste des présences par séance, suppression
  d'entrées, compteurs.
- **Export** — CSV, JSON ou [Grist](https://www.getgrist.com/) pour analyser
  les données dans votre outil préféré.
- **Synchronisation avec Google Calendar** — les séances sont importées
  automatiquement depuis un agenda public.
- **Fonctionne sans JavaScript** — amélioration progressive : tout est
  utilisable avec JS désactivé.
- **Bilingue** — français et anglais selon la langue du navigateur.

## Prérequis

- PHP 8.2+ (extension `intl` recommandée pour les dates localisées)
- Hébergement web avec accès FTP — compatible OVH mutualisé et équivalents
- Un agenda Google Calendar public (ou tout flux iCal)

Pas de base de données serveur requise : les données sont stockées dans un
fichier SQLite.

## Installation

### 1. Cloner le dépôt

```bash
git clone https://github.com/Jou-Retz-Vous/attendance-tracker.git
cd attendance-tracker
```

### 2. Configurer l'application

```bash
cp config.example.php config.php
```

Éditer `config.php` et renseigner :

| Clé | Description |
|-----|-------------|
| `association_name` | Nom affiché dans l'interface |
| `calendar_url` | URL iCal de l'agenda Google Calendar |
| `db_dsn` | Chemin vers la base SQLite (ex. `sqlite:/var/www/data/attendance.db`) |

Voir `config.example.php` pour toutes les options (format des libellés, filtre
des événements…).

### 3. Initialiser la base de données

```bash
sqlite3 data/attendance.db "
  CREATE TABLE attendees (id TEXT PRIMARY KEY, nickname TEXT NOT NULL UNIQUE);
  CREATE TABLE checkins (
    id TEXT PRIMARY KEY, session_uid TEXT NOT NULL,
    attendee_id TEXT NOT NULL REFERENCES attendees(id),
    created_at TIMESTAMP NOT NULL
  );
  CREATE UNIQUE INDEX uq_checkin ON checkins (session_uid, attendee_id);
"
```

### 4. Déployer

Déposer les dossiers `public/`, `src/` et les fichiers `config.php`,
`.ovhconfig` à la racine de votre hébergement. Le dossier `public/` doit être
configuré comme racine web (ou son contenu copié dans `www/`).

Pour le déploiement automatisé via GitHub Actions, voir [DEPLOY.md](DEPLOY.md).

## Protéger la page d'administration

L'accès à `/admin/` est protégé par HTTP Basic Auth. Créer un fichier
`.htpasswd` :

```bash
htpasswd -c public/admin/.htpasswd votre_identifiant
```

## Récupérer l'URL de votre agenda Google Calendar

1. Ouvrir [Google Calendar](https://calendar.google.com)
2. Paramètres de l'agenda → **Intégrer l'agenda**
3. Copier l'**adresse publique au format iCal**

## Licence

MIT
