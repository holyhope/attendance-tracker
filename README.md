<img src="public/assets/icon.svg" alt="SPS" width="64" height="64">

# Système de Pointage Simple (SPS)

Une application web légère pour enregistrer les présences aux séances de votre
association ou de votre salle. Pas de compte à créer, pas d'application à
installer : les participants s'identifient avec un pseudonyme depuis n'importe
quel navigateur.

Conçue avec la vie privée en tête : pas de tracker, pas de CDN externe, pas de
nom réel requis. Toutes les données restent sur votre propre hébergement.

---

## Fonctionnalités

- **Pointage en un clic** — le participant choisit sa séance, saisit son pseudo
  et valide. Un ✅ confirme les séances déjà pointées.
- **Annulation** — possibilité d'annuler un pointage par erreur.
- **Mémorisation du pseudo** — option pour retrouver son pseudo au prochain
  passage.
- **Page d'administration** — liste des présences par séance, suppression
  d'entrées, compteurs.
- **Export** — CSV ou [Grist](https://www.getgrist.com/) pour analyser
  les données dans votre outil préféré.
- **Synchronisation avec Google Calendar** — les séances sont importées
  automatiquement depuis un agenda public.
- **Fonctionne sans JavaScript** — amélioration progressive : tout est
  utilisable avec JS désactivé.
- **Bilingue** — français et anglais selon la langue du navigateur.
- **Respectueux de la vie privée** — pas de tracker, pas de CDN externe,
  pseudonyme au lieu d'un nom réel, données hébergées chez vous.

## Prérequis

- PHP 8.2+ (extension `intl` recommandée pour les dates localisées)
- Hébergement web avec accès FTP — compatible OVH mutualisé et équivalents
- Un agenda Google Calendar public (ou tout flux iCal)

Pas de base de données serveur requise : les données sont stockées dans un
fichier SQLite.

## Installation

### 1. Cloner le dépôt

```bash
git clone https://github.com/holyhope/attendance-tracker.git
cd attendance-tracker
```

### 2. Configurer l'application

```bash
cp config.example.php config.php
```

Éditer `config.php` et renseigner au minimum :

| Clé | Description |
|-----|-------------|
| `association_name` | Nom affiché dans l'interface et les exports |
| `calendar_url` | URL iCal de l'agenda (Google Calendar, Nextcloud, etc.) |
| `db_dsn` | Chemin vers la base SQLite (ex. `sqlite:/var/www/data/attendance.db`) |

`config.example.php` documente toutes les options avancées : format des
libellés de séance (`session_label_format`), affichage du lieu (`show_location`
: `false`, `true`, `'only_link'`, `'with_map'`), filtre des événements
(`event_filter`) et durée de cache iCal (`cache_ttl`).

### 3. Initialiser la base de données

La base est **auto-migrée au premier démarrage** : les tables sont créées
automatiquement si elles n'existent pas. Il suffit de créer le dossier `data/`
accessible en écriture par PHP :

```bash
mkdir -p data cache
```

### 4. Déployer

Déposer les dossiers `public/`, `src/`, `lang/` et les fichiers `config.php`,
`.ovhconfig` à la racine de votre hébergement. Le dossier `public/` doit être
configuré comme racine web (ou son contenu copié dans `www/`).

Pour le déploiement automatisé via GitHub Actions, voir [DEPLOY.md](DEPLOY.md).

## Ajouter une langue

1. Créer `lang/{code}.php` en copiant `lang/fr.php` comme modèle.
2. Traduire toutes les valeurs (les clés restent en anglais).
3. Ajouter le code dans le tableau `$supportedLangs` de `public/index.php`
   et `public/admin/index.php` :
   ```php
   $supportedLangs = ['fr', 'en', 'de'];
   ```

La langue est détectée automatiquement depuis l'en-tête `Accept-Language` du
navigateur. Les tokens `{name}` dans les traductions sont compatibles PHP et JS.

## Protéger la page d'administration

L'accès à `/admin/` est protégé par HTTP Basic Auth via un fichier `.htpasswd`
placé **hors du webroot** (à la racine FTP, pas dans `public/`). Voir
[DEPLOY.md](DEPLOY.md) pour la procédure complète.

## Exporter les données

Depuis la page d'administration, le menu **Exporter** permet de télécharger :

- **CSV** — tableau simple, ouvrable dans Excel, LibreOffice Calc ou Google
  Sheets.
- **Grist** — document [Grist](https://www.getgrist.com/) avec tables liées
  (séances, participants, présences) et géocodage des lieux.

## Récupérer l'URL de votre agenda Google Calendar

1. Ouvrir [Google Calendar](https://calendar.google.com)
2. Paramètres de l'agenda → **Intégrer l'agenda**
3. Copier l'**adresse publique au format iCal**

## Licence

[MPL 2.0](LICENSE) avec conditions supplémentaires d'attribution UI :
tout déploiement doit afficher le copyright `© holyhope` et un lien visible
vers [github.com/sponsors/holyhope](https://github.com/sponsors/holyhope)
ou vers le [fichier de licence du dépôt original](LICENSE).
