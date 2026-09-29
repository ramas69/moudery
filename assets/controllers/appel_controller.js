import { Controller } from '@hotwired/stimulus';

/*
 * « Nouvel appel à contribution » : recalcule en direct « Ce qui va se passer », l'aperçu de l'e-mail et le bouton
 * « Lancer l'appel à N membres » quand on change de type, de montant, de mode, de date, de périmètre ou de message.
 * Le serveur fait le même calcul à chaque envoi : sans JavaScript, la page reste juste après enregistrement.
 * Choisir un type reprend son mode, son montant par défaut et son taux (un type sans taux laisse le champ libre).
 */
export default class extends Controller {
    static targets = [
        'type', 'objet', 'montant', 'montantLibelle', 'mode', 'dateLimite', 'perimetre', 'taux', 'message', 'relances',
        'reversementFixe', 'reversementTexte', 'reversementLibre',
        'membres', 'echeances', 'objectif', 'partCentral', 'emails', 'sansEmail', 'ligneSansEmail', 'relancesDates', 'noteSansEmail',
        'apercuObjet', 'apercuTexte', 'apercuPayer', 'lancer', 'lancerLibelle',
    ];

    static values = { statistiques: Object, types: Object, calendrier: Array, association: String, prenom: String, textes: Object };

    connect() {
        // Le bouton « Lancer » est dans l'en-tête, hors du formulaire : on le rattache au contrôleur par son id.
        this.boutonLancer = document.querySelector('button[form="appel-formulaire"][value="lancer"]');
        this.libelleLancer = this.boutonLancer?.querySelector('[data-appel-target="lancerLibelle"]');
        this.calculer();
    }

    typeChoisi() {
        const radio = this.typeTargets.find((r) => r.checked);
        return radio ? { code: radio.value, ...this.typesValue[radio.value] } : null;
    }

    changerType() {
        const type = this.typeChoisi();
        if (!type) {
            return;
        }
        this.modeTarget.value = type.mode;
        this.montantTarget.value = type.montant ? (type.montant / 100).toFixed(2).replace('.', ',') : '';
        if (type.taux !== null && this.hasTauxTarget) {
            this.tauxTarget.value = type.taux;
        }
        this.calculer();
    }

    calculer() {
        const type = this.typeChoisi();
        if (!type) {
            return;
        }
        const t = this.textesValue;
        const libre = this.modeTarget.value === 'libre';
        const montant = this.centimes(this.montantTarget.value);
        const taux = type.taux !== null ? type.taux : (parseInt(this.tauxTarget.value, 10) || 0);
        const compteurs = (this.statistiquesValue[this.perimetreTarget.value] || {})[type.unite] || { membres: 0, emails: 0 };
        const objectif = montant > 0 ? montant * compteurs.membres : null;
        const sansEmail = compteurs.membres - compteurs.emails;

        this.montantLibelleTarget.textContent = libre ? t.montantSuggere : t.montant;
        this.reversementFixeTarget.hidden = type.taux === null;
        this.reversementLibreTarget.hidden = type.taux !== null;
        if (type.taux !== null) {
            this.reversementTexteTarget.textContent = type.taux === 0 ? t.resteVille : t.reversementFixe.replace('#', type.taux);
        }

        this.membresTarget.textContent = compteurs.membres;
        this.echeancesTarget.textContent = libre
            ? t.echeancesLibre.replace('#', compteurs.membres)
            : t.echeancesFixe.replace('#', compteurs.membres).replace('@', this.euros(montant));
        this.objectifTarget.textContent = this.euros(objectif);
        this.partCentralTarget.textContent = this.euros(objectif === null ? null : Math.round(objectif * taux / 100));
        this.emailsTarget.textContent = compteurs.emails;
        this.sansEmailTarget.textContent = sansEmail;
        this.ligneSansEmailTarget.hidden = sansEmail === 0;
        this.noteSansEmailTarget.hidden = sansEmail === 0;
        this.noteSansEmailTarget.textContent = t.noteSansEmail.replace('999', sansEmail);
        this.relancesDatesTarget.textContent = this.datesRelance();

        const date = this.dateLimite();
        const dateLongue = date ? new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }).format(date) : '…';
        const message = this.messageTarget.value.trim();
        this.apercuObjetTarget.textContent = this.objetTarget.value.trim() || t.objetVide;
        this.apercuTexteTarget.textContent = [
            t.bonjour,
            (libre ? t.corpsLibre : t.corpsFixe.replace('@', this.euros(montant))).replace('%', dateLongue),
            message.length > 110 ? `${message.slice(0, 110)}…` : message,
        ].filter(Boolean).join(' ');
        this.apercuPayerTarget.textContent = libre ? t.payerLibre : t.payerFixe.replace('@', this.euros(montant));

        if (this.boutonLancer) {
            this.boutonLancer.disabled = compteurs.membres === 0;
            this.libelleLancer.textContent = t.lancer.replace('#', compteurs.membres);
        }
    }

    datesRelance() {
        const date = this.dateLimite();
        if (!this.relancesTarget.checked || !date || this.calendrierValue.length === 0) {
            return this.textesValue.aucune;
        }
        const format = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short' });
        return this.calendrierValue.map((jours) => {
            const jour = new Date(date);
            jour.setDate(jour.getDate() + jours);
            return format.format(jour);
        }).join(' · ');
    }

    dateLimite() {
        const valeur = this.dateLimiteTarget.value;
        return /^\d{4}-\d{2}-\d{2}$/.test(valeur) ? new Date(`${valeur}T00:00:00`) : null;
    }

    centimes(saisie) {
        const nombre = parseFloat(String(saisie).replace(/\s/g, '').replace(',', '.'));
        return Number.isFinite(nombre) && nombre > 0 ? Math.round(nombre * 100) : 0;
    }

    euros(centimes) {
        if (centimes === null || centimes === undefined || centimes === 0) {
            return '—';
        }
        return new Intl.NumberFormat('fr-FR', {
            style: 'currency', currency: 'EUR', minimumFractionDigits: centimes % 100 === 0 ? 0 : 2,
        }).format(centimes / 100);
    }
}
