import { Controller } from '@hotwired/stimulus';

/*
 * Sommaire qui suit la lecture (page Aide) : au défilement, le lien de la section visible en haut de l'écran reçoit
 * aria-current, et le sommaire défile pour le garder en vue. Sans JavaScript, le sommaire reste une liste de liens.
 */
export default class extends Controller {
    static targets = ['nav'];

    connect() {
        this.liens = [...this.navTarget.querySelectorAll('a[href^="#"]')];
        this.cibles = this.liens.map((lien) => document.getElementById(lien.getAttribute('href').slice(1))).filter((c) => c);
        this.suivre = () => {
            if (this.demande) { return; }
            this.demande = requestAnimationFrame(() => { this.demande = null; this.marquer(); });
        };
        window.addEventListener('scroll', this.suivre, { passive: true });
        window.addEventListener('resize', this.suivre);
        this.marquer();
    }

    disconnect() {
        window.removeEventListener('scroll', this.suivre);
        window.removeEventListener('resize', this.suivre);
        cancelAnimationFrame(this.demande);
    }

    /* La section courante est la dernière dont le haut est passé au-dessus du tiers de l'écran. */
    marquer() {
        const seuil = window.innerHeight / 3;
        let courante = this.cibles[0];
        for (const cible of this.cibles) {
            if (cible.getBoundingClientRect().top <= seuil) { courante = cible; }
        }
        for (const lien of this.liens) {
            const actif = courante && lien.getAttribute('href') === '#' + courante.id;
            if (actif) {
                if (!lien.hasAttribute('aria-current')) {
                    lien.setAttribute('aria-current', 'true');
                    lien.scrollIntoView({ block: 'nearest', inline: 'nearest' });
                }
            } else {
                lien.removeAttribute('aria-current');
            }
        }
    }
}
