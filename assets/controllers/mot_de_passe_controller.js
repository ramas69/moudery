import { Controller } from '@hotwired/stimulus';

/* Affiche ou masque un mot de passe pendant la saisie. */
export default class extends Controller {
    static targets = ['champ', 'bouton'];
    static values = { afficher: String, masquer: String };

    basculer() {
        const visible = 'text' === this.champTarget.type;
        this.champTarget.type = visible ? 'password' : 'text';
        this.boutonTarget.textContent = visible ? this.afficherValue : this.masquerValue;
        this.boutonTarget.setAttribute('aria-pressed', String(!visible));
        this.champTarget.focus();
    }
}
