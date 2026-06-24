# Déploiement

## Prérequis

### Environnement GitHub `preprod`

Les secrets FTP sont stockés dans l'environnement GitHub `preprod` :

> GitHub → Settings → Environments → preprod → Environment secrets

| Type | Nom | Valeur |
|------|-----|--------|
| Variable | `FTP_HOST` | Hôte FTP OVH (ex. `ftp.cluster129.hosting.ovh.net`) |
| Variable | `FTP_PORT` | Port FTP (ex. `21`) |
| Variable | `FTP_USER` | Identifiant FTP OVH |
| Secret | `FTP_PASSWORD` | Mot de passe FTP OVH |

### Fichiers gérés manuellement sur le serveur

Ces fichiers ne sont **jamais** déployés automatiquement et doivent être gérés
directement sur le serveur (via FTP ou panneau OVH) :

| Fichier | Rôle |
|---------|------|
| `config.php` | Configuration (URL calendrier, token admin, DSN base de données) |
| `data/attendance.db` | Base SQLite (persistance des pointages) |
| `cache/` | Cache du flux iCal (peuplé au runtime) |

## Pipeline de déploiement

```
git push origin vX.Y.Z
        │
        ▼
 [release.yml]
 • npm ci + build:css
 • Prépare dist/ (public/ → www/, src/, .ovhconfig)
 • Crée une GitHub Release avec dist.zip en pièce jointe
        │
        ▼ (release publiée)
 [deploy-preprod.yml]
 • Télécharge dist.zip depuis la release
 • Synchronise dist/ → racine FTP OVH via FTP
```

## Publier une nouvelle version

```bash
git tag v1.2.3
git push origin v1.2.3
```

Le déploiement se déclenche automatiquement à la publication de la release.
L'avancement est visible dans l'onglet **Actions** du dépôt GitHub.

## Premier déploiement (initialisation serveur)

Le pipeline ne déploie pas `config.php` ni la base de données. Lors d'une
installation sur un nouveau serveur, effectuer ces étapes manuellement :

1. Copier `config.example.php` en `config.php` et renseigner les valeurs
2. Créer le dossier `data/` avec un `attendance.db` vierge :
   ```bash
   sqlite3 attendance.db "
     CREATE TABLE attendees (id TEXT PRIMARY KEY, nickname TEXT NOT NULL UNIQUE);
     CREATE TABLE checkins (id TEXT PRIMARY KEY, session_uid TEXT NOT NULL,
       attendee_id TEXT NOT NULL REFERENCES attendees(id), created_at TIMESTAMP NOT NULL);
     CREATE UNIQUE INDEX uq_checkin ON checkins (session_uid, attendee_id);
   "
   ```
3. Créer le dossier `cache/`
4. Configurer l'accès HTTP Basic Auth pour `/www/admin/` (fichier `.htpasswd`)
5. Pousser un tag pour déclencher le premier déploiement automatique
