// Runs entirely in the browser. Only classification results (state +
// timestamps) are ever sent to the server - the video frame itself never
// leaves this page.
import { FaceLandmarker, HandLandmarker, FilesetResolver } from 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14';

// Pinned version: tasks-vision ships breaking changes between minors,
// pinning keeps the demo reproducible.
const TASKS_VISION_VERSION = '0.10.14';
const FACE_MODEL_URL = 'https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task';
const HAND_MODEL_URL = 'https://storage.googleapis.com/mediapipe-models/hand_landmarker/hand_landmarker/float16/1/hand_landmarker.task';

const STATE_ENGAGED = 'engaged';
const STATE_DISTRACTED = 'distracted';
const STATE_ABSENT = 'absent';

const TICK_MS = 1000;
const FLUSH_MS = 5000;
const ABSENCE_TICKS = 2; // "absent" only after >2s with no face, per spec
const YAW_THRESHOLD_DEG = 25; // beyond this the student is looking sideways

const STATE_LABELS = {
    [STATE_ENGAGED]: 'Вовлечён',
    [STATE_DISTRACTED]: 'Отвлечён',
    [STATE_ABSENT]: 'Отсутствует',
};

/**
 * Decomposes a 4x4 column-major rotation matrix (MediaPipe's
 * facialTransformationMatrix) into yaw/pitch/roll in degrees. We only need
 * yaw - "looking sideways at a neighbour or the window" - the spec treats
 * looking down at a notebook as engaged regardless of pitch.
 */
function yawDegreesFromMatrix(m) {
    const r00 = m[0], r10 = m[1], r20 = m[2];
    const sy = Math.sqrt(r00 * r00 + r10 * r10);
    const yaw = Math.atan2(-r20, sy);
    return yaw * (180 / Math.PI);
}

class CaptureSession {
    constructor(config, dom) {
        this.config = config;
        this.dom = dom;
        this.sessionId = null;
        this.faceLandmarker = null;
        this.handLandmarker = null;
        this.stream = null;
        this.tickTimer = null;
        this.flushTimer = null;
        this.rafHandle = null;
        this.buffer = this.loadBuffer();
        this.handBuffer = [];
        this.currentState = null;
        this.currentStateStartedAt = null;
        this.handRaised = null;
        this.handRaisedStartedAt = null;
        this.noFaceStreak = 0;
        this.lastResults = null;
        this.finishing = false;
    }

    bufferKey() {
        return `am_buffer_${this.sessionId}`;
    }

    handBufferKey() {
        return `am_hand_buffer_${this.sessionId}`;
    }

    loadBuffer() {
        return [];
    }

    persistBuffer() {
        try {
            localStorage.setItem(this.bufferKey(), JSON.stringify(this.buffer));
        } catch (e) {
            // localStorage can be unavailable (private mode, quota) - the
            // in-memory buffer is still the source of truth, this is best effort.
        }
    }

    persistHandBuffer() {
        try {
            localStorage.setItem(this.handBufferKey(), JSON.stringify(this.handBuffer));
        } catch (e) {
            // best effort, see persistBuffer()
        }
    }

