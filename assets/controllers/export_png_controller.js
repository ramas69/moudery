import { Controller } from '@hotwired/stimulus';

/*
 * Export d'un graphique en PNG (F-35), sans librairie : la section est clonée avec ses styles calculés recopiés en
 * ligne, placée dans un SVG <foreignObject>, dessinée sur un canevas en double résolution puis téléchargée. Les
 * boutons, les bascules et le tableau de données dépliable ne font pas partie de l'image.
 */
export default class extends Controller {
    static targets = ['bouton'];
    static values = { nom: { type: String, default: 'graphique' } };

    async exporter() {
        const zone = this.element;
        const largeur = Math.ceil(zone.getBoundingClientRect().width);
        const clone = this.cloner(zone);
        clone.querySelectorAll('[data-export-png-target="bouton"], nav, details, .export-png').forEach((el) => el.remove());
        clone.style.margin = '0';
        clone.style.width = `${largeur}px`;

        // La hauteur après retrait des boutons : on mesure le clone hors écran.
        const mesure = document.createElement('div');
        mesure.style.cssText = 'position:fixed;left:-10000px;top:0;';
        mesure.appendChild(clone);
        document.body.appendChild(mesure);
        const hauteur = Math.ceil(clone.getBoundingClientRect().height);
        mesure.remove();

        const html = new XMLSerializer().serializeToString(clone);
        const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${largeur}" height="${hauteur}"><foreignObject x="0" y="0" width="100%" height="100%">${html}</foreignObject></svg>`;
        const image = new Image();
        image.decoding = 'sync';
        const charge = new Promise((resolve, reject) => {
            image.onload = resolve;
            image.onerror = reject;
        });
        image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
        try {
            await charge;
            const echelle = 2;
            const canevas = document.createElement('canvas');
            canevas.width = largeur * echelle;
            canevas.height = hauteur * echelle;
            const contexte = canevas.getContext('2d');
            contexte.fillStyle = '#ffffff';
            contexte.fillRect(0, 0, canevas.width, canevas.height);
            contexte.scale(echelle, echelle);
            contexte.drawImage(image, 0, 0);
            canevas.toBlob((blob) => {
                if (!blob) {
                    return;
                }
                const lien = document.createElement('a');
                lien.href = URL.createObjectURL(blob);
                lien.download = `${this.nomValue}.png`;
                document.body.appendChild(lien);
                lien.click();
                lien.remove();
                setTimeout(() => URL.revokeObjectURL(lien.href), 1000);
            }, 'image/png');
        } catch (erreur) {
            console.error('Export PNG impossible', erreur);
        }
    }

    /* Copie profonde avec les styles calculés de chaque élément recopiés en ligne. */
    cloner(source) {
        const copie = source.cloneNode(false);
        if (source.nodeType === Node.ELEMENT_NODE) {
            const style = window.getComputedStyle(source);
            let texte = '';
            for (let i = 0; i < style.length; i += 1) {
                const propriete = style[i];
                if (propriete.startsWith('animation') || propriete.startsWith('transition')) {
                    continue;
                }
                texte += `${propriete}:${style.getPropertyValue(propriete)};`;
            }
            copie.setAttribute('style', `${texte}animation:none;transition:none;`);
            copie.removeAttribute('data-controller');
            copie.removeAttribute('data-action');
        }
        source.childNodes.forEach((enfant) => {
            if (enfant.nodeType === Node.ELEMENT_NODE || enfant.nodeType === Node.TEXT_NODE) {
                copie.appendChild(enfant.nodeType === Node.TEXT_NODE ? enfant.cloneNode(false) : this.cloner(enfant));
            }
        });
        if (copie.nodeType === Node.ELEMENT_NODE && copie.namespaceURI === 'http://www.w3.org/1999/xhtml' && source === this.element) {
            copie.setAttribute('xmlns', 'http://www.w3.org/1999/xhtml');
        }
        return copie;
    }
}
