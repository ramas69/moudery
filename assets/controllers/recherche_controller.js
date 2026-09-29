import { Controller } from '@hotwired/stimulus';

/*
 * Recherche instantanée : le formulaire GET se soumet tout seul 300 ms après la dernière frappe. Il vise une frame
 * Turbo (data-turbo-frame) qui ne remplace que la liste ; le champ de recherche, dans un élément data-turbo-permanent,
 * garde son contenu et le focus. « valeur » est ce que le serveur a rendu. À chaque reconnexion, le contrôleur
 * compare : si le rendu répond à ce qu'il a envoyé (mémorisé sur le champ, qui survit au rendu) et que la personne a
 * continué de taper, la liste se recharge une fois de plus ; sinon (« Effacer », une entrée de navigation, un retour),
 * le serveur a raison et le champ reprend sa valeur.
 */
export default class extends Controller {
    static values = {
        valeur: { type: String, default: '' },
        delai: { type: Number, default: 300 },
    };

    connect() {
        this.derniereEnvoyee = this.valeurValue.trim();
        /* Turbo ne remet le champ permanent en place qu'après un `await` qui suit l'insertion du nouveau contenu :
           à la connexion, le formulaire ne contient encore qu'un jalon. La comparaison attend le tick suivant. */
        this.report = setTimeout(() => this.reconcilier(), 0);
    }

    reconcilier() {
        const saisie = this.saisie;
        if (!saisie) {
            return;
        }
        const serveur = this.derniereEnvoyee;
        const envoyee = saisie.dataset.rechercheEnvoyee;
        delete saisie.dataset.rechercheEnvoyee;
        if (envoyee === serveur) {
            if (saisie.value.trim() !== serveur) {
                this.programmer();
            }
        } else if (saisie.value.trim() !== serveur) {
            saisie.value = this.valeurValue;
        }
    }

    disconnect() {
        clearTimeout(this.minuterie);
        clearTimeout(this.report);
    }

    saisir() {
        this.programmer();
    }

    /* Entrée dans le champ : le formulaire part tout de suite, inutile de le renvoyer après le délai. */
    annuler() {
        clearTimeout(this.minuterie);
        this.marquer();
    }

    programmer() {
        clearTimeout(this.minuterie);
        this.minuterie = setTimeout(() => this.soumettre(), this.delaiValue);
    }

    soumettre() {
        if (!this.saisie || this.saisie.value.trim() === this.derniereEnvoyee) {
            return;
        }
        this.marquer();
        this.element.requestSubmit();
    }

    marquer() {
        if (this.saisie) {
            this.derniereEnvoyee = this.saisie.value.trim();
            this.saisie.dataset.rechercheEnvoyee = this.derniereEnvoyee;
        }
    }

    get saisie() {
        return this.element.querySelector('input[type="search"]');
    }
}
