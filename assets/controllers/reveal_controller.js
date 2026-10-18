import { Controller } from '@hotwired/stimulus';

/**
 * Révèle progressivement les éléments `.reveal` quand ils entrent dans le viewport
 * (vitrine). Le décalage d'apparition se règle via `style="--reveal-delay: 120ms"`.
 *
 * Sans IntersectionObserver ou avec `prefers-reduced-motion`, tout est affiché
 * immédiatement : le contenu n'est jamais conditionné à l'animation.
 */
export default class extends Controller {
    connect() {
        const items = this.element.querySelectorAll('.reveal');
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (reducedMotion || !('IntersectionObserver' in window)) {
            items.forEach((item) => item.setAttribute('data-revealed', ''));

            return;
        }

        this.observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (!entry.isIntersecting) {
                        continue;
                    }

                    entry.target.setAttribute('data-revealed', '');
                    this.observer.unobserve(entry.target);
                }
            },
            { threshold: 0.15, rootMargin: '0px 0px -8% 0px' },
        );

        items.forEach((item) => this.observer.observe(item));
    }

    disconnect() {
        this.observer?.disconnect();
    }
}
