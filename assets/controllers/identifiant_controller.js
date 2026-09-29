import { Controller } from '@hotwired/stimulus';

/*
 * Aperçu de l'identifiant d'une association dans les adresses : déduit du village, sinon du nom,
 * sauf si un identifiant a été saisi à la main. Le serveur applique exactement la même règle.
 */
export default class extends Controller {
    static targets = ['nom', 'village', 'slug', 'apercu', 'valeur'];

    connect() {
        this.rafraichir();
    }

    rafraichir() {
        const saisi = this.hasSlugTarget ? this.slugTarget.value.trim().toLowerCase() : '';
        const source = saisi !== '' ? saisi : (this.villageTarget.value.trim() !== '' ? this.villageTarget.value : this.nomTarget.value);
        const identifiant = saisi !== '' ? saisi : this.slugifier(source);
        this.valeurTarget.textContent = identifiant;
        this.apercuTarget.hidden = identifiant === '';
    }

    /* Même règle que l'AsciiSlugger de Symfony pour les cas courants : sans accents, en minuscules, tirets entre les mots. */
    slugifier(texte) {
        return (texte || '')
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }
}
