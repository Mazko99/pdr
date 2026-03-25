<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function ref_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (function_exists('pdoi')) {
        $pdo = pdoi();
        return $pdo;
    }

    if (function_exists('pdo')) {
        $pdo = pdo();
        return $pdo;
    }

    if (function_exists('db')) {
        $pdo = db();
        return $pdo;
    }

    throw new RuntimeException('No PDO helper found in db.php');
}

function ref_ensure_schema(): void
{
    $db = ref_db();

    $db->exec("
        CREATE TABLE IF NOT EXISTS ref_accounts (
            id TEXT PRIMARY KEY,
            email TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL DEFAULT '',
            password_hash TEXT NOT NULL,
            ref_code TEXT NOT NULL UNIQUE,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            last_login_at TIMESTAMPTZ NULL
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS user_referrals (
            id BIGSERIAL PRIMARY KEY,
            ref_account_id TEXT NOT NULL REFERENCES ref_accounts(id) ON DELETE CASCADE,
            user_id TEXT NOT NULL,
            ref_code TEXT NOT NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            UNIQUE(user_id)
        )
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_user_referrals_ref_account_id_created_at
        ON user_referrals(ref_account_id, created_at DESC)
    ");
}

function ref_random_id(): string
{
    return bin2hex(random_bytes(16));
}

function ref_random_code(int $len = 8): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, $max)];
    }
    return $out;
}

function ref_find_account_by_email(string $email): ?array
{
    ref_ensure_schema();

    $db = ref_db();
    $st = $db->prepare("SELECT * FROM ref_accounts WHERE email = :email LIMIT 1");
    $st->execute([':email' => mb_strtolower(trim($email))]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function ref_find_account_by_id(string $id): ?array
{
    ref_ensure_schema();

    $db = ref_db();
    $st = $db->prepare("SELECT * FROM ref_accounts WHERE id = :id LIMIT 1");
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function ref_find_account_by_code(string $code): ?array
{
    ref_ensure_schema();

    $db = ref_db();
    $st = $db->prepare("SELECT * FROM ref_accounts WHERE ref_code = :code LIMIT 1");
    $st->execute([':code' => strtoupper(trim($code))]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function ref_create_account(string $email, string $password, string $name = ''): array
{
    ref_ensure_schema();

    $email = mb_strtolower(trim($email));
    $name = trim($name);

    if ($email === '' || $password === '') {
        throw new InvalidArgumentException('Email and password are required');
    }

    if (ref_find_account_by_email($email)) {
        throw new RuntimeException('Referral account with this email already exists');
    }

    $db = ref_db();
    $id = ref_random_id();

    $code = null;
    for ($i = 0; $i < 20; $i++) {
        $try = ref_random_code(8);
        if (!ref_find_account_by_code($try)) {
            $code = $try;
            break;
        }
    }

    if ($code === null) {
        throw new RuntimeException('Could not generate unique referral code');
    }

    $st = $db->prepare("
        INSERT INTO ref_accounts (id, email, name, password_hash, ref_code)
        VALUES (:id, :email, :name, :password_hash, :ref_code)
    ");
    $st->execute([
        ':id' => $id,
        ':email' => $email,
        ':name' => $name,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':ref_code' => $code,
    ]);

    return ref_find_account_by_id($id) ?? [];
}

function ref_verify_password(string $email, string $password): ?array
{
    ref_ensure_schema();

    $acc = ref_find_account_by_email($email);
    if (!$acc) {
        return null;
    }

    if (!password_verify($password, (string)$acc['password_hash'])) {
        return null;
    }

    $db = ref_db();
    $st = $db->prepare("UPDATE ref_accounts SET last_login_at = NOW() WHERE id = :id");
    $st->execute([':id' => $acc['id']]);

    return ref_find_account_by_id((string)$acc['id']);
}

function ref_auth_id(): ?string
{
    return isset($_SESSION['ref_account_id']) && is_string($_SESSION['ref_account_id'])
        ? $_SESSION['ref_account_id']
        : null;
}

function ref_auth_user(): ?array
{
    $id = ref_auth_id();
    if (!$id) {
        return null;
    }
    return ref_find_account_by_id($id);
}

function ref_login(array $acc): void
{
    $_SESSION['ref_account_id'] = (string)$acc['id'];
}

function ref_logout(): void
{
    unset($_SESSION['ref_account_id']);
}

function ref_base_url(): string
{
    $envBase = getenv('APP_URL');
    if (is_string($envBase) && trim($envBase) !== '') {
        return rtrim(trim($envBase), '/');
    }

    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $_SERVER['HTTP_HOST'];
    }

    return 'https://prostopdr.com';
}

function ref_link_for_account(array $acc): string
{
    return ref_base_url() . '/r.php?c=' . urlencode((string)$acc['ref_code']);
}

function ref_capture_code_from_request(?string $code): bool
{
    ref_ensure_schema();

    $code = strtoupper(trim((string)$code));
    if ($code === '') {
        return false;
    }

    $acc = ref_find_account_by_code($code);
    if (!$acc) {
        return false;
    }

    $_SESSION['pending_ref_code'] = (string)$acc['ref_code'];
    $_SESSION['pending_ref_account_id'] = (string)$acc['id'];

    return true;
}

function ref_clear_pending(): void
{
    unset($_SESSION['pending_ref_code'], $_SESSION['pending_ref_account_id']);
}

function ref_attach_new_user(string $userId): void
{
    ref_ensure_schema();

    $userId = trim($userId);
    if ($userId === '') {
        return;
    }

    $refAccountId = $_SESSION['pending_ref_account_id'] ?? null;
    $refCode = $_SESSION['pending_ref_code'] ?? null;

    if (!is_string($refAccountId) || !is_string($refCode) || $refAccountId === '' || $refCode === '') {
        return;
    }

    $db = ref_db();

    $check = $db->prepare("SELECT id FROM user_referrals WHERE user_id = :user_id LIMIT 1");
    $check->execute([':user_id' => $userId]);
    if ($check->fetch(PDO::FETCH_ASSOC)) {
        ref_clear_pending();
        return;
    }

    $st = $db->prepare("
        INSERT INTO user_referrals (ref_account_id, user_id, ref_code)
        VALUES (:ref_account_id, :user_id, :ref_code)
    ");
    $st->execute([
        ':ref_account_id' => $refAccountId,
        ':user_id' => $userId,
        ':ref_code' => $refCode,
    ]);

    ref_clear_pending();
}

function ref_signups_count(string $refAccountId): int
{
    ref_ensure_schema();

    $db = ref_db();
    $st = $db->prepare("SELECT COUNT(*) FROM user_referrals WHERE ref_account_id = :id");
    $st->execute([':id' => $refAccountId]);
    return (int)$st->fetchColumn();
}

function ref_signups_list(string $refAccountId, int $limit = 300): array
{
    ref_ensure_schema();

    $limit = max(1, min($limit, 1000));
    $db = ref_db();

    $sql = "
        SELECT
            ur.created_at AS referred_at,
            ur.user_id,
            u.name,
            u.email,
            u.plan,
            u.paid_at,
            u.expires_at,
            u.created_at AS user_created_at
        FROM user_referrals ur
        LEFT JOIN users u ON u.id = ur.user_id
        WHERE ur.ref_account_id = :id
        ORDER BY ur.created_at DESC
        LIMIT {$limit}
    ";

    $st = $db->prepare($sql);
    $st->execute([':id' => $refAccountId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}