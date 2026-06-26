# Déploiement

## Prérequis

### Environnement GitHub `preprod`

Toutes les valeurs sont configurées dans l'environnement GitHub `preprod` :

> GitHub → Settings → Environments → preprod

| Type | Nom | Description |
|------|-----|-------------|
| Variable | `FTP_HOST` | Hôte FTP OVH (ex. `ftp.cluster129.hosting.ovh.net`) |
| Variable | `FTP_PORT` | Port FTP (ex. `21`) |
| Variable | `FTP_USER` | Identifiant FTP OVH |
| Secret | `FTP_PASSWORD` | Mot de passe FTP OVH |
| Variable | `ASSOCIATION_NAME` | Nom de l'association affiché dans l'interface |
| Secret | `CALENDAR_URL` | URL iCal de l'agenda (contient l'ID de calendrier) |

### Fichiers gérés manuellement sur le serveur

Ces fichiers ne sont **jamais** déployés automatiquement et doivent être gérés
directement sur le serveur (via FTP ou panneau OVH) :

| Fichier | Rôle |
|---------|------|
| `data/attendance.db` | Base SQLite (persistance des pointages) |
| `cache/` | Cache du flux iCal (peuplé au runtime) |

`config.php` est généré automatiquement à chaque déploiement à partir des
variables et secrets GitHub (voir pipeline ci-dessous).

## Pipeline de déploiement

```
git push origin vX.Y.Z   ← tag annoté requis (git tag -a vX.Y.Z -m "vX.Y.Z")
        │
        ▼
 [release.yml — job release]
 • npm ci + lint (ESLint)
 • composer install + phpstan (analyse statique PHP)
 • npm run build (Bootstrap, Leaflet, JS)
 • Prépare dist/ : public/ → www/, src/, lang/, .ovhconfig
 • Crée une GitHub Release avec dist.zip en pièce jointe
        │
        ▼ (release publiée)
 [release.yml — job deploy-preprod]
 • Télécharge dist.zip depuis la release
 • Génère dist/config.php inline (ASSOCIATION_NAME, CALENDAR_URL)
 • Injecte le chemin .htpasswd dans dist/www/admin/.htaccess
   et dist/www/api/admin/.htaccess (remplace %%HTPASSWD_PATH%%)
 • Synchronise dist/ → racine FTP OVH via FTP
```

## Publier une nouvelle version

```bash
git tag -a v1.2.3 -m "v1.2.3"
git push origin v1.2.3
```

Le déploiement se déclenche automatiquement à la publication de la release.
L'avancement est visible dans l'onglet **Actions** du dépôt GitHub.

## Premier déploiement (initialisation serveur)

Le pipeline génère `config.php` automatiquement mais ne crée pas la base de
données ni le fichier `.htpasswd`. Lors d'une installation sur un nouveau
serveur :

1. Configurer les variables et secrets GitHub dans l'environnement `preprod`
   (voir tableau ci-dessus).
2. Créer les dossiers `data/` et `cache/` sur le serveur (via FTP ou panneau
   OVH) et leur donner les permissions `705` :
   ```bash
   source .env
   curl -s --ftp-create-dirs "ftp://$FTP_USER:$FTP_PASSWORD@$FTP_HOST:$FTP_PORT/data/"
   curl -s --ftp-create-dirs "ftp://$FTP_USER:$FTP_PASSWORD@$FTP_HOST:$FTP_PORT/cache/"
   ```
   > **OVHcloud** : les permissions `705` sont requises pour que PHP puisse
   > écrire dans ces dossiers. Les régler via le gestionnaire de fichiers OVH
   > ou `chmod 705 data/ cache/` si SSH est disponible.
   >
   > SQLite crée également des fichiers temporaires (`-wal`, `-shm`) dans
   > `data/` — le dossier doit rester accessible en écriture en permanence.
   >
   > La base SQLite est initialisée automatiquement au premier démarrage.
3. Créer le fichier `.htpasswd` pour protéger l'interface d'administration :
   ```bash
   # Créer le fichier avec un premier utilisateur
   htpasswd -c /tmp/.htpasswd identifiant
   # Ajouter d'autres utilisateurs si besoin (sans -c)
   htpasswd /tmp/.htpasswd autre_identifiant
   ```
   Puis l'uploader à la racine FTP (hors webroot) :
   ```bash
   source .env
   curl --upload-file /tmp/.htpasswd "${FTP_PREPROD%/}/.htpasswd"
   ```
4. Pousser un tag annoté pour déclencher le premier déploiement automatique :
   ```bash
   git tag -a v0.1.0 -m "v0.1.0"
   git push origin v0.1.0
   ```
