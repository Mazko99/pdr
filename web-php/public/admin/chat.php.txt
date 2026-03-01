<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../src/users_store.php';
require_once __DIR__ . '/../../src/chat_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$threads = chat_threads_list();

$selectedUid = (string)($_GET['uid'] ?? '');
if ($selectedUid === '' && !empty($threads)) {
  $selectedUid = (string)($threads[0]['user_id'] ?? '');
}

$selected = $selectedUid !== '' ? chat_thread_get($selectedUid) : null;

if ($selectedUid !== '') {
  chat_mark_read_for($selectedUid, 'admin');
}

$u = $selectedUid !== '' ? user_find_by_id($selectedUid) : null;
$meta = is_array($selected) ? (array)($selected['meta'] ?? []) : [];

$isGuestThread = ($selectedUid !== '' && strpos($selectedUid, 'g_') === 0);

$uName = is_array($u) ? (string)($u['name'] ?? '') : (string)($meta['name'] ?? '');
$uEmail = is_array($u) ? (string)($u['email'] ?? '') : (string)($meta['email'] ?? '');

if ($uName === '' && $isGuestThread) $uName = 'Гість';

?><!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Адмінка — Чати</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800;900&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/style.css?v=4" />

  <style>
    .adm-wrap{max-width:1200px;margin:0 auto;padding:16px;}
    .grid{display:grid;grid-template-columns:360px 1fr;gap:14px;}
    @media (max-width: 980px){ .grid{grid-template-columns:1fr;} }

    .card{background:#fff;border-radius:18px;border:1px solid rgba(11,27,20,.12);box-shadow:var(--shadow);overflow:hidden;}
    .list{max-height: calc(100vh - 220px); overflow:auto;}
    .item{display:block;padding:12px 14px;border-bottom:1px solid rgba(11,27,20,.06);text-decoration:none;color:inherit;}
    .item.is-active{background:rgba(10,122,61,.08);}
    .head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;}
    .muted{opacity:.7;font-weight:800;font-size:12px;}
    .badge{display:inline-flex;min-width:22px;height:22px;padding:0 8px;border-radius:999px;background:#0a7a3d;color:#fff;align-items:center;justify-content:center;font-weight:900;font-size:12px;}
    .chat{display:flex;flex-direction:column;height: calc(100vh - 220px);}
    .msgs{flex:1 1 auto;padding:14px;overflow:auto;background:linear-gradient(180deg,#0b1b14 0%,#0f2a1f 100%);}
    .bubble{max-width:72%;padding:10px 12px;border-radius:14px;margin:8px 0;color:#fff;font-weight:750;line-height:1.35;word-break:break-word;}
    .from-admin{margin-left:auto;background:#0a7a3d;}
    .from-user{margin-right:auto;background:rgba(255,255,255,.14);}
    .meta{font-size:11px;opacity:.75;margin-top:4px;}
    .send{display:flex;gap:10px;padding:12px;background:#fff;border-top:1px solid rgba(11,27,20,.08);}
    .input{flex:1 1 auto;padding:12px 12px;border-radius:14px;border:1px solid rgba(11,27,20,.18);font-weight:900;outline:none;}
  </style>
</head>
<body>

<header class="header">
  <div class="container header__inner">
    <a class="brand" href="/admin/users.php" aria-label="Адмінка">
      <img class="brand__logo" src="/assets/img/logo.svg" alt="ProstoPDR" />
    </a>
    <div class="header__actions">
      <a class="btn btn--ghost" href="/admin/users.php">← Користувачі</a>
    </div>
  </div>
</header>

<main class="section section--soft" style="padding-top:16px;">
  <div class="container adm-wrap">
    <div class="account-card" style="margin-bottom:12px;">
      <div class="h2" style="margin:0;">Чати підтримки</div>
      <div class="lead" style="margin:6px 0 0;">Тут видно всі діалоги: і гості, і зареєстровані.</div>
    </div>

    <div class="grid">
      <div class="card">
        <div style="padding:12px 14px;font-weight:900;border-bottom:1px solid rgba(11,27,20,.06);">Діалоги</div>
        <div class="list">
          <?php if (empty($threads)): ?>
            <div class="item"><div class="muted">Ще немає повідомлень.</div></div>
          <?php else: ?>
            <?php foreach ($threads as $t):
              $uid = (string)($t['user_id'] ?? '');
              $uu = user_find_by_id($uid);
              $m = (array)($t['meta'] ?? []);
              $isGuest = (strpos($uid, 'g_') === 0);

              if (is_array($uu)) {
                $nm = (string)($uu['name'] ?? '');
                $em = (string)($uu['email'] ?? '');
              } else {
                $nm = (string)($m['name'] ?? '');
                $em = (string)($m['email'] ?? '');
                if ($nm === '' && $isGuest) $nm = 'Гість';
              }

              $unread = (int)($t['admin_unread'] ?? 0);
            ?>
              <a class="item <?= $uid===$selectedUid?'is-active':''; ?>" href="/admin/chat.php?uid=<?= urlencode($uid) ?>">
                <div class="head">
                  <div style="font-weight:900;"><?= h($nm !== '' ? $nm : ('User #' . $uid)) ?></div>
                  <?php if ($unread > 0): ?><div class="badge"><?= (int)$unread ?></div><?php endif; ?>
                </div>
                <div class="muted"><?= h($em) ?></div>
                <div class="muted">ID: <?= h($uid) ?> • <?= h((string)($t['updated_at'] ?? '')) ?></div>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <?php if (!$selected): ?>
          <div style="padding:14px;" class="muted">Обери діалог зліва.</div>
        <?php else: ?>
          <div style="padding:12px 14px;border-bottom:1px solid rgba(11,27,20,.06);">
            <div style="font-weight:900;"><?= h($uName !== '' ? $uName : ('User #' . $selectedUid)) ?></div>
            <div class="muted"><?= h($uEmail) ?> • ID: <?= h($selectedUid) ?></div>
          </div>

          <div class="chat" data-uid="<?= h($selectedUid) ?>">
            <div class="msgs" id="msgs">
              <?php foreach (($selected['messages'] ?? []) as $m):
                $from = (string)($m['from'] ?? 'user');
                $cls = $from === 'admin' ? 'from-admin' : 'from-user';
              ?>
                <div class="bubble <?= $cls ?>">
                  <?= nl2br(h((string)($m['text'] ?? ''))) ?>
                  <div class="meta"><?= h((string)($m['ts'] ?? '')) ?></div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="send">
              <input class="input" id="text" placeholder="Відповідь..." />
              <button class="btn btn--primary" id="send">Надіслати</button>
            </div>
          </div>

          <script>
          (function(){
            const uid = document.querySelector('[data-uid]')?.getAttribute('data-uid') || '';
            const msgs = document.getElementById('msgs');
            const input = document.getElementById('text');
            const btn = document.getElementById('send');

            function scrollDown(){ msgs.scrollTop = msgs.scrollHeight; }
            scrollDown();

            async function send(){
              const text = (input.value || '').trim();
              if(!text) return;
              btn.disabled = true;

              const fd = new FormData();
              fd.append('uid', uid);
              fd.append('text', text);

              const res = await fetch('/admin/chat_api.php?action=send', { method:'POST', body: fd });
              const js = await res.json().catch(()=>null);

              btn.disabled = false;
              if(!js || !js.ok) return;

              input.value = '';
              await refresh();
            }

            function esc(s){
              return (s||'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');
            }

            async function refresh(){
              const res = await fetch('/admin/chat_api.php?action=thread&uid=' + encodeURIComponent(uid), { method:'GET' });
              const js = await res.json().catch(()=>null);
              if(!js || !js.ok) return;

              msgs.innerHTML = '';
              (js.thread.messages || []).forEach(m=>{
                const div = document.createElement('div');
                div.className = 'bubble ' + (m.from === 'admin' ? 'from-admin' : 'from-user');
                div.innerHTML = esc(m.text || '').replaceAll('\n','<br>') + '<div class="meta">' + esc(m.ts || '') + '</div>';
                msgs.appendChild(div);
              });
              scrollDown();
            }

            btn.addEventListener('click', send);
            input.addEventListener('keydown', (e)=>{ if(e.key==='Enter'){ e.preventDefault(); send(); } });

            setInterval(refresh, 2500);
          })();
          </script>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>

</body>
</html>