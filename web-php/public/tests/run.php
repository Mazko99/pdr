<?php
// public/tests/run.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/tests_repo.php';

$user = require_login();
if (!has_active_subscription($user)) { header('Location: /tests/'); exit; }

$testId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$test = $testId ? get_test($testId) : null;
if (!$test) { http_response_code(404); die("Test not found"); }

$questions = get_questions_for_test($test);

// Створюємо спробу
$attemptId = create_attempt((int)$user['id'], (int)$test['id']);

// Збережемо питання в сесію, щоб у submit.php перевірити їх порядок/належність.
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SESSION['attempt_'.$attemptId] = [
  'test_id' => (int)$test['id'],
  'topic_id' => (int)$test['topic_id'],
  'time_limit_sec' => (int)$test['time_limit_sec'],
  'max_mistakes' => (int)$test['max_mistakes'],
  'question_ids' => array_map(fn($q)=> (int)$q['id'], $questions),
  'started_ts' => time(),
];

?>
<!doctype html>
<html lang="uk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="/style.css">
  <style><?php echo file_get_contents(__DIR__ . '/../../public/quiz_additions.css'); ?></style>
  <title><?php echo htmlspecialchars($test['title']); ?></title>
</head>
<body>
  <div class="container" style="padding:26px 0 50px;">
    <div class="quiz-shell" id="quiz" data-attempt-id="<?php echo (int)$attemptId; ?>"
         data-time-limit="<?php echo (int)$test['time_limit_sec']; ?>"
         data-max-mistakes="<?php echo (int)$test['max_mistakes']; ?>">
      <div class="quiz-head">
        <h1 class="quiz-title"><?php echo htmlspecialchars($test['topic_title']); ?> · <?php echo htmlspecialchars($test['title']); ?></h1>
        <div class="quiz-meta">
          <span class="pill">⏱ <span id="tleft"></span></span>
          <span class="pill">Питання: <span id="qpos"></span>/<span id="qtotal"></span></span>
          <span class="pill">Помилки: <span id="wrong">0</span>/<span id="maxwrong"><?php echo (int)$test['max_mistakes']; ?></span></span>
        </div>
      </div>

      <div class="quiz-q">
        <div class="quiz-q__text" id="qtext"></div>
        <div class="quiz-q__img" id="qimgwrap" style="display:none;"><img id="qimg" alt=""></div>
      </div>

      <div class="quiz-opts" id="opts"></div>

      <div class="quiz-foot">
        <div class="quiz-progress" id="status"></div>
        <button class="btn btn--ghost" id="skip" type="button">Пропустити</button>
      </div>
    </div>
  </div>

<script>
(() => {
  const data = <?php echo json_encode($questions, JSON_UNESCAPED_UNICODE); ?>;

  const attemptId = parseInt(document.getElementById('quiz').dataset.attemptId, 10);
  const timeLimit = parseInt(document.getElementById('quiz').dataset.timeLimit, 10);
  const maxMistakes = parseInt(document.getElementById('quiz').dataset.maxMistakes, 10);

  let idx = 0;
  let wrong = 0;
  const answers = {}; // questionId -> chosenKey|null
  const startedAt = Date.now();

  const elQText = document.getElementById('qtext');
  const elOpts = document.getElementById('opts');
  const elTLeft = document.getElementById('tleft');
  const elQPos = document.getElementById('qpos');
  const elQTotal = document.getElementById('qtotal');
  const elWrong = document.getElementById('wrong');
  const elStatus = document.getElementById('status');

  const imgWrap = document.getElementById('qimgwrap');
  const imgEl = document.getElementById('qimg');

  elQTotal.textContent = data.length;
  document.getElementById('maxwrong').textContent = maxMistakes;

  function fmt(sec){
    const m = Math.floor(sec/60);
    const s = sec%60;
    return String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
  }

  function render(){
    const q = data[idx];
    elQPos.textContent = (idx+1);
    elWrong.textContent = wrong;

    elQText.textContent = q.text;

    // image (беремо першу)
    if (q.images && q.images.length){
      imgWrap.style.display = 'block';
      imgEl.src = '/assets/' + q.images[0];
    } else {
      imgWrap.style.display = 'none';
      imgEl.src = '';
    }

    elOpts.innerHTML = '';
    const cls = ['opt--a','opt--b','opt--c','opt--d'];

    q.options.forEach((o, i) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'opt ' + cls[i];
      b.textContent = o.text;
      b.onclick = () => choose(o.opt_key ?? o.opt_key === 0 ? o.opt_key : o.opt_key, o.opt_key);
      // our options from PHP: {opt_key,text}
      b.onclick = () => choose(o.opt_key);
      elOpts.appendChild(b);
    });

    elStatus.textContent = '';
  }

  function choose(key){
    const q = data[idx];
    answers[q.id] = key;

    // На цьому етапі ми НЕ можемо перевірити правильність, доки correct_key не заповнений у БД.
    // Але: контроль помилок працює після появи ключів. Поки ключів нема — просто фіксуємо відповіді.
    idx++;
    if (idx >= data.length) return finish();
    render();
  }

  function skip(){
    const q = data[idx];
    if (!(q.id in answers)) answers[q.id] = null;
    idx++;
    if (idx >= data.length) return finish();
    render();
  }

  async function finish(){
    const spentSec = Math.floor((Date.now() - startedAt)/1000);
    const payload = { attempt_id: attemptId, spent_sec: spentSec, answers };
    const res = await fetch('/tests/submit.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    const out = await res.json();
    if (!out.ok){
      alert(out.error || 'Помилка збереження');
      return;
    }
    window.location.href = '/tests/result.php?id=' + attemptId;
  }

  // timer
  const deadline = Date.now() + timeLimit*1000;
  const timer = setInterval(() => {
    const sec = Math.max(0, Math.floor((deadline - Date.now())/1000));
    elTLeft.textContent = fmt(sec);
    if (sec <= 0){
      clearInterval(timer);
      finish();
    }
  }, 250);

  document.getElementById('skip').addEventListener('click', skip);

  render();
})();
</script>
</body>
</html>
