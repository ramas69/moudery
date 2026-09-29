import { Controller } from '@hotwired/stimulus';

/* La case d'en-tête coche ou décoche toutes les cases actives du formulaire ciblé (impayés). */
export default class extends Controller {
    static values = { cible: String };

    basculer() {
        const formulaire = document.getElementById(this.cibleValue);
        if (!formulaire) {
            return;
        }
        formulaire.querySelectorAll('tbody input[type="checkbox"]:not([disabled])').forEach((case_) => {
            case_.checked = this.element.checked;
        });
    }
}
