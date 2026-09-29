#!/usr/bin/env bash
# Déploiement de Caisses sur o2switch par SSH et rsync (29 septembre 2026).
# Usage : O2_UTILISATEUR=moncompte deploiement/deployer.sh [--premiere-fois]
# Prérequis : accès SSH autorisé pour votre adresse IP (cPanel › Autorisation SSH), .env.local présent sur le serveur.
set -euo pipefail

HOTE="${O2_HOTE:-tabebuia.o2switch.net}"
UTILISATEUR="${O2_UTILISATEUR:?Indiquez le compte cPanel : O2_UTILISATEUR=moncompte}"
DOSSIER="${O2_DOSSIER:-caisses}"          # relatif au dossier personnel du compte
PHP="${O2_PHP:-php}"                        # binaire PHP 8.3 ou 8.4 sur le serveur
RACINE="$(cd "$(dirname "$0")/.." && pwd)"
CLE="${O2_CLE:-$HOME/.ssh/id_ed25519_o2switch}"   # clé créée le 29 septembre 2026, à importer dans cPanel › Accès SSH
SSH_OPTIONS="-p ${O2_PORT:-22} -i ${CLE} -o IdentitiesOnly=yes"
SSH="ssh ${SSH_OPTIONS} ${UTILISATEUR}@${HOTE}"

echo "▶ Vérifications locales"
cd "$RACINE"
php bin/console lint:twig templates >/dev/null
php bin/console lint:yaml config translations >/dev/null
php bin/console importmap:install >/dev/null

echo "▶ Envoi du code vers ${HOTE}:~/${DOSSIER}"
rsync -az --delete \
    --exclude '.env.local' --exclude '.env.local.php' --exclude '.env.*.local' \
    --exclude '/var/' --exclude '/vendor/' --exclude '/public/assets/' \
    --exclude '/tests/' --exclude '/.phpunit.cache/' --exclude '*.db' \
    --exclude '/docs/' --exclude '/deploiement/' --exclude '.DS_Store' --exclude '/.claude/' \
    -e "ssh ${SSH_OPTIONS}" \
    ./ "${UTILISATEUR}@${HOTE}:${DOSSIER}/"

echo "▶ Installation sur le serveur"
$SSH bash -s <<DISTANT
set -euo pipefail
cd ~/${DOSSIER}
test -f .env.local || { echo "Il manque ~/${DOSSIER}/.env.local (voir deploiement/env.local.exemple)"; exit 1; }
if command -v composer >/dev/null; then COMPOSER="composer"; else
    test -f composer.phar || curl -sS https://getcomposer.org/installer | ${PHP}
    COMPOSER="${PHP} composer.phar"
fi
APP_ENV=prod \$COMPOSER install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress
mkdir -p var/justificatifs var/import var/log
${PHP} bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=prod
${PHP} bin/console asset-map:compile --env=prod
${PHP} bin/console cache:clear --env=prod
${PHP} bin/console cache:warmup --env=prod
echo "Déploiement terminé."
DISTANT
