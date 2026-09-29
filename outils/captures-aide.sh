#!/usr/bin/env bash
# Captures d'écran du guide utilisateur (page « Aide »), rejouables quand l'interface change.
# Prérequis : `symfony serve` sur http://localhost:8000, la ville fictive « Montreuil » (voir CLAUDE.md, « Guide
# utilisateur ») et Google Chrome. Chaque page est prise connectée avec le compte du rôle concerné, puis rendue par
# Chrome sans interface (feuilles de style depuis le serveur via <base href>, animations d'entrée terminées grâce au
# budget de temps virtuel). Les images vont dans assets/images/aide/.
set -euo pipefail
cd "$(dirname "$0")/.."
SERVEUR="${SERVEUR:-http://localhost:8000}"
CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
SORTIE="assets/images/aide"
TRAVAIL="$(mktemp -d)"
MOT_DE_PASSE="${MOT_DE_PASSE:-caisses-moudery}"
LARGEUR="${LARGEUR:-1280}"
HAUTEUR="${HAUTEUR:-800}"

connecter() { # compte -> pot de cookies
    local compte="$1" pot="$TRAVAIL/$1.cookies"
    curl -s -c "$pot" -b "$pot" "$SERVEUR/connexion" -o "$TRAVAIL/connexion.html"
    local jeton
    jeton="$(grep -o 'name="_csrf_token" value="[^"]*"' "$TRAVAIL/connexion.html" | head -1 | sed 's/.*value="//; s/"$//')"
    curl -s -c "$pot" -b "$pot" -e "$SERVEUR/connexion" -H "Origin: $SERVEUR" \
        --data-urlencode "email=$compte" --data-urlencode "mot_de_passe=$MOT_DE_PASSE" --data-urlencode "_csrf_token=$jeton" \
        "$SERVEUR/connexion" -o /dev/null
    echo "$pot"
}

capturer() { # pot chemin nom [hauteur]
    local pot="$1" chemin="$2" nom="$3" hauteur="${4:-$HAUTEUR}" page="$TRAVAIL/$3.html"
    curl -s -b "$pot" "$SERVEUR$chemin" -o "$page"
    # Feuilles de style et images depuis le serveur ; la barre de débogage de Symfony est masquée (sans JavaScript, elle
    # resterait affichée « Loading… » au bas de la capture).
    perl -0pi -e "s#<head>#<head><base href=\"$SERVEUR/\"><style>.sf-toolbar,.sf-toolbarreset,.sf-minitoolbar{display:none!important}</style>#" "$page"
    # Chrome écrit la capture en quelques secondes puis ne rend pas la main : l'alarme le coupe.
    perl -e 'alarm 30; exec @ARGV' -- "$CHROME" --headless=new --disable-gpu --hide-scrollbars --no-first-run \
        --user-data-dir="$TRAVAIL/profil" --window-size="$LARGEUR,$hauteur" --virtual-time-budget=4000 --timeout=15000 \
        --screenshot="$SORTIE/$nom.png" "file://$page" >/dev/null 2>&1 || true
    [ -s "$SORTIE/$nom.png" ] && echo "✓ $nom" || echo "✗ $nom (échec)"
}

CENTRAL="$(connecter central@moudery.fr)"
TRESORIERE="$(connecter tresoriere.montreuil@example.org)"
PRESIDENT="$(connecter president.montreuil@example.org)"
MEMBRE="$(connecter moussa.kebe@example.org)"

# Bureau central : périmètre « toute l'association », puis Montreuil.
capturer "$CENTRAL" "/associations/moudery?ville=association" central-tableau-de-bord 900
capturer "$CENTRAL" "/associations/moudery/villes" central-villes
capturer "$CENTRAL" "/associations/moudery/villes/nouvelle" central-ville-nouvelle
capturer "$CENTRAL" "/associations/moudery/membres?ville=2" central-membres
capturer "$CENTRAL" "/associations/moudery/cotisations?ville=2" central-cotisations
capturer "$CENTRAL" "/associations/moudery/appels" central-appels
capturer "$CENTRAL" "/associations/moudery/appels/nouveau" central-appel-nouveau 1000
capturer "$CENTRAL" "/associations/moudery/depenses?ville=association" central-depenses
capturer "$CENTRAL" "/associations/moudery/reversements?ville=association" central-reversements
capturer "$CENTRAL" "/associations/moudery/rapport" central-rapport 1000
capturer "$CENTRAL" "/associations/moudery/parametres/responsables" central-responsables
capturer "$CENTRAL" "/associations/moudery/parametres" central-parametres

# Responsables de la ville de Montreuil.
capturer "$TRESORIERE" "/associations/moudery" ville-accueil 900
capturer "$TRESORIERE" "/associations/moudery/membres" ville-membres
capturer "$TRESORIERE" "/associations/moudery/paiements/nouveau" ville-paiement-groupe 1000
capturer "$TRESORIERE" "/associations/moudery/impayes" ville-impayes 1000
capturer "$TRESORIERE" "/associations/moudery/depenses/nouvelle" ville-depense-nouvelle 1000
capturer "$PRESIDENT" "/associations/moudery/depenses" president-depenses
capturer "$TRESORIERE" "/associations/moudery/reversements" ville-reversements

# Membre.
capturer "$MEMBRE" "/mon-espace" membre-espace 900
capturer "$MEMBRE" "/mon-espace/ma-ville" membre-ville 900

rm -rf "$TRAVAIL"
echo "Captures dans $SORTIE"
