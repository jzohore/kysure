import { Controller } from '@hotwired/stimulus';

/**
 * Scroll en haut du composant au clic sur "Continuer" / "Précédent" : sans ça, le scroll
 * reste calé sur le bouton cliqué en bas de la section précédente et les nouvelles questions
 * démarrent hors du champ de vision (questionnaire profil investisseur, écrans par dimension).
 * Déclenché sur le clic plutôt qu'après le rendu LiveComponent : la position à l'écran du
 * conteneur ne change pas pendant le morph, donc scroller immédiatement suffit et affiche
 * aussi tout message d'erreur de validation (rendu en haut du composant) sans attendre l'Ajax.
 */
export default class extends Controller {
    scrollToTop() {
        this.element.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}
