<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}
?>
<style>
/* ===== Chat widget (ProstoPDR) ===== */
.ppdr-chat-fab{
  position: fixed;
  right: 16px;
  bottom: 16px;
  z-index: 9999;

  width: 60px;
  height: 60px;

  border-radius: 999px;
  border: 0;
  cursor: pointer;

  display: flex;
  align-items: center;
  justify-content: center;

  background: #0a7a3d;
  color: #fff;

  box-shadow: 0 18px 40px rgba(0,0,0,.22);
  transition: transform .15s ease, box-shadow .15s ease;
}
.ppdr-chat-fab:hover{ transform: translateY(-2px); box-shadow: 0 22px 50px rgba(0,0,0,.28); }
.ppdr-chat-fab:active{ transform: translateY(0); }

.ppdr-chat-overlay{
  position: fixed;
  inset: 0;
  z-index: 9998;
  background: rgba(0,0,0,.35);
  backdrop-filter: blur(2px);
  display: none;
}

.ppdr-chat{
  position: fixed;
  right: 16px;
  bottom: 88px;
  z-index: 9999;
  width: 380px;
  max-width: calc(100vw - 24px);
  height: 560px;
  max-height: calc(100vh - 120px);
  background: #fff;
  border-radius: 18px;
  overflow: hidden;
  border: 1px solid rgba(11,27,20,.12);
  box-shadow: 0 20px 60px rgba(0,0,0,.25);
  display: none;
}

.ppdr-chat__head{
  padding: 12px 12px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  border-bottom: 1px solid rgba(11,27,20,.08);
  background:
    radial-gradient(520px 220px at 70% 30%, rgba(22,163,74,.18), transparent 60%),
    linear-gradient(180deg, rgba(14,122,67,.08), rgba(255,255,255, .00));
}
.ppdr-chat__title{
  font-weight: 900;
  font-size: 14px;
  color:#0b1b14;
  display:flex;
  flex-direction:column;
  line-height:1.2;
}
.ppdr-chat__sub{
  font-weight: 800;
  font-size: 12px;
  opacity: .65;
}
.ppdr-chat__close{
  border: 1px solid rgba(11,27,20,.14);
  background:#fff;
  border-radius: 12px;
  padding: 8px 10px;
  cursor:pointer;
  font-weight: 900;
}

