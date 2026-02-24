<?php
declare(strict_types=1);

/**
 * ProstoPDR JSON Repair Tool
 * - Repairs invalid questions_export.json that contains multiple concatenated arrays/objects
 * - Extracts all top-level JSON objects {...} (questions) safely (string/escape aware)
 * - Validates/normalizes fields:
 *   id:int, question:string, options:array<string>, correct:int(1-based), explain:string|null, image:string|null
 * - Deduplicates by id (keeps the last occurrence)
 * - Writes pretty JSON (UTF-8) back to file and creates a .bak backup
 *
 * Usage:
 *   cd C:\edkiplatform\web-php
 *   php fix_questions_export.php
 *
 * Optional:
 *   php fix_questions_export.php "C:\edkiplatform\web-php\public\data\questions_export.json"
 */

function fail(string $msg, int $code = 1): void {
    fwrite(STDERR, $msg . PHP_EOL);
    exit($code);
}

function read_file_utf8(string $path): string {
    if (!is_file($path)) fail("File not found: {$path}");
    $raw = file_get_contents($path);
    if ($raw === false) fail("Cannot read file: {$path}");
    // Strip UTF-8 BOM if present
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
    // Normalize line endings a bit (optional)
    return (string)$raw;
}

/**
 * Extracts JSON objects by scanning braces, correctly handling strings and escapes.
 * Returns an array of raw JSON object strings.
 */
function extract_json_objects(string $s): array {
    $objs = [];

    $len = strlen($s);
    $inString = false;
    $escape = false;
    $depth = 0;
    $start = -1;

    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];

        if ($inString) {
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($ch === '\\') {
                $escape = true;
                continue;
            }
            if ($ch === '"') {
                $inString = false;
                continue;
            }
            continue;
        }

        // not in string
        if ($ch === '"') {
            $inString = true;
            $escape = false;
            continue;
        }

        if ($ch === '{') {
            if ($depth === 0) {
                $start = $i;
            }
            $depth++;
            continue;
        }

        if ($ch === '}') {
            if ($depth > 0) $depth--;
            if ($depth === 0 && $start !== -1) {
                $objs[] = substr($s, $start, $i - $start + 1);
                $start = -1;
            }
            continue;
        }
    }

    return $objs;
}

function is_assoc(array $a): bool {
    $k = array_keys($a);
    return $k !== range(0, count($a) - 1);
}

function normalize_question(array $q, array &$warnings): ?array {
    // id
    if (!isset($q['id'])) {
        $warnings[] = "Skip question without id";
        return null;
    }
    $id = (int)$q['id'];
    if ($id <= 0) {
        $warnings[] = "Skip question with invalid id=" . (string)$q['id'];
        return null;
    }

    // question
    $question = isset($q['question']) ? (string)$q['question'] : '';
    $question = trim($question);
    if ($question === '') {
        $warnings[] = "id={$id}: empty question текст";
        // не скипаємо, але попереджаємо
    }

    // options
    $options = $q['options'] ?? [];
    if (!is_array($options)) $options = [];
    // make sure options are strings
    $normOptions = [];
    foreach ($options as $opt) {
        $optStr = trim((string)$opt);
        if ($optStr !== '') $normOptions[] = $optStr;
    }

    // correct
    $correct = (int)($q['correct'] ?? 0);
    if ($correct <= 0 || $correct > max(1, count($normOptions))) {
        // якщо options порожній — correct теж некоректний, але це краще явно побачити
        $warnings[] = "id={$id}: correct={$correct} поза діапазоном (options=" . count($normOptions) . ")";
        // залишимо як є, але якщо options є — підріжемо
        if (count($normOptions) > 0) {
            $correct = min(max($correct, 1), count($normOptions));
        }
    }

    // explain
    $explain = $q['explain'] ?? null;
    if ($explain !== null) {
        $explain = trim((string)$explain);
        if ($explain === '') $explain = null;
    }

    // image
    $image = $q['image'] ?? null;
    if ($image !== null) {
        $image = trim((string)$image);
        if ($image === '') $image = null;
    }
    // normalize image path rule if someone stored full public path
    if (is_string($image) && $image !== '' && str_starts_with($image, 'public/')) {
        $image = '/' . ltrim(substr($image, strlen('public/')), '/');
    }

    return [
        'id' => $id,
        'question' => $question,
        'options' => $normOptions,
        'correct' => $correct,
        'explain' => $explain,
        'image' => $image,
    ];
}

function write_backup(string $path, string $content): string {
    $dir = dirname($path);
    $base = basename($path);
    $stamp = date('Ymd-His');
    $bak = $dir . DIRECTORY_SEPARATOR . $base . ".bak-" . $stamp;
    if (file_put_contents($bak, $content) === false) {
        fail("Cannot write backup: {$bak}");
    }
    return $bak;
}

// --------- main ---------

$defaultPath = __DIR__ . '/public/data/questions_export.json';
$path = $argv[1] ?? $defaultPath;
$path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

$original = read_file_utf8($path);

// Extract all objects
$rawObjects = extract_json_objects($original);
if (count($rawObjects) === 0) {
    fail("Не знайшов жодного JSON-об'єкта {...} у файлі. Перевір шлях: {$path}");
}

// Decode & normalize
$warnings = [];
$byId = []; // dedupe: keep last

$decodedOk = 0;
$decodedBad = 0;

foreach ($rawObjects as $objStr) {
    $arr = json_decode($objStr, true);
    if (!is_array($arr) || !is_assoc($arr)) {
        $decodedBad++;
        continue;
    }
    $decodedOk++;

    $norm = normalize_question($arr, $warnings);
    if ($norm === null) continue;

    $byId[(int)$norm['id']] = $norm; // keep last
}

if (count($byId) === 0) {
    fail("Знайшов об'єкти, але жодне питання не пройшло валідацію (id/question/options).");
}

// Sort by id
ksort($byId);
$final = array_values($byId);

// Validate final JSON
$out = json_encode(
    $final,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
if ($out === false) {
    fail("json_encode failed: " . json_last_error_msg());
}

// Backup and write
$bakPath = write_backup($path, $original);
if (file_put_contents($path, $out . PHP_EOL) === false) {
    fail("Cannot write repaired file: {$path}");
}

// Report
echo "✅ Repaired: {$path}" . PHP_EOL;
echo "✅ Backup:   {$bakPath}" . PHP_EOL;
echo "Objects found: " . count($rawObjects) . PHP_EOL;
echo "Decoded OK:    {$decodedOk}" . PHP_EOL;
echo "Decoded Bad:   {$decodedBad}" . PHP_EOL;
echo "Questions out: " . count($final) . PHP_EOL;

if (!empty($warnings)) {
    echo PHP_EOL . "⚠️ Warnings (first 30):" . PHP_EOL;
    $i = 0;
    foreach ($warnings as $w) {
        $i++;
        echo " - {$w}" . PHP_EOL;
        if ($i >= 30) break;
    }
}