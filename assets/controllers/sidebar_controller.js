import { Controller } from '@hotwired/stimulus';

/*
 * Sidebar flottante (pro, admin, client).
 * - Desktop : repliable en rail d'icônes, état mémorisé par navigateur (localStorage, confort uniquement).
 * - Mobile  : tiroir avec fond assombri ; Échap ou clic extérieur pour fermer.
 * Cibles : `panel` (l'<aside>), `backdrop`. Actions : toggle (desktop), open / close (mobile).
 */
export default class extends Controller {
    static targets = ['panel', 'backdrop'];

    static values = { key: { type: String, default: 'kysure.sidebar.collapsed' } };

    connect() {
        this._onKeydown = this._onKeydown.bind(this);
        document.addEventListener('keydown', this._onKeydown);
        this._setCollapsed(this._read() === '1');
    }

    disconnect() {
        document.removeEventListener('keydown', this._onKeydown);
    }

    toggle() {
        this._setCollapsed(!this.panelTarget.hasAttribute('data-collapsed'));
    }

    open() {
        this.panelTarget.classList.remove('-translate-x-[110%]');
        this.backdropTarget.classList.remove('hidden');
        requestAnimationFrame(() => this.backdropTarget.classList.remove('opacity-0'));
    }

    close() {
        this.panelTarget.classList.add('-translate-x-[110%]');
        this.backdropTarget.classList.add('opacity-0');
        this.backdropTarget.classList.add('hidden');
    }

    _setCollapsed(collapsed) {
        this.panelTarget.toggleAttribute('data-collapsed', collapsed);
        this.panelTarget.setAttribute('data-collapsed-state', collapsed ? 'true' : 'false');
        this._write(collapsed ? '1' : '0');
    }

    _onKeydown(event) {
        if ('Escape' === event.key) {
            this.close();
        }
    }

    _read() {
        try {
            return window.localStorage.getItem(this.keyValue);
        } catch {
            return null;
        }
    }

    _write(value) {
        try {
            window.localStorage.setItem(this.keyValue, value);
        } catch {
            // stockage indisponible (navigation privée) : on ignore, simple confort
        }
    }
}
