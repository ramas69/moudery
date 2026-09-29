import { Controller } from '@hotwired/stimulus';

/* Menu d'actions (élément details) : un seul menu ouvert à la fois, fermé par un clic ailleurs ou par Échap. */
export default class extends Controller {
    connect() {
        this.fermerAilleurs = (event) => {
            if (this.element.open && !this.element.contains(event.target)) {
                this.element.open = false;
            }
        };
        this.fermerEchap = (event) => {
            if ('Escape' === event.key && this.element.open) {
                this.element.open = false;
                this.element.querySelector('summary')?.focus();
            }
        };
        document.addEventListener('click', this.fermerAilleurs);
        document.addEventListener('keydown', this.fermerEchap);
    }

    disconnect() {
        document.removeEventListener('click', this.fermerAilleurs);
        document.removeEventListener('keydown', this.fermerEchap);
    }

    ouvrir() {
        if (!this.element.open) {
            return;
        }
        document.querySelectorAll('details.menu[open]').forEach((autre) => {
            if (autre !== this.element) {
                autre.open = false;
            }
        });
    }
}
