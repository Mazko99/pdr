<?php
declare(strict_types=1);

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../src/users_store.php';
require_once __DIR__ . '/../../src/chat_store.php';

if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$threads = chat_threads_list();
$selectedUid = (string)($_GET['uid'] ?? '');
if ($selectedUid === '' && !empty($threads)) $selectedUid = (string)($threads[0]['user_id'] ?? '');

$selected = $selectedUid !== '' ? chat_thread_get($selectedUid) : null;

// якщо відкрили конкретний чат — прочитано адміном
if ($selectedUid !== '') {
  chat_mark_read_for($selectedUid, 'admin');
}

$u = $selectedUid !== '' ? user_find_by_id($selectedUid) : null;
$uName = is_array($u) ? (string)($u['name'] ?? '') : '';
$uEmail = is_array($u) ? (string)($u['email'] ?? '') : '';

?><!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Адмінка — Чати</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial; margin:0; background:#f6f7f7; color:#0b1b14;}
    a{color:inherit; text-decoration:none;}
    .top{display:flex; align-items:center; justify-content:space-between; padding:16px 18px; background:#fff; border-bottom:1px solid rgba(11,27,20,.08);}
    .top .nav{display:flex; gap:10px; align-items:center;}
    .btn{display:inline-flex; align-items:center; justify-content:center; padding:10px 14px; border-radius:12px; background:#0a7a3d; color:#fff; font-weight:800; border:0; cursor:pointer;}
    .btn--ghost{background:#fff; color:#0b1b14; border:1px solid rgba(11,27,20,.12);}
    .wrap{display:grid; grid-template-columns:360px 1fr; gap:14px; padding:14px;}
    .card{background:#fff; border-radius:14px; border:1px solid rgba(11,27,20,.08); overflow:hidden;}
    .list{max-height: calc(100vh - 120px); overflow:auto;}
    .item{padding:12px 14px; border-bottom:1px solid rgba(11,27,20,.06);}
    .item.is-active{background:rgba(10,122,61,.08);}
    .item-head{display:flex; justify-content:space-between; gap:10px;}
    .muted{opacity:.65; font-weight:700; font-size:12px;}
    .badge{display:inline-flex; min-width:22px; height:22px; padding:0 8px; border-radius:999px; background:#0a7a3d; color:#fff; align-items:center; justify-content:center; font-weight:900; font-size:12px;}
    .chat{display:flex; flex-direction:column; height: calc(100vh - 120px);}
    .msgs{flex:1 1 auto; padding:14px; overflow:auto; background:linear-gradient(180deg, #0b1b14 0%, #0f2a1f 100%);}
    .bubble{max-width:72%; padding:10px 12px; border-radius:14px; margin:8px 0; color:#fff; font-weight:700; line-height:1.35;}
    .from-admin{margin-left:auto; background:#0a7a3d;}
    .from-user{margin-right:auto; background:rgba(255,255,255,.14);}
    .meta{font-size:11px; opacity:.75; margin-top:4px;}
    .send{display:flex; gap:10px; padding:12px; background:#fff; border-top:1px solid rgba(11,27,20,.08);}
    .input{flex:1 1 auto; padding:12px 12px; border-radius:12px; border:1px solid rgba(11,27,20,.18); font-weight:800;}
  </style>
</head>
<body>

<div class="top">
  <div class="nav">
    <a class="btn btn--ghost" href="/admin/users.php">← Користувачі</a>
    <div style="font-weight:900;">Чати підтримки</div>
  </div>
  <div class="muted">Відкрив чат → непрочитане скидається</div>
</div>

<div class="wrap">
  <div class="card">
    <div style="padding:12px 14px; font-weight:900; border-bottom:1px solid rgba(11,27,20,.06);">Діалоги</div>
    <div class="list">
      <?php if (empty($threads)): ?>
        <div class="item"><div class="muted">Ще немає повідомлень.</div></div>
      <?php else: ?>
        <?php foreach ($threads as $t): 
          $uid = (string)($t['user_id'] ?? '');
          $uu = user_find_by_id($uid);
          $nm = is_array($uu) ? (string)($uu['name'] ?? '') : '';
          $em = is_array($uu) ? (string)($uu['email'] ?? '') : '';
          $unread = (int)($t['admin_unread'] ?? 0);
        ?>
          <a class="item <?= $uid===$selectedUid?'is-active':''; ?>" href="/admin/chat.php?uid=<?= urlencode($uid) ?>">
            <div class="item-head">
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
      <div style="padding:12px 14px; border-bottom:1px solid rgba(11,27,20,.06);">
        <div style="font-weight:900;"><?= h($uName !== '' ? $uName : ('User #' . $selectedUid)) ?></div>
        <div class="muted"><?= h($uEmail) ?> • ID: <?= h($selectedUid) ?></div>
        <div style="margin-top:10px; display:flex; gap:10px; flex-wrap:wrap;">
          <form method="post" action="/admin/user.php?id=<?= urlencode($selectedUid) ?>" style="display:inline;">
            <input type="hidden" name="csrf" value="<?= h((string)($_SESSION['admin_csrf'] ?? '')) ?>">
            <input type="hidden" name="action" value="reset_sessions">
            <button class="btn btn--ghost" type="submit">Скинути сесії користувача</button>
          </form>
          <a class="btn btn--ghost" href="/admin/user.php?id=<?= urlencode($selectedUid) ?>">Відкрити профіль</a>
        </div>
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
          <button class="btn" id="send">Надіслати</button>
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

        function lastId(){
          const bubbles = msgs.querySelectorAll('.bubble');
          // у нас id не в DOM, тому просто опираємось на опитування всього треду раз на 2-3 сек
          return 0;
        }

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

        async function refresh(){
          const res = await fetch('/admin/chat_api.php?action=thread&uid=' + encodeURIComponent(uid), { method:'GET' });
          const js = await res.json().catch(()=>null);
          if(!js || !js.ok) return;

          msgs.innerHTML = '';
          (js.thread.messages || []).forEach(m=>{
            const div = document.createElement('div');
            div.className = 'bubble ' + (m.from === 'admin' ? 'from-admin' : 'from-user');
            div.innerHTML = (m.text || '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('\n','<br>')
              + '<div class="meta">' + (m.ts || '') + '</div>';
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

</body>
</html>