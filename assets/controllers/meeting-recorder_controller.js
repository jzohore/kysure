import { Controller } from '@hotwired/stimulus';
import RecordRTC from 'recordrtc'; // 🛡️ Import de la librairie ultra-robuste

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static values = {
        chunkUrl: String,
        stopUrl: String,
        maxMinutes: { type: Number, default: 0 }
    }
    static targets = ["indicator", "startBtn", "stopBtn", "pauseBtn", "resumeBtn", "timer"]

    // 🛡️ Déclaration explicite de toutes les propriétés de classe
    recorder = null;
    microphone = null;
    uiTimerId = null;
    secondsRemaining = 0;
    chunkIndex = 0;
    pendingUploads = 0;
    isStopping = false;
    sessionId = null; // 🚀 AJOUT : Identifiant unique de session pour l'isolation S3

    // Analyse Audio (Fiabilité matérielle)
    audioContext = null;
    analyser = null;
    dataArray = null;
    audioProcessingInterval = null;
    silenceTimer = null;

    disconnect() {
        if (this.uiTimerId) clearInterval(this.uiTimerId);
        this.stopAudioAnalysis().catch(() => {});

        if (this.recorder) {
            try { this.recorder.destroy(); } catch (e) {}
            this.recorder = null;
        }

        if (this.microphone) {
            this.microphone.getTracks().forEach(track => track.stop());
            this.microphone = null;
        }

        // On nettoie les écouteurs d'événements globaux pour ne pas les empiler
        window.removeEventListener('kysure:recording:paused', this.handlePause);
        window.removeEventListener('kysure:recording:resumed', this.handleResume);
    }

    async start() {
        console.log('start() appelé, recorder=', this.recorder, 'isStopping=', this.isStopping);

        if (this.recorder || this.isStopping) return;
        if (this.maxMinutesValue <= 0) return alert("Solde de minutes épuisé.");

        // 🚀 AJOUT : Génération de l'UUID pour isoler cet enregistrement sur S3
        this.sessionId = this.generateSafeUUID();
        try {
            // 1. Capture du micro SANS traitement navigateur.
            // 🛡️ Compatibilité visio (Meet/Teams/Zoom ouverts en parallèle) : un second getUserMedia
            // avec des traitements différents (écho/bruit/gain) peut reconfigurer la source audio
            // partagée et couper le micro de la visio. De plus, l'annulation d'écho retire du signal
            // la voix du client diffusée par les haut-parleurs → l'enregistrement perdrait ses réponses.
            this.microphone = await navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false }
            });

            // 2. Sécurité Matérielle
            const audioTrack = this.microphone.getAudioTracks()[0];
            // Diagnostic terrain (conflit avec une visio) : réglages réellement appliqués par le navigateur.
            console.info('Micro ouvert :', audioTrack.label, audioTrack.getSettings());
            audioTrack.addEventListener('ended', () => this.handleHardwareError("Microphone déconnecté."));
            audioTrack.addEventListener('mute', () => this.handleHardwareError("Microphone muté par le système."));

            // 3. Lancement de l'analyse audio
            this.startAudioAnalysis();

            // 4. RecordRTC natif
            this.recorder = new RecordRTC(this.microphone, {
                type: 'audio',
                mimeType: 'audio/webm',
                disableLogs: true,
                timeSlice: 10000,
                ondataavailable: (blob) => {
                    if (blob.size > 0 && !this.isStopping) {
                        this.sendChunk(blob);
                    }
                }
            });

            // 5. Lancement
            this.recorder.startRecording();
            this.chunkIndex = 0;
            this.secondsRemaining = this.maxMinutesValue * 60;
            this.updateTimerUI();

            const startListeningBtn = document.getElementById('btn-start-listening');
            if (startListeningBtn) startListeningBtn.click();

            this.uiTimerId = setInterval(() => {
                if (this.recorder.getState() === 'paused') return;
                this.secondsRemaining--;
                this.updateTimerUI();

                if (this.secondsRemaining <= 0) this.stop();
            }, 1000);

            this.toggleUI('recording');

        } catch (err) {
            console.error("Erreur micro:", err);
            alert("Impossible d'accéder au microphone. Vérifiez vos autorisations système.");
        }
    }

    pause() {
        if (this.recorder && this.recorder.getState() === 'recording') {
            this.recorder.pauseRecording();
            this.toggleUI('paused');
            window.dispatchEvent(new CustomEvent('kysure:recording:paused'));
        }
    }

    resume() {
        if (this.recorder && this.recorder.getState() === 'paused') {
            this.recorder.resumeRecording();
            this.toggleUI('recording');
            window.dispatchEvent(new CustomEvent('kysure:recording:resumed'));
        }
    }

    async stop() {
        if (!this.recorder || this.recorder.getState() === 'stopped' || this.isStopping) return;

        this.isStopping = true;
        clearInterval(this.uiTimerId);
        await this.stopAudioAnalysis();

        this.timerTarget.innerHTML = "Enregistrement...";
        this.timerTarget.classList.add("text-indigo-600", "animate-pulse");

        this.recorder.stopRecording(async () => {
            if (this.microphone) {
                this.microphone.getTracks().forEach(track => track.stop());
                this.microphone = null;
            }

            await this.waitForPendingUploads();

            const totalAllocatedSeconds = this.maxMinutesValue * 60;
            const consumedSeconds = totalAllocatedSeconds - Math.max(0, this.secondsRemaining);

            const formData = new FormData();
            formData.append('consumed_seconds', consumedSeconds.toString());
            formData.append('session_id', this.sessionId); // 🚀 AJOUT : Transmission de l'UUID au serveur

            try {
                const res = await fetch(this.stopUrlValue, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await res.json().catch(() => ({}));

                if (!res.ok) {
                    throw new Error(data.error || `Erreur serveur (${res.status})`);
                }

                const startPollingBtn = document.getElementById('btn-start-polling');
                if (startPollingBtn) {
                    startPollingBtn.click();
                }

            } catch (err) {
                alert(`Erreur: ${err.message}`);
                console.error("Stop Error:", err);
            } finally {
                if (this.recorder) {
                    try {
                        this.recorder.destroy();
                    } catch (e) {
                        console.warn("Erreur silencieuse:", e);
                    }
                    this.recorder = null;
                }
                this.isStopping = false;
                this.timerTarget.classList.remove("text-indigo-600", "animate-pulse");
                this.updateTimerUI();
                this.toggleUI('idle');
                window.dispatchEvent(new CustomEvent('kysure:recording:stopped'));
            }
        });
    }

    async sendChunk(blob) {
        this.pendingUploads++;
        const formData = new FormData();

        const actualMimeType = blob.type || 'audio/webm';
        let extension = 'webm';
        if (actualMimeType.includes('mp4')) extension = 'mp4';
        else if (actualMimeType.includes('wav') || actualMimeType.includes('wave')) extension = 'wav';
        else if (actualMimeType.includes('ogg')) extension = 'ogg';

        formData.append('audio_chunk', blob, `chunk_${this.chunkIndex}.${extension}`);
        formData.append('chunk_index', this.chunkIndex++);
        formData.append('mime_type', actualMimeType);
        formData.append('session_id', this.sessionId); // 🚀 AJOUT : Transmission de l'UUID pour chaque chunk

        try {
            const res = await fetch(this.chunkUrlValue, { method: 'POST', body: formData });
            if (res.status === 402 || res.status === 403) {
                this.stop();
            }
        } catch (err) {
            console.error('Erreur réseau Chunk:', err);
        } finally {
            this.pendingUploads--;
        }
    }

    waitForPendingUploads() {
        return new Promise((resolve) => {
            const check = setInterval(() => {
                if (this.pendingUploads === 0) {
                    clearInterval(check);
                    resolve();
                }
            }, 200);
        });
    }

    handleHardwareError(message) {
        console.error(message);
        alert(`Attention : ${message} L'enregistrement est compromis.`);
        if (this.recorder && this.recorder.getState() === 'recording') {
            this.pause();
        }
    }

    startAudioAnalysis() {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (!AudioContext) return;

        this.audioContext = new AudioContext();
        const source = this.audioContext.createMediaStreamSource(this.microphone);
        this.analyser = this.audioContext.createAnalyser();

        this.analyser.fftSize = 256;
        source.connect(this.analyser);
        this.dataArray = new Uint8Array(this.analyser.frequencyBinCount);

        this.audioProcessingInterval = setInterval(() => {
            if (this.recorder && this.recorder.getState() === 'paused') return;

            this.analyser.getByteFrequencyData(this.dataArray);
            let sum = 0;
            for (let i = 0; i < this.dataArray.length; i++) sum += this.dataArray[i];
            const average = sum / this.dataArray.length;

            if (average < 2) {
                if (!this.silenceTimer) {
                    this.silenceTimer = setTimeout(() => {
                        console.warn("Silence détecté : Le micro ne capte aucun son.");
                    }, 10000);
                }
            } else {
                if (this.silenceTimer) {
                    clearTimeout(this.silenceTimer);
                    this.silenceTimer = null;
                }
            }
        }, 200);
    }

    async stopAudioAnalysis() {
        if (this.audioProcessingInterval) clearInterval(this.audioProcessingInterval);
        if (this.silenceTimer) clearTimeout(this.silenceTimer);

        if (this.audioContext && this.audioContext.state !== 'closed') {
            try {
                // 🛡️ On force le thread JS à attendre le déverrouillage matériel
                await this.audioContext.close();
            } catch (e) {
                console.error("Erreur silencieuse lors de la fermeture de l'AudioContext:", e);
            }
        }

        this.audioContext = null;
        this.analyser = null;
    }

    toggleUI(state) {
        if (this.hasStartBtnTarget) this.startBtnTarget.classList.toggle('hidden', state !== 'idle');
        if (this.hasPauseBtnTarget) this.pauseBtnTarget.classList.toggle('hidden', state !== 'recording');
        if (this.hasResumeBtnTarget) this.resumeBtnTarget.classList.toggle('hidden', state !== 'paused');
        if (this.hasStopBtnTarget) this.stopBtnTarget.classList.toggle('hidden', state === 'idle');

        if (this.hasIndicatorTarget) {
            this.indicatorTarget.classList.toggle('hidden', state !== 'recording');
        }
    }

    updateTimerUI() {
        const displaySeconds = Math.max(0, this.secondsRemaining);
        const m = Math.floor(displaySeconds / 60).toString().padStart(2, '0');
        const s = (displaySeconds % 60).toString().padStart(2, '0');

        this.timerTarget.innerHTML = `${m}:${s}`;
        this.timerTarget.classList.toggle('text-rose-600', displaySeconds > 0 && displaySeconds <= 300);
    }

    // 🛡️ NOUVEAU : Générateur d'UUID résistant aux environnements locaux non sécurisés
    generateSafeUUID() {
        if (typeof crypto !== 'undefined' && crypto.randomUUID) {
            return crypto.randomUUID();
        }

        // Fallback mathématique si le navigateur bloque l'API crypto (ex: localhost sans SSL valide)
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }
}