    async start(studentId) {
        this.setError(null);
        this.dom.startBtn.disabled = true;

        try {
            this.stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 }, audio: false });
        } catch (e) {
            this.setError('Камера недоступна: ' + (e.message || e.name || 'нет доступа.') + ' Урок не начат.');
            this.dom.startBtn.disabled = false;
            return;
        }

        let vision;
        try {
            vision = await FilesetResolver.forVisionTasks(
                `https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@${TASKS_VISION_VERSION}/wasm`
            );
            this.faceLandmarker = await FaceLandmarker.createFromOptions(vision, {
                baseOptions: { modelAssetPath: FACE_MODEL_URL, delegate: 'GPU' },
                outputFaceBlendshapes: false,
                outputFacialTransformationMatrixes: true,
                runningMode: 'VIDEO',
                numFaces: 1,
            });
        } catch (e) {
            this.stopCamera();
            this.setError('Не удалось загрузить модель распознавания лиц. Проверьте подключение к интернету и обновите страницу.');
            this.dom.startBtn.disabled = false;
            return;
        }

        try {
            // Bonus feature - if the hand model fails to load (slow network,
            // CDN hiccup) the lesson still proceeds without hand-raise
            // detection rather than blocking on a non-essential model.
            this.handLandmarker = await HandLandmarker.createFromOptions(vision, {
                baseOptions: { modelAssetPath: HAND_MODEL_URL, delegate: 'GPU' },
                runningMode: 'VIDEO',
                numHands: 1,
            });
        } catch (e) {
            this.handLandmarker = null;
        }

        let startResponse;
        try {
            startResponse = await this.postJson(this.config.startUrl, { studentId }, false);
        } catch (e) {
            this.stopCamera();
            this.setError('Не удалось создать сессию на сервере. Попробуйте ещё раз.');
            this.dom.startBtn.disabled = false;
            return;
        }

        this.sessionId = startResponse.sessionId;
        this.buffer = [];

        this.dom.video.srcObject = this.stream;
        await this.dom.video.play();
        this.dom.overlay.width = this.dom.video.videoWidth || 640;
        this.dom.overlay.height = this.dom.video.videoHeight || 480;

        this.dom.setup.classList.add('am-hidden');
        this.dom.session.classList.remove('am-hidden');
        this.setStatus(`Сессия #${this.sessionId} запущена.`);

        this.tickTimer = setInterval(() => this.classifyTick(), TICK_MS);
        this.flushTimer = setInterval(() => this.flushBuffer(), FLUSH_MS);
        this.renderLoop();
    }

    renderLoop() {
        const ctx = this.dom.overlay.getContext('2d');
        ctx.clearRect(0, 0, this.dom.overlay.width, this.dom.overlay.height);

        if (this.lastResults && this.lastResults.faceLandmarks && this.lastResults.faceLandmarks.length > 0) {
            const landmarks = this.lastResults.faceLandmarks[0];
            let minX = 1, minY = 1, maxX = 0, maxY = 0;
            for (const p of landmarks) {
                if (p.x < minX) minX = p.x;
                if (p.x > maxX) maxX = p.x;
                if (p.y < minY) minY = p.y;
                if (p.y > maxY) maxY = p.y;
            }
            const w = this.dom.overlay.width;
            const h = this.dom.overlay.height;
            ctx.strokeStyle = this.currentState === STATE_ENGAGED ? '#2e9e44' : '#c9a227';
            ctx.lineWidth = 2;
            ctx.strokeRect(minX * w, minY * h, (maxX - minX) * w, (maxY - minY) * h);
        }

        this.updateBadge();
        this.rafHandle = requestAnimationFrame(() => this.renderLoop());
    }

    classifyTick() {
        if (!this.faceLandmarker || !this.dom.video.videoWidth) {
            return;
        }

        let results;
        try {
            results = this.faceLandmarker.detectForVideo(this.dom.video, performance.now());
        } catch (e) {
            // A transient decode error on a single frame should not kill the
            // whole session - just skip this tick and try again next second.
            return;
        }
        this.lastResults = results;

        const hasFace = results.faceLandmarks && results.faceLandmarks.length > 0;
        let observedState;
        let faceTopY = null;

        if (!hasFace) {
            this.noFaceStreak++;
            if (this.noFaceStreak >= ABSENCE_TICKS) {
                observedState = STATE_ABSENT;
            } else {
                // Brief blip (< 2s) - not yet "absent", keep last known state.
                observedState = this.currentState || STATE_DISTRACTED;
            }
        } else {
            this.noFaceStreak = 0;
            const landmarks = results.faceLandmarks[0];
            faceTopY = Math.min(...landmarks.map((p) => p.y));
            const matrix = results.facialTransformationMatrixes && results.facialTransformationMatrixes[0]
                ? results.facialTransformationMatrixes[0].data
                : null;
            const yaw = matrix ? yawDegreesFromMatrix(matrix) : 0;
            observedState = Math.abs(yaw) <= YAW_THRESHOLD_DEG ? STATE_ENGAGED : STATE_DISTRACTED;
        }

        this.applyState(observedState);
        this.classifyHandTick(faceTopY);
    }

    /**
     * Bonus feature: a hand is "raised" if its wrist landmark is above the
     * top of the face bounding box - cheap, camera-angle-tolerant heuristic
     * that needs no body pose model.
     */
    classifyHandTick(faceTopY) {
        if (!this.handLandmarker || faceTopY === null) {
            this.applyHandState(false);
            return;
        }

        let handResults;
        try {
            handResults = this.handLandmarker.detectForVideo(this.dom.video, performance.now());
        } catch (e) {
            return; // transient decode error - try again next tick
        }

        const raised = (handResults.landmarks || []).some((hand) => hand[0].y < faceTopY);
        this.applyHandState(raised);
    }

    applyHandState(raised) {
        const now = Math.floor(Date.now() / 1000);

        if (this.handRaised === null) {
            this.handRaised = raised;
            this.handRaisedStartedAt = raised ? now : null;
            return;
        }

        if (raised !== this.handRaised) {
            if (this.handRaised) {
                this.handBuffer.push({ startedAt: this.handRaisedStartedAt, endedAt: now });
                this.persistHandBuffer();
            }
            this.handRaised = raised;
            this.handRaisedStartedAt = raised ? now : null;
        }
    }

    applyState(state) {
        const now = Math.floor(Date.now() / 1000);

        if (this.currentState === null) {
            this.currentState = state;
            this.currentStateStartedAt = now;
            return;
        }

        if (state !== this.currentState) {
            this.buffer.push({ state: this.currentState, startedAt: this.currentStateStartedAt, endedAt: now });
            this.persistBuffer();
            this.currentState = state;
            this.currentStateStartedAt = now;
        }
    }

    updateBadge() {
        const badge = this.dom.badge;
        const state = this.currentState || STATE_ENGAGED;
        badge.textContent = STATE_LABELS[state];
        badge.className = 'am-state-badge am-state-' + state;

        if (this.dom.handBadge) {
            this.dom.handBadge.classList.toggle('am-hidden', !this.handRaised);
        }
    }

    async flushBuffer() {
        if ((this.buffer.length === 0 && this.handBuffer.length === 0) || this.finishing) {
            return;
        }

        const toSend = this.buffer;
        const handToSend = this.handBuffer;
        try {
            const result = await this.postJson(
                this.urlWithId(this.config.ingestUrl),
                { intervals: toSend, handEvents: handToSend },
                true
            );
            this.buffer = this.buffer.slice(toSend.length);
            this.handBuffer = this.handBuffer.slice(handToSend.length);
            this.persistBuffer();
            this.persistHandBuffer();
            this.setStatus(`Сессия #${this.sessionId}: отправлено ${result.accepted}, отклонено ${result.rejected}.`);
        } catch (e) {
            // Network/server hiccup - keep everything buffered, the data is
            // not lost, we just retry on the next flush tick.
            this.setStatus(`Сессия #${this.sessionId}: нет связи с сервером, данные копятся локально (${this.buffer.length} интервалов).`);
        }
    }

    async finish() {
        if (this.finishing) {
            return;
        }
        this.finishing = true;
        clearInterval(this.tickTimer);
        clearInterval(this.flushTimer);
        if (this.rafHandle) {
            cancelAnimationFrame(this.rafHandle);
        }

        const now = Math.floor(Date.now() / 1000);
        if (this.currentState !== null) {
            this.buffer.push({ state: this.currentState, startedAt: this.currentStateStartedAt, endedAt: now });
        }
        if (this.handRaised) {
            this.handBuffer.push({ startedAt: this.handRaisedStartedAt, endedAt: now });
        }

        this.dom.finishBtn.disabled = true;
        this.setStatus('Завершение урока...');

        try {
            const result = await this.postJson(
                this.urlWithId(this.config.finishUrl),
                { intervals: this.buffer, handEvents: this.handBuffer },
                true
            );
            this.buffer = [];
            this.handBuffer = [];
            this.persistBuffer();
            this.persistHandBuffer();
            this.stopCamera();
            window.location.href = result.reportUrl;
        } catch (e) {
            this.finishing = false;
            this.dom.finishBtn.disabled = false;
            this.setStatus('Не удалось завершить урок - проверьте связь и попробуйте снова.');
        }
    }

    stopCamera() {
        if (this.stream) {
            this.stream.getTracks().forEach((t) => t.stop());
        }
    }

    urlWithId(url) {
        return url + (url.includes('?') ? '&' : '?') + 'id=' + encodeURIComponent(this.sessionId);
    }

    async postJson(url, body, isJsonBody) {
        const options = isJsonBody
            ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
            : { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(body).toString() };

        const response = await fetch(url, options);
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data.error || ('HTTP ' + response.status));
        }
        return data;
    }

    setStatus(text) {
        this.dom.status.textContent = text;
    }

    setError(text) {
        if (!text) {
            this.dom.error.classList.add('am-hidden');
            this.dom.error.textContent = '';
            return;
        }
        this.dom.error.textContent = text;
        this.dom.error.classList.remove('am-hidden');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const config = window.__AM_CAPTURE_CONFIG__;
    if (!config) {
        return;
    }

    const dom = {
        setup: document.getElementById('am-setup'),
        session: document.getElementById('am-session'),
        studentInput: document.getElementById('am-student-id'),
        startBtn: document.getElementById('am-start-btn'),
        finishBtn: document.getElementById('am-finish-btn'),
        video: document.getElementById('am-video'),
        overlay: document.getElementById('am-overlay'),
        badge: document.getElementById('am-state-badge'),
        handBadge: document.getElementById('am-hand-badge'),
        status: document.getElementById('am-status'),
        error: document.getElementById('am-error'),
    };

    const capture = new CaptureSession(config, dom);

    dom.startBtn.addEventListener('click', () => {
        const studentId = dom.studentInput.value.trim();
        if (!studentId) {
            capture.setError('Укажите ID ученика.');
            return;
        }
        capture.start(studentId);
    });

    dom.finishBtn.addEventListener('click', () => capture.finish());

    window.addEventListener('beforeunload', () => {
        // Best-effort: if the tab is closing mid-lesson, ask the server to
        // close out whatever was already ingested rather than leaving the
        // session stuck "active" forever. Any data still only in the local
        // buffer at this point is lost - a known limitation, see README.
        if (capture.sessionId && !capture.finishing) {
            navigator.sendBeacon(capture.urlWithId(capture.config.finishUrl), new Blob([JSON.stringify({ intervals: [] })], { type: 'application/json' }));
        }
    });
});
