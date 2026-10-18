import { Controller } from '@hotwired/stimulus';

/**
 * « Scrollytelling » de la vitrine : à gauche, les étapes défilent ; à droite, une
 * maquette collante (sticky) change selon l'étape qui traverse le milieu de l'écran.
 *
 * Targets : `step` (texte d'une étape), `panel` (maquette), `dot` (pastille de progression).
 * Les maquettes sont décoratives (aria-hidden) : le texte porte tout le sens.
 */
export default class extends Controller {
    static targets = ['step', 'panel', 'dot'];

    connect() {
        if (!('IntersectionObserver' in window)) {
            return;
        }

        // Bande d'observation étroite au centre de l'écran : une seule étape y est active.
        this.observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        this.activate(this.stepTargets.indexOf(entry.target));
                    }
                }
            },
            { rootMargin: '-45% 0px -45% 0px', threshold: 0 },
        );

        this.stepTargets.forEach((step) => this.observer.observe(step));
    }

    disconnect() {
        this.observer?.disconnect();
    }

    activate(index) {
        if (index < 0) {
            return;
        }

        this.stepTargets.forEach((step, i) => step.toggleAttribute('data-active', i === index));
        this.panelTargets.forEach((panel, i) => panel.toggleAttribute('data-active', i === index));
        this.dotTargets.forEach((dot, i) => {
            dot.toggleAttribute('data-active', i === index);
            dot.setAttribute('aria-current', i === index ? 'step' : 'false');
        });
    }
}
