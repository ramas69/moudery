import { Controller } from '@hotwired/stimulus';

/*
 * Enregistrement d'un paiement (F-17) : le total se recalcule à chaque case cochée ou montant libre saisi. Sans
 * JavaScript, le serveur calcule le total à l'enregistrement : rien n'en dépend.
 */
export default class extends Controller {
    static targets = ['total'];

    connect() {
        this.recalculer();
    }

    recalculer() {
        let total = 0;
        this.element.querySelectorAll('input[type="checkbox"]').forEach((case_) => {
            if (!case_.checked) {
                return;
            }
            const ligne = case_.closest('li');
            const libre = ligne ? ligne.querySelector('input[data-libre]') : null;
            if (libre) {
                const saisie = parseFloat(String(libre.value).replace(/\s/g, '').replace(',', '.'));
                total += Number.isFinite(saisie) ? Math.round(saisie * 100) : 0;
            } else {
                total += parseInt(case_.dataset.montant || '0', 10);
            }
        });
        if (this.hasTotalTarget) {
            this.totalTarget.textContent = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(total / 100);
        }
    }
}
