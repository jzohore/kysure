import { Controller } from "@hotwired/stimulus";

// Widget de feedback flottant (visible uniquement en dev/staging, cf. base_app.html.twig).
// Envoie un message + l'URL/titre de la page courante à /app/feedback en fetch() — pas de
// jeton CSRF dédié : la vérification same-origin par défaut de Symfony sur les requêtes POST
// suffit pour une requête fetch same-origin.
export default class extends Controller {
    static targets = ["dialog", "textarea", "submitButton", "confirmation"];
    static values = { url: String };

    open() {
        this.confirmationTarget.hidden = true;
        this.textareaTarget.value = "";
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    clickOutside(event) {
        if (event.target === this.dialogTarget) {
            this.close();
        }
    }

    async submit() {
        const message = this.textareaTarget.value.trim();
        if (!message) {
            return;
        }

        this.submitButtonTarget.disabled = true;

        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    message,
                    pageUrl: window.location.href,
                    pageTitle: document.title,
                }),
            });

            if (!response.ok) {
                throw new Error("Échec de l'envoi");
            }

            this.textareaTarget.value = "";
            this.confirmationTarget.hidden = false;
            setTimeout(() => this.close(), 1200);
        } catch (error) {
            console.error("Envoi du feedback impossible", error);
        } finally {
            this.submitButtonTarget.disabled = false;
        }
    }
}
