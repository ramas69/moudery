# Mise en ligne sur o2switch

Hébergement mutualisé cPanel, serveur `tabebuia.o2switch.net`. À faire une fois, dans l'ordre.

## 1. cPanel (une fois)

1. **Sous-domaine** : cPanel › Domaines › créer `caisses.sansraccourcis.com` avec pour racine `caisses.sansraccourcis.com/public`
   (le dossier `public/` du projet, jamais la racine du projet).
2. **HTTPS** : cPanel › SSL/TLS Status › lancer AutoSSL sur le sous-domaine (Let's Encrypt). `public/.htaccess` force HTTPS.
3. **PHP** : cPanel › Sélectionner une version de PHP › 8.4 (ou 8.3). Extensions à cocher : intl, mbstring, xml, xmlreader,
   simplexml, zip, pdo_mysql, ctype, iconv, sodium, opcache. Options : `memory_limit` 256M, `upload_max_filesize` 12M,
   `post_max_size` 16M, `max_execution_time` 120.
4. **Base de données** : cPanel › Bases de données MySQL › créer la base `caisses` et l'utilisateur `caisses`
   (cPanel ajoute le préfixe du compte), lui donner tous les droits. Noter la version affichée (MySQL ou MariaDB) pour
   `serverVersion` dans `.env.local`.
5. **E-mail** : la boîte `moudery@sansraccourcis.com` existe ; vérifier SPF et DKIM dans cPanel › Délivrabilité des e-mails.
6. **SSH** : cPanel › Autorisation SSH › ajouter l'adresse IP de l'ordinateur qui déploie ; cPanel › Accès SSH › Gérer
   les clés SSH › Importer la clé publique `~/.ssh/id_ed25519_o2switch.pub` du Mac, puis l'autoriser. Le script de
   déploiement utilise cette clé (variable `O2_CLE` pour en choisir une autre).

## 2. Sur le serveur (une fois)

```bash
ssh COMPTE@tabebuia.o2switch.net
mkdir -p ~/caisses.sansraccourcis.com
nano ~/caisses.sansraccourcis.com/.env.local        # contenu : deploiement/env.local.exemple, avec les vraies valeurs
php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'   # pour APP_SECRET
```

## 3. Déployer (à chaque version)

Depuis le Mac, à la racine du projet :

```bash
O2_UTILISATEUR=COMPTE deploiement/deployer.sh
```

Le script vérifie les gabarits, envoie le code par rsync (sans `.env.local`, `var/`, `vendor/`, les tests ni les bases de
test), puis sur le serveur : `composer install --no-dev`, migrations, compilation des assets, cache.

## 4. Premiers comptes (une fois, en SSH)

```bash
cd ~/caisses.sansraccourcis.com
php bin/console app:utilisateur:creer ADRESSE Prénom Nom --role=super-admin --env=prod
php bin/console app:association:creer "Association de Moudery" moudery --bureau-central=ADRESSE_DU_BUREAU --env=prod
```

La seconde commande envoie l'invitation du bureau central par e-mail (lien de 7 jours).

## 5. Tâches planifiées (cPanel › Tâches Cron)

```
* * * * *  cd ~/caisses.sansraccourcis.com && php bin/console messenger:consume async --time-limit=55 --limit=100 --memory-limit=128M --env=prod >> var/log/messenger.log 2>&1
0 7 * * *  cd ~/caisses.sansraccourcis.com && php bin/console app:relances:envoyer --env=prod >> var/log/relances.log 2>&1
```

La première vide la file d'e-mails chaque minute (invitations, reçus, appels, relances) : o2switch ne laisse pas tourner
de processus permanent. La seconde envoie les relances automatiques chaque matin à 7 h (heure du serveur).

## 6. Vérifier

- `https://caisses.sansraccourcis.com/connexion` s'affiche en HTTPS, avec la police Manrope.
- Une invitation envoyée arrive en moins de deux minutes (sinon : `var/log/messenger.log`, puis `php bin/console messenger:failed:show --env=prod`).
- Les erreurs sont dans `~/caisses.sansraccourcis.com/var/log/prod-AAAA-MM-JJ.log`.
- Sauvegardes : JetBackup d'o2switch couvre les fichiers (dont `var/justificatifs`) et la base.
