<?php
// public/admin/upload_answers.php
// CSV формат (UTF-8):
// topic_slug;qnum;correct_key
// Напр: dorozhni-znaky;15;3
//
// Або якщо topic_slug порожній, то оновлюємо перший збіг qnum (не рекомендується).

declare(strict_types=1);

require_once __DIR__ . '/../../src/db.php';

$pdo = db();
$pdo->exec("SET NAMES utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_FILES['csv']['tmp_name'])) die("No file");
  $csv = file($_FILES['csv']['tmp_name'], FILE_IGNORE_NEW_LINES);
  $upd = $pdo->prepare("
    UPDATE questions q
    JOIN topics t ON t.id = q.topic_id
    SET q.correct_key = ?
    WHERE t.slug = ? AND q.qnum = ?
    LIMIT 1
  ");

  $updLoose = $pdo->prepare("UPDATE questions SET correct_key = ? WHERE qnum = ? LIMIT 1");

  $ok=0; $skip=0;
  foreach ($csv as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) continue;
    $parts = preg_split('/[;,]/', $line);
    $parts = array_map('trim', $parts);

    if (count($parts) < 3) { $skip++; continue; }
    [$slug, $qnum, $ckey] = $parts;

    $qnum = (int)$qnum;
    $ckey = (int)$ckey;
    if ($ckey < 1 || $ckey > 4 || $qnum <= 0) { $skip++; continue; }

    if ($slug !== '') {
      $upd->execute([$ckey, $slug, $qnum]);
      $ok += $upd->rowCount() ? 1 : 0;
    } else {
      $updLoose->execute([$ckey, $qnum]);
      $ok += $updLoose->rowCount() ? 1 : 0;
    }
  }

  echo "OK updated: $ok, skipped: $skip";
  exit;
}
?>
<!doctype html>
<html lang="uk">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Upload answers</title></head>
<body style="font-family:system-ui;padding:24px;">
  <h2>Залити ключ відповідей (CSV)</h2>
  <form method="post" enctype="multipart/form-data">
    <input type="file" name="csv" accept=".csv,text/csv">
    <button type="submit">Upload</button>
  </form>
  <p>Формат рядка: <code>topic_slug;qnum;correct_key</code></p>
</body></html>