.ppdr-chat__body{
  height: calc(100% - 58px);
  display:flex;
  flex-direction:column;
  background: linear-gradient(180deg, #0b1b14 0%, #0f2a1f 100%);
}

.ppdr-chat__msgs{
  flex: 1 1 auto;
  overflow:auto;
  padding: 12px;
}

.ppdr-bubble{
  max-width: 82%;
  padding: 10px 12px;
  border-radius: 14px;
  margin: 8px 0;
  color:#fff;
  font-weight: 750;
  line-height: 1.35;
  word-wrap: break-word;
  white-space: pre-wrap;
}
.ppdr-bubble--admin{
  margin-left:auto;
  background:#0a7a3d;
}
.ppdr-bubble--user{
  margin-right:auto;
  background: rgba(255,255,255,.14);
}
.ppdr-bubble__meta{
  margin-top: 4px;
  font-size: 11px;
  opacity: .75;
  font-weight: 700;
}

.ppdr-chat__foot{
  padding: 10px;
  border-top: 1px solid rgba(255,255,255,.08);
  background: rgba(255,255,255,.06);
}

.ppdr-chat__row{
  display:flex;
  gap:10px;
  margin-bottom: 8px;
}
.ppdr-chat__row:last-child{ margin-bottom:0; }

.ppdr-input{
  width: 100%;
  padding: 11px 12px;
  border-radius: 14px;
  border: 1px solid rgba(255,255,255,.18);
  background: rgba(255,255,255,.10);
  color:#fff;
  outline: none;
  font-weight: 800;
  min-width: 0;
}
.ppdr-input::placeholder{ color: rgba(255,255,255,.65); }
.ppdr-input:focus{ border-color: rgba(34,197,94,.55); box-shadow: 0 0 0 4px rgba(34,197,94,.12); }

.ppdr-send{
  display:flex;
  gap:10px;
  align-items:center;
}
.ppdr-send__text{ flex: 1 1 auto; }
.ppdr-btn{
  border: 0;
  border-radius: 14px;
  padding: 11px 14px;
  cursor:pointer;
  font-weight: 900;
  background: #0a7a3d;
  color:#fff;
  white-space: nowrap;
}
.ppdr-btn[disabled]{ opacity:.55; cursor:not-allowed; }

@media (max-width: 560px){
  .ppdr-chat{
    right: 10px;
    left: 10px;
    width: auto;
    bottom: 10px;
    height: 78vh;
    max-height: 78vh;
    border-radius: 18px;
  }
  .ppdr-chat-fab{
    right: 12px;
    bottom: 12px;
  }
  .ppdr-chat{
    padding-bottom: env(safe-area-inset-bottom);
  }
}
</style>

<!-- ✅ FAB button (без напису "чат") -->
<button class="ppdr-chat-fab" type="button" id="ppdrChatFab" aria-label="Чат підтримки">
  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <path d="M4 4H20V16H8L4 20V4Z" stroke="white" stroke-width="2" stroke-linejoin="round"/>
  </svg>
</button>

<div class="ppdr-chat-overlay" id="ppdrChatOverlay"></div>

<div class="ppdr-chat" id="ppdrChat">
  <div class="ppdr-chat__head">
    <div class="ppdr-chat__title">
      Підтримка ProstoPDR
      <div class="ppdr-chat__sub" id="ppdrChatSub">Напиши питання — відповімо</div>
    </div>
    <button class="ppdr-chat__close" type="button" id="ppdrChatClose">✕</button>
  </div>

  <div class="ppdr-chat__body">
    <div class="ppdr-chat__msgs" id="ppdrChatMsgs"></div>

    <div class="ppdr-chat__foot">
      <div class="ppdr-chat__row" id="ppdrGuestRow" style="display:none;">
        <input class="ppdr-input" id="ppdrName" placeholder="Ім’я (необов’язково)">
        <input class="ppdr-input" id="ppdrEmail" placeholder="Email (необов’язково)">
      </div>

      <div class="ppdr-send">
        <input class="ppdr-input ppdr-send__text" id="ppdrText" placeholder="Напиши повідомлення...">
        <button class="ppdr-btn" type="button" id="ppdrSend">Надіслати</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const fab = document.getElementById('ppdrChatFab');
  const overlay = document.getElementById('ppdrChatOverlay');
  const box = document.getElementById('ppdrChat');
  const close = document.getElementById('ppdrChatClose');
  const msgs = document.getElementById('ppdrChatMsgs');
  const sendBtn = document.getElementById('ppdrSend');
  const textEl = document.getElementById('ppdrText');
  const guestRow = document.getElementById('ppdrGuestRow');
  const nameEl = document.getElementById('ppdrName');
  const emailEl = document.getElementById('ppdrEmail');
  const sub = document.getElementById('ppdrChatSub');

  let isOpen = false;
  let csrf = '';
  let isAuth = false;

  function setOpen(v){
    isOpen = !!v;
    box.style.display = isOpen ? 'block' : 'none';
    overlay.style.display = isOpen ? 'block' : 'none';
    if(isOpen){
      init().then(refresh);
      setTimeout(()=>textEl.focus(), 50);
    }
  }

  function esc(s){
    return (s||'')
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }

  function scrollDown(){ msgs.scrollTop = msgs.scrollHeight; }

  function renderThread(thread){
    msgs.innerHTML = '';
    (thread.messages || []).forEach(m=>{
      const div = document.createElement('div');
      div.className = 'ppdr-bubble ' + (m.from === 'admin' ? 'ppdr-bubble--admin' : 'ppdr-bubble--user');
      div.innerHTML = esc(m.text || '') + '<div class="ppdr-bubble__meta">' + esc(m.ts || '') + '</div>';
      msgs.appendChild(div);
    });
    scrollDown();
  }

  async function init(){
    const res = await fetch('/chat_api.php?action=init', { method:'GET' });
    const js = await res.json().catch(()=>null);
    if(!js || !js.ok) return;

    csrf = js.csrf || '';
    isAuth = !!js.is_auth;

    guestRow.style.display = isAuth ? 'none' : '';
    sub.textContent = isAuth ? 'Ви авторизовані — ім’я підтягнеться автоматично' : 'Можна писати як гість';
  }

  async function refresh(){
    const res = await fetch('/chat_api.php?action=fetch', { method:'GET' });
    const js = await res.json().catch(()=>null);
    if(!js || !js.ok) return;
    renderThread(js.thread || {messages:[]});
  }

  async function send(){
    const text = (textEl.value || '').trim();
    if(!text) return;

    sendBtn.disabled = true;

    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('text', text);

    if(!isAuth){
      fd.append('name', (nameEl.value || '').trim());
      fd.append('email', (emailEl.value || '').trim());
    }

    const res = await fetch('/chat_api.php?action=send', { method:'POST', body: fd });
    const js = await res.json().catch(()=>null);

    sendBtn.disabled = false;

    if(!js || !js.ok){
      if(js && js.error === 'csrf'){
        await init();
      }
      return;
    }

    textEl.value = '';
    await refresh();
  }

  fab.addEventListener('click', ()=>setOpen(true));
  overlay.addEventListener('click', ()=>setOpen(false));
  close.addEventListener('click', ()=>setOpen(false));

  sendBtn.addEventListener('click', send);
  textEl.addEventListener('keydown', (e)=>{
    if(e.key === 'Enter'){
      e.preventDefault();
      send();
    }
  });

  document.addEventListener('keydown', (e)=>{
    if(e.key === 'Escape') setOpen(false);
  });

  setInterval(()=>{ if(isOpen) refresh(); }, 2500);
})();
</script>