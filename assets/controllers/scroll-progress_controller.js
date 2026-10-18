import { Controller } from '@hotwired/stimulus';

/**
 * Expose la progression du scroll d'un élément dans la variable CSS `--progress` (0 → 1).
 * Le CSS en fait ce qu'il veut (parallaxe légère, texte qui s'éclaire mot à mot…).
 *
 * - mode `enter` (défaut) : 0 quand le centre de l'élément est à `start` × hauteur de
 *   viewport, 1 quand il atteint `end` × hauteur de viewport (l'élément « arrive »).
 * - mode `exit` : 0 tant que le haut de l'élément n'a pas quitté l'écran, 1 quand il
 *   est entièrement sorti (l'élément « s'efface »).
 *
 * Avec `prefers-reduced-motion`, la variable est figée à son état final : aucune animation.
 */
export default class extends Controller {
    static values = {
        mode: { type: String, default: 'enter' },
        start: { type: Number, default: 0.9 },
        end: { type: Number, default: 0.4 },
    };

    connect() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            this.element.style.setProperty('--progress', this.modeValue === 'exit' ? '0' : '1');

            return;
        }

        this.ticking = false;
        this.onScroll = () => {
            if (this.ticking) {
                return;
            }

            this.ticking = true;
            requestAnimationFrame(() => {
                this.update();
                this.ticking = false;
            });
        };

        window.addEventListener('scroll', this.onScroll, { passive: true });
        window.addEventListener('resize', this.onScroll, { passive: true });
        this.update();
    }

    disconnect() {
        window.removeEventListener('scroll', this.onScroll);
        window.removeEventListener('resize', this.onScroll);
    }

    update() {
        const rect = this.element.getBoundingClientRect();
        const viewport = window.innerHeight;
        let progress;

        if (this.modeValue === 'exit') {
            progress = -rect.top / rect.height;
        } else {
            const center = rect.top + rect.height / 2;
            progress = (viewport * this.startValue - center) / (viewport * (this.startValue - this.endValue));
        }

        this.element.style.setProperty('--progress', Math.min(1, Math.max(0, progress)).toFixed(3));
    }
}
