<?php
declare(strict_types=1);

$MAX_ID = 1856;

$root = dirname(__DIR__); // web-php
$in  = $root . '/public/data/tests_export.json';
$out = $root . '/public/data/tests_export_trimmed.json';

if (!is_file($in)) {
  fwrite(STDERR, "NOT FOUND: $in\n");
  exit(1);
}

$tests = json_decode((string)file_get_contents($in), true);
if (!is_array($tests)) {
  fwrite(STDERR, "Invalid JSON in $in\n");
  exit(1);
}

$kept = [];
$removedTests = 0;
$removedIds = 0;

foreach ($tests as $t) {
  if (!is_array($t)) continue;

  $ids = $t['question_ids'] ?? [];
  if (!is_array($ids)) $ids = [];

  $newIds = [];
  foreach ($ids as $id) {
    $id = (int)$id;
    if ($id <= 0) continue;
    if ($id <= $MAX_ID) $newIds[] = $id;
    else $removedIds++;
  }

  $t['question_ids'] = array_values($newIds);

  // якщо test/exam без питань — прибираємо
  if (count($t['question_ids']) === 0) {
    $removedTests++;
    continue;
  }

  $kept[] = $t;
}

file_put_contents(
  $out,
  json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

echo "OK\n";
echo "Saved: $out\n";
echo "Removed question_ids > $MAX_ID: $removedIds\n";
echo "Removed empty tests: $removedTests\n";
echo "Kept tests: " . count($kept) . "\n";