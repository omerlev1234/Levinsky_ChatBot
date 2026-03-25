<?php
/**
 * Plugin Name: Corner Chat Bubble
 * Description: Adds a fixed chat bubble that toggles a small in-page chat window and sends messages to a WP REST endpoint (which should proxy to your Python FastAPI bot). Input auto-grows + max length.
 * Version: 1.3.0
 */

if (!defined('ABSPATH')) exit;

add_action('wp_enqueue_scripts', function () {
  wp_register_style('corner-chat-style', false);
  wp_enqueue_style('corner-chat-style');
  wp_add_inline_style('corner-chat-style', corner_chat_css());

  wp_register_script('corner-chat-script', false, [], false, true);
  wp_enqueue_script('corner-chat-script');

  $endpoint = wp_make_link_relative(rest_url('levinsky/v1/chat'));
  wp_add_inline_script(
    'corner-chat-script',
    'window.CC_CHAT_ENDPOINT = ' . wp_json_encode($endpoint) . ';',
    'before'
  );

  wp_add_inline_script('corner-chat-script', corner_chat_js());
});

add_action('wp_footer', function () {
  ?>
  <div id="cc-bubble" class="cc-bubble" aria-label="Open chat" role="button" tabindex="0">
    💬
  </div>

  <div id="cc-panel" class="cc-panel" aria-hidden="true">
    <div class="cc-header">
      <div class="cc-title">Chat</div>
      <button id="cc-close" class="cc-close" type="button" aria-label="Close chat">✕</button>
    </div>

    <div id="cc-messages" class="cc-messages">
      <div class="cc-msg cc-msg-bot">שלום וברוכים הבאים למרפאת לוינסקי 🙂 כאן הצ'אטבוט של המרפאה. אם יש לכם בעיה ותרצו להתייעץ, אשמח לעזור לכם.</div>
    </div>

    <form id="cc-form" class="cc-form">
      <div class="cc-input-wrap">
        <textarea
          id="cc-input"
          class="cc-input"
          rows="1"
          placeholder="Type a message..."
          autocomplete="off"
          maxlength="500"
          aria-describedby="cc-counter"
        ></textarea>
        <div class="cc-meta">
          <span id="cc-counter" class="cc-counter">500</span>
        </div>
      </div>

      <button class="cc-send" id="cc-send" type="submit">Send</button>
    </form>
  </div>
  <?php
});

