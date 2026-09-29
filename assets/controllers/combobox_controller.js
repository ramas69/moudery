import { Controller } from '@hotwired/stimulus';

/*
 * Sélecteur avec recherche : un champ texte qui filtre une liste d'options rendue par le serveur.
 * Choisir une option remplit le champ et envoie le formulaire. Sans JavaScript, le champ reste un texte libre
 * que le serveur sait interpréter (identifiant, nom exact ou début de nom).
 */
export default class extends Controller {
    static targets = ['saisie', 'liste', 'option', 'vide'];

    connect() {
        this.indice = -1;
        this.fermer();
    }

    ouvrir() {
        this.listeTarget.hidden = false;
        this.saisieTarget.setAttribute('aria-expanded', 'true');
        this.appliquerFiltre();
    }

    fermer() {
        this.listeTarget.hidden = true;
        this.saisieTarget.setAttribute('aria-expanded', 'false');
        this.saisieTarget.removeAttribute('aria-activedescendant');
        this.indice = -1;
    }

    /* Le clic sur une option arrive après le blur : on laisse le temps au clic de partir. */
    fermerBientot() {
        window.setTimeout(() => this.fermer(), 150);
    }

    filtrer() {
        if (this.listeTarget.hidden) {
            this.ouvrir();

            return;
        }
        this.appliquerFiltre();
    }

    /* Montre les options qui contiennent la saisie, sans accents ni casse ; le message « aucune » sinon. */
    appliquerFiltre() {
        const aiguille = this.normaliser(this.saisieTarget.value);
        let visibles = 0;
        this.optionTargets.forEach((option) => {
            const correspond = aiguille === '' || this.normaliser(option.dataset.libelle).includes(aiguille);
            option.hidden = !correspond;
            option.setAttribute('aria-selected', 'false');
            if (correspond) {
                visibles += 1;
            }
        });
        this.videTarget.hidden = visibles > 0;
        this.indice = -1;
    }

    clavier(evenement) {
        const visibles = this.optionTargets.filter((option) => !option.hidden);
        switch (evenement.key) {
            case 'ArrowDown':
                evenement.preventDefault();
                this.surligner(visibles, Math.min(this.indice + 1, visibles.length - 1));
                break;
            case 'ArrowUp':
                evenement.preventDefault();
                this.surligner(visibles, Math.max(this.indice - 1, 0));
                break;
            case 'Enter':
                if (this.indice >= 0 && visibles[this.indice]) {
                    evenement.preventDefault();
                    this.choisir(visibles[this.indice]);
                } else if (visibles.length === 1) {
                    evenement.preventDefault();
                    this.choisir(visibles[0]);
                }
                break;
            case 'Escape':
                this.fermer();
                break;
            default:
                break;
        }
    }

    selectionner(evenement) {
        this.choisir(evenement.currentTarget);
    }

    effacer() {
        this.saisieTarget.value = '';
        this.envoyer();
    }

    surligner(visibles, indice) {
        if (this.listeTarget.hidden) {
            this.ouvrir();
        }
        this.indice = indice;
        visibles.forEach((option, i) => option.setAttribute('aria-selected', i === indice ? 'true' : 'false'));
        const active = visibles[indice];
        if (active) {
            this.saisieTarget.setAttribute('aria-activedescendant', active.id);
            active.scrollIntoView({ block: 'nearest' });
        }
    }

    choisir(option) {
        this.saisieTarget.value = option.dataset.valeur;
        this.fermer();
        this.envoyer();
    }

    envoyer() {
        const formulaire = this.element.closest('form');
        if (formulaire) {
            formulaire.requestSubmit();
        }
    }

    normaliser(texte) {
        return (texte || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    }
}
