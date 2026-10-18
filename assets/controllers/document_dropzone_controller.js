import { Controller } from '@hotwired/stimulus';

// Dépôt d'une pièce par le client : choix (clic, glisser-déposer, appareil photo),
// aperçu AVANT envoi, envoi avec barre de progression, puis rafraîchissement du
// LiveComponent parent sans recharger la page.
//
// Un fichier ne peut pas transiter par une requête LiveComponent : l'envoi est un
// POST classique (XMLHttpRequest, pour la progression), puis on déclenche `live:render`
// (même convention que support_attachment_controller.js).
//
// Piège morphdom : le LiveComponent se re-rend toutes les 2 s tant qu'une pièce est
// en cours de traitement. Tant qu'un fichier est sélectionné ou en cours d'envoi, on
// pose `data-live-ignore` sur l'élément pour que ce re-rendu n'efface pas l'aperçu.
export default class extends Controller {
    static values = {
        url: String,
        maxSize: Number,
        types: Array,
    };

    static targets = [
        'input', 'cameraInput', 'idle', 'panel', 'thumb', 'fileName', 'fileMeta',
        'previewLink', 'progress', 'bar', 'progressLabel', 'error', 'sendButton',
        'sendLabel', 'changeButton', 'cancelButton',
    ];

    static classes = ['active'];

    connect() {
        this.file = null;
        this.objectUrl = null;
        this.xhr = null;
        this.uploading = false;
    }

    disconnect() {
        this.xhr?.abort();
        this.revokePreview();
    }

    // --- Sélection ---------------------------------------------------------

    browse() {
        this.inputTarget.click();
    }

    takePhoto() {
        this.cameraInputTarget.click();
    }

    selected(event) {
        const file = event.target.files[0];
        // Permet de re-sélectionner le même fichier après une annulation.
        event.target.value = '';
        if (file) {
            this.stage(file);
        }
    }

    dragOver(event) {
        if (this.uploading) {
            return;
        }
        event.preventDefault();
        this.element.classList.add(...this.activeClasses);
    }

    dragLeave(event) {
        if (!this.element.contains(event.relatedTarget)) {
            this.element.classList.remove(...this.activeClasses);
        }
    }

    drop(event) {
        event.preventDefault();
        this.element.classList.remove(...this.activeClasses);
        if (this.uploading) {
            return;
        }

        const files = event.dataTransfer?.files;
        if (!files || files.length === 0) {
            return;
        }
        if (files.length > 1) {
            this.showError('Une seule pièce à la fois : déposez un fichier par ligne.');
            return;
        }
        this.stage(files[0]);
    }

    // --- Aperçu ------------------------------------------------------------

    stage(file) {
        const problem = this.validate(file);
        if (problem) {
            this.showError(problem);
            return;
        }

        this.hideError();
        this.file = file;
        this.renderPreview(file);

        this.idleTarget.hidden = true;
        this.panelTarget.hidden = false;
        this.progressTarget.hidden = true;
        this.sendLabelTarget.textContent = 'Envoyer';
        this.element.setAttribute('data-live-ignore', '');
        this.sendButtonTarget.focus();
    }

    validate(file) {
        if (file.size === 0) {
            return 'Ce fichier est vide.';
        }
        if (file.size > this.maxSizeValue) {
            return `Ce fichier est trop volumineux (${this.formatSize(file.size)}). Taille maximale : ${this.formatSize(this.maxSizeValue)}.`;
        }
        if (!this.typesValue.includes(file.type)) {
            return 'Format non accepté. Déposez un PDF, un JPG ou un PNG.';
        }
        return null;
    }

    renderPreview(file) {
        this.revokePreview();
        this.objectUrl = URL.createObjectURL(file);

        this.thumbTarget.replaceChildren();
        if (file.type.startsWith('image/')) {
            const image = document.createElement('img');
            image.src = this.objectUrl;
            image.alt = 'Aperçu de la pièce sélectionnée';
            image.className = 'size-full object-cover';
            this.thumbTarget.append(image);
        } else {
            const badge = document.createElement('span');
            badge.textContent = 'PDF';
            badge.className = 'text-sm font-bold tracking-wide text-slate-500';
            this.thumbTarget.append(badge);
        }

        this.fileNameTarget.textContent = file.name;
        this.fileMetaTarget.textContent = `${file.type === 'application/pdf' ? 'PDF' : 'Image'} · ${this.formatSize(file.size)}`;
        this.previewLinkTarget.href = this.objectUrl;
    }

