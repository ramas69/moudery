import { Controller } from '@hotwired/stimulus';

/*
 * Paiement groupé : filtre instantané de la liste des membres, « tout cocher » sur les lignes visibles, compteur des
 * membres cochés et des cibles choisies dans le bouton. Sans JavaScript, le filtre passe par le formulaire GET et le
 * bouton reste actif côté serveur (le contrôleur vérifie tout).
 */
export default class extends Controller {
    static targets = ['filtre', 'ligne', 'toutCocher', 'resume', 'bouton', 'libelle'];

    connect() {
        this.filtrer();
        this.compter();
    }

    filtrer() {
        const texte = this.normaliser(this.hasFiltreTarget ? this.filtreTarget.value : '');
        this.ligneTargets.forEach((ligne) => {
            ligne.hidden = texte !== '' && !this.normaliser(ligne.dataset.texte || '').includes(texte);
        });
        this.compter();
    }

    toutCocher() {
        const coche = this.toutCocherTarget.checked;
        this.ligneTargets.forEach((ligne) => {
            if (ligne.hidden) {
                return;
            }
            const case_ = ligne.querySelector('input[type="checkbox"]');
            if (case_ && !case_.disabled) {
                case_.checked = coche;
            }
        });
        this.compter();
    }

    compter() {
        const membres = this.ligneTargets.filter((ligne) => {
            const case_ = ligne.querySelector('input[type="checkbox"]');
            return case_ && case_.checked;
        }).length;
        const cibles = this.element.querySelectorAll('input[name="cibles[]"]:checked').length;
        if (this.hasBoutonTarget) {
            this.boutonTarget.disabled = membres === 0 || cibles === 0;
        }
        if (this.hasLibelleTarget) {
            const libelle = this.libelleTarget;
            libelle.dataset.zero = libelle.dataset.zero || libelle.textContent;
            libelle.textContent = membres === 0
                ? libelle.dataset.zero
                : (membres === 1 ? libelle.dataset.un : libelle.dataset.plusieurs.replace('999', String(membres)));
        }
        if (this.hasResumeTarget) {
            const modele = this.resumeTarget.dataset.modele || this.resumeTarget.textContent;
            this.resumeTarget.dataset.modele = modele;
            this.resumeTarget.textContent = membres === 0
                ? modele
                : `${membres} membre${membres > 1 ? 's' : ''} · ${cibles} cible${cibles > 1 ? 's' : ''}`;
        }
    }

    normaliser(texte) {
        return String(texte).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
    }
}
