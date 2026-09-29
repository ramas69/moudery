import { Controller } from '@hotwired/stimulus';

/*
 * Étape Identité de l'assistant de création d'une ville.
 * - prévient avant de quitter la page si des modifications ne sont pas enregistrées ;
 * - signale quand une même adresse est saisie pour plusieurs rôles (le cumul est autorisé).
 */
export default class extends Controller {
    static targets = ['email', 'cumul'];
    static values = { confirmation: String };

    connect() {
        this.modifie = false;

        this.avantDechargement = (event) => {
            if (!this.modifie) {
                return;
            }
            event.preventDefault();
            event.returnValue = '';
        };

        this.avantVisite = (event) => {
            if (this.modifie && !window.confirm(this.confirmationValue)) {
                event.preventDefault();
            }
        };

        window.addEventListener('beforeunload', this.avantDechargement);
        document.addEventListener('turbo:before-visit', this.avantVisite);
        this.verifierCumul();
    }

    disconnect() {
        window.removeEventListener('beforeunload', this.avantDechargement);
        document.removeEventListener('turbo:before-visit', this.avantVisite);
    }

    marquerModifie() {
        this.modifie = true;
        this.verifierCumul();
    }

    marquerEnregistre() {
        this.modifie = false;
    }

    verifierCumul() {
        if (!this.hasCumulTarget) {
            return;
        }
        const adresses = this.emailTargets
            .map((champ) => champ.value.trim().toLowerCase())
            .filter((adresse) => adresse !== '');
        this.cumulTarget.hidden = new Set(adresses).size === adresses.length;
    }
}
