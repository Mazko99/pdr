<?php
// public/tests/submit.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/auth.php';
require_once __DIR__ . '/../../src/tests_repo.php';

$user = require_login();

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) { echo json_encode(['ok'=>false,'error'=>'Bad JSON']); exit; }

$attemptId = (int)($data['attempt_id'] ?? 0);
$spentSec  = (int)($data['spent_sec'] ?? 0);
$answers   = (array)($data['answers'] ?? []);

if (!$attemptId) { echo json_encode(['ok'=>false,'error'=>'No attempt_id']); exit; }

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$skey = 'attempt_'.$attemptId;
$meta = $_SESSION[$skey] ?? null;

if (!$meta || (int)$meta['test_id'] <= 0) {
  echo json_encode(['ok'=>false,'error'=>'Attempt session not found']); exit;
}

$maxMistakes = (int)$meta['max_mistakes'];
$qids = array_map('intval', (array)$meta['question_ids']);

$correct = 0;
$wrong = 0;

// Перевіряємо тільки питання, які реально були в цій спробі
foreach ($qids as $qid) {
  $chosen = array_key_exists((string)$qid, $answers) ? $answers[(string)$qid] : (array_key_exists($qid, $answers) ? $answers[$qid] : null);
  $chosenKey = is_null($chosen) ? null : (int)$chosen;

  $ckey = get_correct_key($qid);

  if ($ckey === null || $chosenKey === null) {
    // ключа ще нема або користувач пропустив — просто збережемо без оцінки
    save_attempt_answer($attemptId, $qid, $chosenKey, null);
    continue;
  }

  $isCorrect = ($chosenKey === $ckey);
  save_attempt_answer($attemptId, $qid, $chosenKey, $isCorrect);

  if ($isCorrect) $correct++;
  else $wrong++;
}

// Якщо ключів ще немає — passed=false (щоб не вводити в оману)
$hasAllKeys = true;
foreach ($qids as $qid){
  if (get_correct_key($qid) === null) { $hasAllKeys = false; break; }
}

$passed = $hasAllKeys ? ($wrong <= $maxMistakes) : false;

finish_attempt($attemptId, $spentSec, $correct, $wrong, $passed);

// чистимо сесію щоб не повторно
unset($_SESSION[$skey]);

echo json_encode(['ok'=>true,'correct'=>$correct,'wrong'=>$wrong,'passed'=>$passed,'has_keys'=>$hasAllKeys], JSON_UNESCAPED_UNICODE);
