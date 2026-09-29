import { Controller } from '@hotwired/stimulus';

/*
 * Sélection multiple dans un tableau de membres (artboard « 05 Membres · trésorière ») : la barre flottante compte les
 * lignes cochées et le total dû, et porte les membres choisis vers « Relancer » (formulaire POST), « Paiement »
 * (page de saisie groupée, membres précochés), « Exporter » (ids), « Inviter à leur espace » (formulaire POST, bouton
 * inactif tant qu'aucun coché n'a d'adresse sans compte) et, sur les impayés, « Marquer comme appelé » (formulaire
 * POST) avec le nombre de cochés sans e-mail. Sans JavaScript, la barre reste cachée : chaque action
 * existe ailleurs.
 */
export default class extends Controller {
    static targets = ['ligne', 'toutCocher', 'barre', 'nombre', 'texte', 'sansEmail', 'formRelancer', 'formAppeler', 'formInviter', 'boutonInviter', 'nombreInviter', 'lienPaiement', 'lienExport'];

    connect() {
        this.compter();
    }

    cases() {
        return this.ligneTargets.map((ligne) => ligne.querySelector('input[type="checkbox"]')).filter((c) => c);
    }

    toutCocher() {
        const coche = this.toutCocherTarget.checked;
        this.cases().forEach((c) => { c.checked = coche; });
        this.compter();
    }

    vider() {
        this.cases().forEach((c) => { c.checked = false; });
        if (this.hasToutCocherTarget) {
            this.toutCocherTarget.checked = false;
        }
        this.compter();
    }

    /* Les membres cochés deviennent des champs cachés membres[] du formulaire (relancer, marquer comme appelé). */
    remplir(formulaire, ids) {
        formulaire.querySelectorAll('input[name="membres[]"]').forEach((i) => i.remove());
        ids.forEach((id) => {
            const champ = document.createElement('input');
            champ.type = 'hidden';
            champ.name = 'membres[]';
            champ.value = id;
            formulaire.appendChild(champ);
        });
    }

    compter() {
        const cochees = this.cases().filter((c) => c.checked);
        const ids = cochees.map((c) => c.value);
        const du = cochees.reduce((somme, c) => somme + parseInt(c.dataset.du || '0', 10), 0);
        if (this.hasBarreTarget) {
            this.barreTarget.hidden = cochees.length === 0;
        }
        if (this.hasNombreTarget) {
            this.nombreTarget.textContent = String(cochees.length);
        }
        if (this.hasTexteTarget) {
            const euros = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(du / 100);
            this.texteTarget.textContent = `${cochees.length > 1 ? 'sélectionnés' : 'sélectionné'} · ${euros} dus`;
        }
        /* Impayés : combien de lignes cochées ne peuvent pas être relancées par e-mail (data-injoignable). */
        const injoignables = cochees.filter((c) => c.dataset.injoignable === '1').length;
        if (this.hasSansEmailTarget) {
            this.sansEmailTarget.hidden = injoignables === 0;
            this.sansEmailTarget.textContent = `· ${injoignables} sans e-mail`;
        }
        [...this.formRelancerTargets, ...this.formAppelerTargets, ...this.formInviterTargets].forEach((formulaire) => this.remplir(formulaire, ids));
        /* Membres : l'invitation à leur espace ne concerne que les cochés avec adresse et sans compte (data-invitable). */
        const invitables = cochees.filter((c) => c.dataset.invitable === '1').length;
        if (this.hasBoutonInviterTarget) {
            this.boutonInviterTarget.disabled = invitables === 0;
        }
        if (this.hasNombreInviterTarget) {
            this.nombreInviterTarget.textContent = invitables > 0 ? `· ${invitables}` : '';
        }
        if (this.hasLienPaiementTarget) {
            const url = new URL(this.lienPaiementTarget.href, window.location.href);
            url.searchParams.delete('membres[]');
            ids.forEach((id) => url.searchParams.append('membres[]', id));
            this.lienPaiementTarget.href = url.toString();
        }
        if (this.hasLienExportTarget) {
            const url = new URL(this.lienExportTarget.href, window.location.href);
            url.searchParams.delete('ids[]');
            ids.forEach((id) => url.searchParams.append('ids[]', id));
            this.lienExportTarget.href = url.toString();
        }
    }
}
