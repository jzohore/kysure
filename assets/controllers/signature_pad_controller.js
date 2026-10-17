import { Controller } from '@hotwired/stimulus';
import SignaturePad from 'signature_pad';

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static targets = ['canvas', 'input']

    connect() {
        // 🪄 Initialisation du Canvas avec un style "stylo plume" (bleu foncé)
        this.pad = new SignaturePad(this.canvasTarget, {
            penColor: 'rgb(15, 23, 42)', // slate-900 de Tailwind
            backgroundColor: 'rgba(255, 255, 255, 0)', // Fond transparent
            minWidth: 1.5,
            maxWidth: 3.5 // Épaisseur du trait
        });

        // 🪄 Ajustement de la résolution pour les écrans Retina/MacBook
        this.resizeCanvas();
        window.addEventListener("resize", () => this.resizeCanvas());

        // À chaque fois que l'utilisateur lève le clic/doigt, on met à jour le champ caché
        this.pad.addEventListener("endStroke", () => {
            this.inputTarget.value = this.pad.toDataURL('image/png');

            // Si tu utilises les Live Components, on force la synchro du champ
            this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
        });
    }

    clear() {
        this.pad.clear();
        this.inputTarget.value = '';
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
    }

    resizeCanvas() {
        const ratio = Math.max(window.devicePixelRatio || 1, 1);
        this.canvasTarget.width = this.canvasTarget.offsetWidth * ratio;
        this.canvasTarget.height = this.canvasTarget.offsetHeight * ratio;
        this.canvasTarget.getContext("2d").scale(ratio, ratio);
        this.pad.clear(); // Optionnel : efface si on redimensionne la fenêtre
    }
}
