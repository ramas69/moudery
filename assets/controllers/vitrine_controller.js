import { Controller } from '@hotwired/stimulus';

/*
 * Page d'accueil : l'animation « un membre paie, la caisse le voit » ne tourne que lorsque la section est à l'écran
 * (économie de batterie, et on la voit depuis le début). Sans JavaScript, la scène reste immobile dans son état initial.
 */
export default class extends Controller {
    connect() {
        if (!('IntersectionObserver' in window)) {
            this.element.classList.add('demo--visible');
            return;
        }
        this.observateur = new IntersectionObserver((entrees) => {
            entrees.forEach((entree) => this.element.classList.toggle('demo--visible', entree.isIntersecting));
        }, { threshold: 0.35 });
        this.observateur.observe(this.element);
    }

    disconnect() {
        this.observateur?.disconnect();
    }
}