function corner_chat_css() {
  return "
  .cc-bubble{
    position:fixed;
    right:18px;
    bottom:18px;
    width:52px;
    height:52px;
    border-radius:999px;
    display:flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    user-select:none;
    font-size:22px;
    background:#111;
    color:#fff;
    box-shadow:0 10px 25px rgba(0,0,0,.25);
    z-index:999999;
  }

  .cc-panel{
    position:fixed;
    right:18px;
    bottom:84px;
    width:320px;
    max-width:calc(100vw - 36px);
    height:420px;
    max-height:calc(100vh - 140px);
    background:#fff;
    border-radius:14px;
    box-shadow:0 14px 35px rgba(0,0,0,.28);
    overflow:hidden;
    display:none;
    z-index:999999;
    border:1px solid rgba(0,0,0,.08);
  }

  .cc-panel.cc-open{ display:flex; flex-direction:column; }

  .cc-header{
    padding:10px 12px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    border-bottom:1px solid rgba(0,0,0,.08);
    background:#fafafa;
  }

  .cc-title{ font-weight:600; font-size:14px; }
  .cc-close{
    border:0;
    background:transparent;
    cursor:pointer;
    font-size:16px;
    padding:6px 8px;
    line-height:1;
  }

  .cc-messages{
    padding:12px;
    flex:1;
    overflow:auto;
    display:flex;
    flex-direction:column;
    gap:10px;
    background:#fff;
  }

  .cc-msg{
    max-width:82%;
    padding:10px 10px;
    border-radius:12px;
    font-size:13px;
    line-height:1.35;
    border:1px solid rgba(0,0,0,.06);
    word-wrap:break-word;
    white-space:pre-wrap;
  }

  .cc-msg-bot{ background:#f6f6f6; align-self:flex-start; }
  .cc-msg-user{ background:#111; color:#fff; align-self:flex-end; border-color:#111; }

  .cc-form{
    display:flex;
    gap:8px;
    padding:10px;
    border-top:1px solid rgba(0,0,0,.08);
    background:#fafafa;
    align-items:flex-end;
  }

  .cc-input-wrap{
    flex:1;
    display:flex;
    flex-direction:column;
    gap:6px;
  }

  .cc-input{
    width:100%;
    border:1px solid rgba(0,0,0,.18);
    border-radius:10px;
    padding:10px 10px;
    font-size:13px;
    outline:none;

    resize:none;
    overflow:hidden;
    min-height:40px;
    max-height:120px;
    line-height:1.25;
  }

  .cc-meta{
    display:flex;
    justify-content:flex-end;
    align-items:center;
    height:14px;
  }

  .cc-counter{
    font-size:11px;
    color:rgba(0,0,0,.55);
  }

  .cc-input.cc-limit{
    border-color: rgba(220, 38, 38, .7);
    box-shadow: 0 0 0 2px rgba(220, 38, 38, .12);
  }

  .cc-counter.cc-limit{
    color: rgba(220, 38, 38, .85);
    font-weight:600;
  }

  .cc-send{
    border:0;
    border-radius:10px;
    padding:10px 12px;
    cursor:pointer;
    background:#111;
    color:#fff;
    font-size:13px;
    height:40px;
  }

  .cc-send:disabled{
    opacity:.55;
    cursor:not-allowed;
  }

  .cc-typing{
    display:inline-flex;
    align-items:center;
    gap:6px;
    height:14px;
  }

  .cc-dot{
    width:6px;
    height:6px;
    border-radius:999px;
    background:rgba(0,0,0,.55);
    display:inline-block;
    animation: cc-bounce 1s infinite ease-in-out;
  }

  .cc-dot:nth-child(2){ animation-delay: 0.15s; }
  .cc-dot:nth-child(3){ animation-delay: 0.30s; }

  @keyframes cc-bounce{
    0%, 80%, 100% { transform: translateY(0); opacity: .45; }
    40% { transform: translateY(-5px); opacity: 1; }
  }

  @media (max-width:420px){
    .cc-panel{ width:calc(100vw - 36px); height:60vh; }
  }
  ";
}

function corner_chat_js() {
  return <<<'JS'
(function(){
  const bubble = document.getElementById('cc-bubble');
  const panel  = document.getElementById('cc-panel');
  const close  = document.getElementById('cc-close');
  const form   = document.getElementById('cc-form');
  const input  = document.getElementById('cc-input');
  const msgs   = document.getElementById('cc-messages');
  const counter = document.getElementById('cc-counter');
  const sendBtn = document.getElementById('cc-send');

  if (!bubble || !panel || !close || !form || !input || !msgs || !counter || !sendBtn) return;

  const STORAGE_KEY = 'cc_chat_state_v1';
  const TEXTAREA_MAX_PX = 120;
  const MAX_CHARS = 500;

  function loadState(){
    try {
      const raw = sessionStorage.getItem(STORAGE_KEY);
      if (!raw) return { interactionsUsed: 0, history: [] };
      const parsed = JSON.parse(raw);
      return {
        interactionsUsed: Number.isFinite(parsed.interactionsUsed) ? parsed.interactionsUsed : 0,
        history: Array.isArray(parsed.history) ? parsed.history : []
      };
    } catch {
      return { interactionsUsed: 0, history: [] };
    }
  }

  function saveState(state){
    try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch {}
  }

  let state = loadState();

  function autoResize(){
    input.style.height = 'auto';
    const h = Math.min(input.scrollHeight, TEXTAREA_MAX_PX);
    input.style.height = h + 'px';
  }

  function enforceMaxLen(){
    if (input.value.length > MAX_CHARS) {
      input.value = input.value.slice(0, MAX_CHARS);
    }

    const remaining = Math.max(0, MAX_CHARS - input.value.length);
    counter.textContent = String(remaining);

    const atLimit = remaining === 0;
    input.classList.toggle('cc-limit', atLimit);
    counter.classList.toggle('cc-limit', atLimit);

    sendBtn.disabled = input.value.trim().length === 0;
  }

  input.addEventListener('input', () => {
    enforceMaxLen();
    autoResize();
  });

  enforceMaxLen();
  autoResize();

  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      if (form.requestSubmit) form.requestSubmit();
      else form.dispatchEvent(new Event('submit', { cancelable: true }));
    }
  });

  function openChat(){
    panel.classList.add('cc-open');
    panel.setAttribute('aria-hidden','false');
    input.focus();
    enforceMaxLen();
    autoResize();
  }
  function closeChat(){
    panel.classList.remove('cc-open');
    panel.setAttribute('aria-hidden','true');
  }
  function toggleChat(){
    panel.classList.contains('cc-open') ? closeChat() : openChat();
  }

  bubble.addEventListener('click', toggleChat);
  bubble.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleChat(); }
  });
  close.addEventListener('click', closeChat);

  document.addEventListener('click', (e) => {
    if (!panel.classList.contains('cc-open')) return;
    if (panel.contains(e.target) || bubble.contains(e.target)) return;
    closeChat();
  });

  function appendMessage(text, who){
    const el = document.createElement('div');
    el.className = 'cc-msg ' + (who === 'user' ? 'cc-msg-user' : 'cc-msg-bot');
    el.textContent = text;
    msgs.appendChild(el);
    msgs.scrollTop = msgs.scrollHeight;
    return el;
  }

  function appendTyping(){
    const el = document.createElement('div');
    el.className = 'cc-msg cc-msg-bot';

    const typing = document.createElement('span');
    typing.className = 'cc-typing';
    typing.innerHTML = '<span class="cc-dot"></span><span class="cc-dot"></span><span class="cc-dot"></span>';

    el.appendChild(typing);
    msgs.appendChild(el);
    msgs.scrollTop = msgs.scrollHeight;
    return el;
  }

  async function sendToBot(text){
    const endpoint = window.CC_CHAT_ENDPOINT || '/wp-json/levinsky/v1/chat';

    const res = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        message: text,
        interactions_used: state.interactionsUsed,
        history: state.history
      })
    });

    if (!res.ok) {
      const raw = await res.text().catch(() => '');
      throw new Error(raw || ('HTTP ' + res.status));
    }

    return await res.json();
  }

  function restoreUIFromState(){
    if (!Array.isArray(state.history) || state.history.length === 0) return;

    const lastPairs = state.history.slice(-2);
    for (const item of lastPairs) {
      if (item && typeof item.q === 'string' && item.q.trim()) appendMessage(item.q.trim(), 'user');
      if (item && typeof item.a === 'string' && item.a.trim()) appendMessage(item.a.trim(), 'bot');
    }
  }

  if (state.history.length > 0) {
    msgs.innerHTML = '';
    restoreUIFromState();
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();

    enforceMaxLen();
    const text = (input.value || '').trim();
    if (!text) return;

    appendMessage(text, 'user');
    input.value = '';
    enforceMaxLen();
    autoResize();

    const typingEl = appendTyping();

    try {
      const data = await sendToBot(text);

      if (Array.isArray(data.history)) state.history = data.history;

      state.interactionsUsed = state.interactionsUsed + 1;
      saveState(state);

      const reply = (data.reply || '').toString().trim() || 'מצטער/ת, לא התקבלה תשובה.';
      typingEl.textContent = reply;
      msgs.scrollTop = msgs.scrollHeight;
    } catch (err) {
      console.error('Chat error:', err);
      typingEl.textContent = 'מצטער/ת, הייתה תקלה בשליחת ההודעה. נסו שוב בעוד רגע.';
      msgs.scrollTop = msgs.scrollHeight;
    }
  });
})();
JS;
}
