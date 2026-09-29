import { Controller } from '@hotwired/stimulus';

/*
 * Jauge de robustesse du mot de passe (maquette « Activation de compte ») : quatre segments et un mot, pendant la
 * saisie. Indicative seulement : le serveur reste juge (12 caractères au moins, robustesse moyenne exigée).
 */
export default class extends Controller {
    static targets = ['champ', 'segment', 'texte'];
    static values = { textes: Object };

    connect() {
        this.evaluer();
    }

    evaluer() {
        const valeur = this.champTarget.value;
        let niveau = 0;
        let cle = 'vide';
        if (valeur.length > 0 && valeur.length < 12) {
            niveau = 1;
            cle = 'court';
        } else if (valeur.length >= 12) {
            const familles = [/[a-z]/, /[A-Z]/, /\d/, /[^a-zA-Z\d]/].filter((motif) => motif.test(valeur)).length;
            const points = familles + (valeur.length >= 16 ? 1 : 0);
            niveau = points >= 4 ? 4 : (points >= 3 ? 3 : 2);
            cle = { 2: 'faible', 3: 'correct', 4: 'robuste' }[niveau];
        }
        this.element.dataset.robustesse = cle;
        this.segmentTargets.forEach((segment, index) => segment.classList.toggle('robustesse__segment--plein', index < niveau));
        this.texteTarget.textContent = this.textesValue[cle] ?? '';
    }
}
