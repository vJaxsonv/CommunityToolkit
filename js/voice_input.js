/**
 * Community Toolkit — Global Voice Input
 * Attaches a mic button to all text inputs and textareas site-wide.
 * Primary: Web Speech API (Chrome/Edge, instant, free)
 * Fallback: OpenAI Whisper via voice_transcribe.php (all browsers)
 */
(function () {
    'use strict';

    // Skip these pages entirely
    const skipPages = ['/login', '/logout', '/register', '/forgot_password', '/reset_password'];
    const path = window.location.pathname.toLowerCase();
    if (skipPages.some(p => path.includes(p))) return;

    // Skip these input types
    const SKIP_TYPES = ['hidden','password','submit','button','reset','checkbox','radio','file','range','color','date','time','number'];
    // Skip inputs with these names (numeric/sensitive fields)
    const SKIP_NAMES = ['zipcode','zip','phone','price','hours','min_price','max_price','dob','card','cvv','cvc','expir'];
    // Skip inputs with these IDs
    const SKIP_IDS  = ['radius','hoursInput','startTime','endTime'];

    const hasSpeechAPI = ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window);

    // ── Styles ────────────────────────────────────────────────────────────────
    const style = document.createElement('style');
    style.textContent = `
        .ct-voice-wrapper {
            position: relative;
            display: inline-flex;
            width: 100%;
        }
        .ct-voice-wrapper.ct-voice-inline {
            display: inline-flex;
            width: auto;
        }
        .ct-voice-btn {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #9ca3af;
            font-size: 14px;
            padding: 4px;
            border-radius: 50%;
            transition: color 0.15s, background 0.15s;
            z-index: 10;
            line-height: 1;
        }
        .ct-voice-btn:hover { color: #667eea; background: #f0f2ff; }
        .ct-voice-btn.ct-listening {
            color: #dc2626;
            animation: ct-pulse 1s infinite;
        }
        .ct-voice-btn.ct-processing { color: #f59e0b; }
        textarea.ct-voice-padded,
        input.ct-voice-padded { padding-right: 32px !important; }
        @keyframes ct-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }
    `;
    document.head.appendChild(style);

    // ── Attach mic button to a single input/textarea ──────────────────────────
    function attachMic(el) {
        if (el.dataset.ctVoice) return; // already attached
        if (SKIP_TYPES.includes((el.type || '').toLowerCase())) return;
        if (el.readOnly || el.disabled) return;
        if (SKIP_NAMES.some(n => (el.name || '').toLowerCase().includes(n))) return;
        if (SKIP_IDS.some(n => (el.id || '').toLowerCase().includes(n))) return;

        el.dataset.ctVoice = '1';
        el.classList.add('ct-voice-padded');

        // Wrap element if not already wrapped
        const parent = el.parentElement;
        let wrapper;
        if (parent && parent.classList.contains('ct-voice-wrapper')) {
            wrapper = parent;
        } else {
            wrapper = document.createElement('div');
            wrapper.className = 'ct-voice-wrapper';
            // Preserve textarea's block display
            if (el.tagName === 'TEXTAREA') {
                wrapper.style.display = 'block';
            }
            parent.insertBefore(wrapper, el);
            wrapper.appendChild(el);
        }

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ct-voice-btn';
        btn.title = 'Voice input';
        btn.innerHTML = '<i class="fas fa-microphone"></i>';
        // For textareas, position at top-right instead of vertically centered
        if (el.tagName === 'TEXTAREA') {
            btn.style.top = '10px';
            btn.style.transform = 'none';
        }
        wrapper.appendChild(btn);

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (btn.classList.contains('ct-listening') || btn.classList.contains('ct-processing')) {
                stopListening(btn, el);
            } else {
                startListening(btn, el);
            }
        });
    }

    // ── Web Speech API path ───────────────────────────────────────────────────
    let activeRecognition = null;

    function startListening(btn, el) {
        if (hasSpeechAPI) {
            startSpeechAPI(btn, el);
        } else {
            startWhisper(btn, el);
        }
    }

    function stopListening(btn, el) {
        if (activeRecognition) {
            activeRecognition.stop();
            activeRecognition = null;
        }
        btn.classList.remove('ct-listening', 'ct-processing');
        btn.innerHTML = '<i class="fas fa-microphone"></i>';
    }

    function startSpeechAPI(btn, el) {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        const rec = new SpeechRecognition();
        rec.lang = 'en-US';
        rec.interimResults = false;
        rec.maxAlternatives = 1;
        activeRecognition = rec;

        btn.classList.add('ct-listening');
        btn.innerHTML = '<i class="fas fa-stop"></i>';
        btn.title = 'Stop listening';

        rec.onresult = function (e) {
            const transcript = e.results[0][0].transcript;
            insertText(el, transcript);
            btn.classList.remove('ct-listening');
            btn.innerHTML = '<i class="fas fa-microphone"></i>';
            btn.title = 'Voice input';
            activeRecognition = null;
        };

        rec.onerror = function (e) {
            // If speech API fails, fall through to Whisper
            if (e.error === 'not-allowed') {
                showToast('Microphone access denied. Please allow mic access in your browser settings.');
            } else {
                // Try Whisper as fallback
                startWhisper(btn, el);
                return;
            }
            btn.classList.remove('ct-listening');
            btn.innerHTML = '<i class="fas fa-microphone"></i>';
            activeRecognition = null;
        };

        rec.onend = function () {
            btn.classList.remove('ct-listening');
            btn.innerHTML = '<i class="fas fa-microphone"></i>';
            btn.title = 'Voice input';
            activeRecognition = null;
        };

        rec.start();
    }

    // ── Whisper fallback path ─────────────────────────────────────────────────
    let mediaRecorder = null;
    let audioChunks   = [];

    function startWhisper(btn, el) {
        navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
            audioChunks = [];
            mediaRecorder = new MediaRecorder(stream);

            btn.classList.add('ct-listening');
            btn.innerHTML = '<i class="fas fa-stop"></i>';
            btn.title = 'Stop recording';

            mediaRecorder.ondataavailable = function (e) {
                if (e.data.size > 0) audioChunks.push(e.data);
            };

            mediaRecorder.onstop = function () {
                stream.getTracks().forEach(t => t.stop());
                btn.classList.remove('ct-listening');
                btn.classList.add('ct-processing');
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                btn.title = 'Transcribing...';

                const blob = new Blob(audioChunks, { type: 'audio/webm' });
                const formData = new FormData();
                formData.append('audio', blob, 'recording.webm');

                const base = (window.location.pathname.includes('/admin/')) ? '../' : '';
                fetch(base + 'api/voice_transcribe.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.text) {
                        insertText(el, data.text);
                    } else {
                        showToast('Could not transcribe audio. Please try again.');
                    }
                })
                .catch(() => showToast('Transcription failed. Please try again.'))
                .finally(() => {
                    btn.classList.remove('ct-processing');
                    btn.innerHTML = '<i class="fas fa-microphone"></i>';
                    btn.title = 'Voice input';
                });
            };

            mediaRecorder.start();

            // Auto-stop after 30 seconds
            setTimeout(function () {
                if (mediaRecorder && mediaRecorder.state === 'recording') {
                    mediaRecorder.stop();
                }
            }, 30000);

        }).catch(function () {
            showToast('Microphone access denied. Please allow mic access in your browser settings.');
            btn.classList.remove('ct-listening');
            btn.innerHTML = '<i class="fas fa-microphone"></i>';
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    function insertText(el, text) {
        // Append to existing text with a space if there's already content
        const current = el.value.trim();
        el.value = current ? current + ' ' + text : text;
        // Fire input/change events so frameworks and validators react
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
        el.focus();
    }

    function showToast(msg) {
        const toast = document.createElement('div');
        toast.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:#1a1a2e;color:white;padding:10px 20px;border-radius:8px;font-size:14px;z-index:99999;box-shadow:0 4px 12px rgba(0,0,0,0.2);';
        toast.textContent = msg;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3500);
    }

    // ── Scan for inputs on load and on DOM changes ────────────────────────────
    function scanAndAttach() {
        document.querySelectorAll('input:not([data-ct-voice]), textarea:not([data-ct-voice])').forEach(attachMic);
    }

    // Initial scan after DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scanAndAttach);
    } else {
        scanAndAttach();
    }

    // Re-scan if new inputs are added dynamically (e.g. Toolbot, modals)
    const observer = new MutationObserver(function (mutations) {
        let needsScan = false;
        mutations.forEach(function (m) {
            m.addedNodes.forEach(function (n) {
                if (n.nodeType === 1 && (n.matches('input,textarea') || n.querySelector('input,textarea'))) {
                    needsScan = true;
                }
            });
        });
        if (needsScan) scanAndAttach();
    });
    observer.observe(document.body, { childList: true, subtree: true });

})();
