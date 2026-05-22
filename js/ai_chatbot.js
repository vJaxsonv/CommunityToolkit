/**
 * Community Toolkit — AI Chatbot Widget
 * Place at: public_html/js/ai_chatbot.js
 */

(function() {
    'use strict';

    const API_ENDPOINT = '/api/ai_chatbot.php';

    const STORAGE_KEY_OPEN    = 'ct_toolbot_open';
    const STORAGE_KEY_HISTORY = 'ct_toolbot_history';
    const STORAGE_KEY_CONV    = 'ct_toolbot_conv';   // role/content pairs for API
    const STORAGE_KEY_MODE    = 'ct_toolbot_mode';
    const MAX_HISTORY         = 40;

    // In-memory conversation history (role/content pairs sent to API)
    let conversationHistory = [];

    function saveState() {
        const win = document.getElementById('ctChatWindow');
        if (win) sessionStorage.setItem(STORAGE_KEY_OPEN, win.style.display === 'none' ? '0' : '1');
        localStorage.setItem(STORAGE_KEY_MODE, currentMode);

        // Save display history (HTML bubbles)
        const msgs = document.querySelectorAll('#ctMessages .ct-message');
        const history = [];
        msgs.forEach(m => history.push({ html: m.innerHTML, isUser: m.classList.contains('ct-user-message') }));
        try { localStorage.setItem(STORAGE_KEY_HISTORY, JSON.stringify(history.slice(-MAX_HISTORY))); } catch(e) {}

        // Save API conversation history (role/content pairs) — survives page navigation
        try { localStorage.setItem(STORAGE_KEY_CONV, JSON.stringify(conversationHistory.slice(-MAX_HISTORY))); } catch(e) {}
    }

    function restoreHistory() {
        // Restore display bubbles
        const raw = localStorage.getItem(STORAGE_KEY_HISTORY);
        if (raw) {
            try {
                const history = JSON.parse(raw);
                if (history.length) {
                    const msgContainer = document.getElementById('ctMessages');
                    msgContainer.innerHTML = '';
                    history.forEach(m => {
                        const div = document.createElement('div');
                        div.className = 'ct-message ' + (m.isUser ? 'ct-user-message' : 'ct-bot-message');
                        div.innerHTML = m.html;
                        msgContainer.appendChild(div);
                    });
                    msgContainer.scrollTop = msgContainer.scrollHeight;
                }
            } catch(e) {}
        }

        // Restore API conversation history so follow-up questions still work after page navigation
        const rawConv = localStorage.getItem(STORAGE_KEY_CONV);
        if (rawConv) {
            try {
                const saved = JSON.parse(rawConv);
                if (Array.isArray(saved) && saved.length) {
                    conversationHistory = saved;
                }
            } catch(e) {}
        }
    }

    function initWidget() {
        const launcher = document.createElement('div');
        launcher.className = 'ct-chat-launcher';
        launcher.innerHTML = `
            <div class="ct-chat-window" id="ctChatWindow" style="display:none;">
                <div class="ct-chat-header">
                    <div class="ct-chat-header-info">
                        <div class="ct-chat-header-icon"><i class="fas fa-robot"></i></div>
                        <div>
                            <h3>Toolbot</h3>
                            <p>Powered by AI</p>
                        </div>
                    </div>
                    <button class="ct-chat-close" id="ctChatClose" title="Close">&#x2715;</button>
                </div>

                <div class="ct-mode-tabs">
                    <button class="ct-mode-tab active" data-mode="chat">
                        <i class="fas fa-comments"></i> Chat
                    </button>
                    <button class="ct-mode-tab" data-mode="description">
                        <i class="fas fa-pen"></i> Write Description
                    </button>
                    <button class="ct-mode-tab" data-mode="price">
                        <i class="fas fa-tag"></i> Suggest Price
                    </button>
                    <button class="ct-mode-tab" data-mode="find_help" id="ctFindHelpTab">
                        <i class="fas fa-hard-hat"></i> Find Help
                    </button>
                </div>

                <div class="ct-chat-messages" id="ctMessages">
                    <div class="ct-message ct-bot-message">
                        Hi! I can help you find items, write listing descriptions, or suggest fair rental prices. What can I help with?
                    </div>
                </div>

                <div class="ct-chat-input-area">
                    <textarea class="ct-chat-input" id="ctInput"
                        placeholder="Type a message..."
                        rows="1"></textarea>
                    <button class="ct-send-btn" id="ctSendBtn" title="Send">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>

            <button class="ct-chat-btn" id="ctChatToggle">
                <i class="fas fa-robot"></i> Toolbot
            </button>
        `;
        document.body.appendChild(launcher);
        bindEvents();

        // Restore open/closed state
        const win = document.getElementById('ctChatWindow');
        if (sessionStorage.getItem(STORAGE_KEY_OPEN) === '1') {
            win.style.display = 'flex';
        }

        // Restore active mode tab
        const savedMode = localStorage.getItem(STORAGE_KEY_MODE);
        if (savedMode && savedMode !== 'chat') {
            const tab = document.querySelector('.ct-mode-tab[data-mode="' + savedMode + '"]');
            if (tab) {
                document.querySelectorAll('.ct-mode-tab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                currentMode = savedMode;
            }
        }

        // Restore display history and API conversation history
        restoreHistory();
    }

    let currentMode = 'chat';
    const modePlaceholders = {
        chat:        'Ask me anything about renting or listing...',
        description: 'Add any extra notes about your item (optional)...',
        price:       'Add extra details about condition or brand (optional)...',
        find_help:   'Describe what you need help with (optional)...',
    };
    const modeGreetings = {
        chat:        'Hi! Ask me anything about Community Toolkit — finding items, how rentals work, or anything else.',
        description: "I'll write a listing description for you! Fill in the title and category on the form first, then hit Send.",
        price:       "I'll suggest a fair rental price! Fill in the title and category on the form first, then hit Send.",
        find_help:   "I'll find local contractors and service professionals to help with this item! Describe what you need help with, or just hit Send and I'll suggest based on the item.",
    };

    function getPageContext() {
        const ctx = {};
        const titleEl    = document.querySelector('input[name="title"]');
        const categoryEl = document.querySelector('#categorySelect option:checked');
        const condEl     = document.querySelector('select[name="condition_id"] option:checked');
        if (titleEl    && titleEl.value)    ctx.title     = titleEl.value;
        if (categoryEl && categoryEl.value) ctx.category  = categoryEl.textContent.trim();
        if (condEl     && condEl.value)     ctx.condition = condEl.textContent.trim();
        const itemTitle = document.querySelector('.item-title');
        if (itemTitle) ctx.pageItemTitle = itemTitle.textContent.trim();
        const urlParams = new URLSearchParams(window.location.search);
        const listingId = urlParams.get('id');
        if (listingId) ctx.listing_id = parseInt(listingId);
        return ctx;
    }

    async function getLiveContext(pageCtx) {
        try {
            const res = await fetch('/api/chatbot_context.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ listing_id: pageCtx.listing_id || 0 })
            });
            if (!res.ok) return pageCtx;
            const text = await res.text();
            let data;
            try { data = JSON.parse(text); } catch(e) { return pageCtx; }
            if (!data || !data.success) return pageCtx;
            return {
                ...pageCtx,
                inventory:    data.inventory    || [],
                current_item: data.current_item || null,
                user_area:    data.user_area    || '',
                user_zip:     data.user_zip     || '',
                user_lat:     data.user_lat     || '',
                user_lng:     data.user_lng     || '',
            };
        } catch(e) {
            return pageCtx;
        }
    }

    function linkifyItems(text) {
        const placeholders = [];
        // Item links [Name](ID) — open in new tab so chat stays open, localStorage keeps history in sync
        text = text.replace(/\[([^\]]+)\]\((\d+)\)/g, function(m, name, id) {
            placeholders.push('<a href="/item_detail.php?id=' + id + '" target="_blank" rel="noopener" style="color:#667eea;font-weight:600;text-decoration:underline;">' + name + '</a>');
            return '\x00' + (placeholders.length - 1) + '\x00';
        });
        // External links [Label](https://...) — keep target="_blank" for external sites
        text = text.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, function(m, name, url) {
            placeholders.push('<a href="' + url + '" target="_blank" rel="noopener" style="color:#667eea;font-weight:600;text-decoration:underline;">' + name + ' ↗</a>');
            return '\x00' + (placeholders.length - 1) + '\x00';
        });
        // Escape remaining plain text
        text = text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        // Restore placeholders
        text = text.replace(/\x00(\d+)\x00/g, function(m, i) { return placeholders[parseInt(i)]; });
        // Markdown formatting
        text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        text = text.replace(/\*([^*\n]+)\*/g, '<em>$1</em>');
        text = text.replace(/^- (.+)$/gm, '• $1');
        text = text.replace(/\n/g, '<br>');
        return text;
    }

    function addMessage(text, isUser) {
        const msgs = document.getElementById('ctMessages');
        const div  = document.createElement('div');
        div.className = 'ct-message ' + (isUser ? 'ct-user-message' : 'ct-bot-message');
        if (isUser) {
            div.textContent = text;
        } else {
            // Pass raw text directly — linkifyItems handles its own escaping
            div.innerHTML = linkifyItems(text);
        }
        msgs.appendChild(div);
        msgs.scrollTop = msgs.scrollHeight;
        saveState();
    }

    function showTyping() {
        const msgs = document.getElementById('ctMessages');
        const div  = document.createElement('div');
        div.className = 'ct-typing';
        div.id = 'ctTyping';
        div.innerHTML = '<span></span><span></span><span></span>';
        msgs.appendChild(div);
        msgs.scrollTop = msgs.scrollHeight;
    }

    function hideTyping() {
        const el = document.getElementById('ctTyping');
        if (el) el.remove();
    }

    async function sendMessage() {
        const input   = document.getElementById('ctInput');
        const sendBtn = document.getElementById('ctSendBtn');
        const text    = input.value.trim();

        if (!text && currentMode === 'chat') return;

        const displayText = (currentMode !== 'chat') ? (text || '(no extra notes)') : text;
        addMessage(displayText, true);
        input.value = '';
        sendBtn.disabled = true;
        showTyping();

        try {
            let ctx = getPageContext();
            if (currentMode === 'chat') {
                try { ctx = await getLiveContext(ctx); } catch(e) {}
            } else if (currentMode === 'find_help') {
                // find_help only needs user_area for link pre-filling — not full inventory
                try {
                    const liveCtx = await getLiveContext(ctx);
                    ctx = { user_area: liveCtx.user_area, user_zip: liveCtx.user_zip };
                } catch(e) {}
            }

            // Build the payload — only chat mode gets conversation history
            // find_help, description, and price are single-shot modes;
            // injecting chat history into them breaks their strict response formats
            const payload = {
                mode:    currentMode,
                message: text,
                context: ctx,
            };
            if (currentMode === 'chat') {
                conversationHistory.push({ role: 'user', content: text });
                payload.history = conversationHistory.slice(-20);
            }

            const res = await fetch(API_ENDPOINT, {
                method:  'POST',
                headers: {'Content-Type': 'application/json'},
                body:    JSON.stringify(payload)
            });
            const data = await res.json();
            hideTyping();
            const botReply = data.response || data.error || 'Something went wrong.';
            addMessage(botReply, false);

            // Only track conversation history for chat mode
            if (currentMode === 'chat') {
                conversationHistory.push({ role: 'assistant', content: botReply });
                if (conversationHistory.length > 40) {
                    conversationHistory = conversationHistory.slice(-40);
                }
            }

        } catch (e) {
            hideTyping();
            addMessage('Could not reach the AI assistant. Please check your connection.', false);
        } finally {
            sendBtn.disabled = false;
            input.focus();
        }
    }

    function bindEvents() {
        const toggle   = document.getElementById('ctChatToggle');
        const closeBtn = document.getElementById('ctChatClose');
        const win      = document.getElementById('ctChatWindow');
        const input    = document.getElementById('ctInput');
        const sendBtn  = document.getElementById('ctSendBtn');
        const tabs     = document.querySelectorAll('.ct-mode-tab');

        toggle.addEventListener('click', () => {
            win.style.display = win.style.display === 'none' ? 'flex' : 'none';
            if (win.style.display === 'flex') input.focus();
            saveState();
        });

        closeBtn.addEventListener('click', () => {
            win.style.display = 'none';
            saveState();
        });

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                currentMode = tab.dataset.mode;
                input.placeholder = modePlaceholders[currentMode];
                const msgs = document.getElementById('ctMessages');
                msgs.innerHTML = '';
                addMessage(modeGreetings[currentMode], false);
                localStorage.removeItem(STORAGE_KEY_HISTORY);
                localStorage.removeItem(STORAGE_KEY_CONV);
                // Only reset chat history when switching away from chat
                conversationHistory = [];
                saveState();
            });
        });

        sendBtn.addEventListener('click', sendMessage);

        input.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        input.addEventListener('input', () => {
            input.style.height = 'auto';
            input.style.height = Math.min(input.scrollHeight, 80) + 'px';
        });
    }

    // Global function so item_detail.php 'Find Help' button can trigger the tab
    window.openToolbotFindHelp = function() {
        const win = document.getElementById('ctChatWindow');
        if (win) win.style.display = 'flex';
        const tab = document.getElementById('ctFindHelpTab');
        if (tab) tab.click();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWidget);
    } else {
        initWidget();
    }

    // Save state immediately before any page navigation so chat history
    // is never lost when the user clicks a link or navigates away
    window.addEventListener('beforeunload', saveState);
    // Also save on pagehide for mobile browsers that don't fire beforeunload reliably
    window.addEventListener('pagehide', saveState);
    // Intercept clicks on internal item links in the chat to ensure state saves first
    document.addEventListener('click', function(e) {
        const a = e.target.closest('a[href*="item_detail.php"]');
        if (a && !a.target) saveState();
    });
    // Clear chat history on logout so a new user on the same device starts fresh
    if (window.location.pathname.indexOf('logout.php') !== -1) {
        localStorage.removeItem(STORAGE_KEY_HISTORY);
        localStorage.removeItem(STORAGE_KEY_CONV);
        localStorage.removeItem(STORAGE_KEY_MODE);
    }

    // Sync chat history across tabs — when another tab saves new messages,
    // update this tab's in-memory conversation history so follow-ups stay coherent
    window.addEventListener('storage', function(e) {
        if (e.key === STORAGE_KEY_CONV && e.newValue) {
            try {
                const updated = JSON.parse(e.newValue);
                if (Array.isArray(updated)) conversationHistory = updated;
            } catch(err) {}
        }
    });

})();
