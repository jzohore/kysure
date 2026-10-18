import { Controller } from '@hotwired/stimulus';

/**
 * Rafraîchit les zones `[data-ocr-zone]` de la fiche dossier quand une analyse OCR se termine
 * (signal Mercure sur le topic du dossier), sans recharger la page : on récupère la page à jour
 * et on ne remplace que ces zones, en conservant l'état ouvert/fermé des « données extraites ».
 */
export default class extends Controller {
    static values = { topic: String }

    connect() {
        if (!this.topicValue) return;

        this.eventSource = new EventSource(this.topicValue);
        this.eventSource.addEventListener('message', (event) => {
            let payload = null;
            try { payload = JSON.parse(event.data); } catch { /* signal non JSON : ignoré */ }
            if (payload?.action !== 'document_ocr_analysed') return;

            // Plusieurs pièces analysées à la suite : un seul rafraîchissement.
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.refresh(), 400);
        });
    }

    disconnect() {
        clearTimeout(this.timer);
        if (this.eventSource) this.eventSource.close();
    }

    async refresh() {
        try {
            const response = await fetch(window.location.href, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) return;

            const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');

            this.element.querySelectorAll('[data-ocr-zone]').forEach((zone) => {
                const next = fresh.getElementById(zone.id);
                if (!next) return;

                const wasOpen = Array.from(zone.querySelectorAll('details')).map((d) => d.open);
                zone.replaceWith(next);
                next.querySelectorAll('details').forEach((d, i) => { d.open = wasOpen[i] ?? d.open; });
            });
        } catch {
            // Rafraîchissement cosmétique : au pire, le CGP recharge la page.
        }
    }
}