    revokePreview() {
        if (this.objectUrl) {
            URL.revokeObjectURL(this.objectUrl);
            this.objectUrl = null;
        }
    }

    // --- Envoi -------------------------------------------------------------

    send() {
        if (!this.file || this.uploading) {
            return;
        }

        this.hideError();
        this.setBusy(true);
        this.updateProgress(0, 'Envoi en cours…');

        const formData = new FormData();
        formData.append('document', this.file);

        const xhr = new XMLHttpRequest();
        this.xhr = xhr;
        xhr.open('POST', this.urlValue);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.responseType = 'json';

        xhr.upload.addEventListener('progress', (event) => {
            if (!event.lengthComputable) {
                return;
            }
            const percent = Math.round((event.loaded / event.total) * 100);
            this.updateProgress(percent, percent >= 100 ? 'Analyse de sécurité…' : `Envoi en cours… ${percent} %`);
        });

        xhr.addEventListener('load', () => this.handleResponse(xhr));
        xhr.addEventListener('error', () => this.fail('Connexion interrompue. Vérifiez votre réseau puis réessayez.'));
        xhr.addEventListener('timeout', () => this.fail('L\'envoi a pris trop de temps. Réessayez.'));

        xhr.timeout = 120000;
        xhr.send(formData);
    }

    handleResponse(xhr) {
        const data = xhr.response;

        if (xhr.status >= 200 && xhr.status < 300 && data?.ok) {
            this.updateProgress(100, 'Pièce envoyée');
            this.reset();
            // Le LiveComponent parent se rafraîchit : la ligne passe en « Traitement… ».
            this.element.dispatchEvent(new CustomEvent('live:render', { bubbles: true }));
            return;
        }

        this.fail(data?.message || 'L\'envoi a échoué. Réessayez.', data?.retryable === true);
    }

    fail(message, retryable = true) {
        this.xhr = null;
        this.setBusy(false);
        this.progressTarget.hidden = true;
        this.sendLabelTarget.textContent = retryable ? 'Réessayer' : 'Envoyer';
        this.showError(message);
        this.sendButtonTarget.focus();
    }

    cancel() {
        this.xhr?.abort();
        this.reset();
    }

    reset() {
        this.xhr = null;
        this.file = null;
        this.setBusy(false);
        this.revokePreview();
        this.thumbTarget.replaceChildren();
        this.panelTarget.hidden = true;
        this.idleTarget.hidden = false;
        this.progressTarget.hidden = true;
        this.hideError();
        this.element.removeAttribute('data-live-ignore');
    }

    // --- Affichage ---------------------------------------------------------

    setBusy(busy) {
        this.uploading = busy;
        this.sendButtonTarget.disabled = busy;
        this.changeButtonTarget.disabled = busy;
        this.element.setAttribute('aria-busy', busy ? 'true' : 'false');
        this.progressTarget.hidden = !busy;
    }

    updateProgress(percent, label) {
        this.progressTarget.hidden = false;
        this.barTarget.style.width = `${percent}%`;
        this.progressTarget.setAttribute('aria-valuenow', String(percent));
        this.progressLabelTarget.textContent = label;
    }

    showError(message) {
        this.errorTarget.textContent = message;
        this.errorTarget.hidden = false;
    }

    hideError() {
        this.errorTarget.textContent = '';
        this.errorTarget.hidden = true;
    }

    formatSize(bytes) {
        if (bytes < 1024 * 1024) {
            return `${Math.max(1, Math.round(bytes / 1024))} Ko`;
        }
        return `${(bytes / (1024 * 1024)).toFixed(1).replace('.', ',')} Mo`;
    }
}
