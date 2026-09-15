import { Controller } from '@hotwired/stimulus';

// Upload d'une pièce jointe support hors cycle LiveComponent : un fichier ne
// peut pas être transporté d'une requête LiveComponent à l'autre. On envoie
// donc un POST classique, puis on déclenche `live:render` pour rafraîchir
// la conversation (même convention que document_upload_controller.js).
export default class extends Controller {
    static values = { url: String };
    static targets = ['input', 'trigger', 'error', 'message', 'category', 'topic'];

    async upload() {
        const file = this.inputTarget.files[0];
        if (!file) return;

        this.hideError();
        this.triggerTarget.classList.add('opacity-50', 'pointer-events-none');

        const formData = new FormData();
        formData.append('attachment', file);
        if (this.hasMessageTarget) {
            formData.append('content', this.messageTarget.value);
        }
        if (this.hasCategoryTarget) {
            formData.append('category', this.categoryTarget.value);
        }
        if (this.hasTopicTarget) {
            formData.append('topic', this.topicTarget.value);
        }

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();

            if (response.ok && data.ok) {
                if (this.hasMessageTarget) {
                    this.messageTarget.value = '';
                }
                this.element.dispatchEvent(new CustomEvent('live:render', { bubbles: true }));
            } else {
                this.showError(data.message || "Erreur lors de l'envoi du fichier.");
            }
        } catch {
            this.showError("Erreur réseau lors de l'envoi du fichier.");
        } finally {
            this.triggerTarget.classList.remove('opacity-50', 'pointer-events-none');
            this.inputTarget.value = '';
        }
    }

    showError(message) {
        this.errorTarget.textContent = message;
        this.errorTarget.classList.remove('hidden');
    }

    hideError() {
        this.errorTarget.textContent = '';
        this.errorTarget.classList.add('hidden');
    }
}
