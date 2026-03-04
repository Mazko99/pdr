<?php
declare(strict_types=1);

/**
 * DB helpers for users
 * db() already defined in bootstrap.php
 */

function ensure_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id BIGSERIAL PRIMARY KEY,
            email VARCHAR(190) NOT NULL UNIQUE,
            name VARCHAR(190),
            password_hash VARCHAR(255),
            google_sub VARCHAR(255) UNIQUE,
            plan VARCHAR(50) DEFAULT 'free',
            expires_at TIMESTAMPTZ,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        );
    ");
}

/**
 * Find user by email
 */
function db_find_user_by_email(PDO $pdo, string $email): ?array {

    $st = $pdo->prepare("
        SELECT *
        FROM users
        WHERE email = :email
        LIMIT 1
    ");

    $st->execute([
        'email' => $email
    ]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}


/**
 * Find user by Google ID
 */
function db_find_user_by_google_sub(PDO $pdo, string $sub): ?array {

    $st = $pdo->prepare("
        SELECT *
        FROM users
        WHERE google_sub = :sub
        LIMIT 1
    ");

    $st->execute([
        'sub' => $sub
    ]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}


/**
 * Create user (email/password)
 */
function db_create_user_email(PDO $pdo, string $email, string $name, string $passwordHash): int {

    $st = $pdo->prepare("
        INSERT INTO users (email, name, password_hash)
        VALUES (:email, :name, :ph)
        RETURNING id
    ");

    $st->execute([
        'email' => $email,
        'name'  => ($name !== '' ? $name : null),
        'ph'    => $passwordHash
    ]);

    return (int)$st->fetchColumn();
}


/**
 * Google login / signup
 */
function db_upsert_user_google(PDO $pdo, string $email, string $name, string $sub): int {

    $u = db_find_user_by_google_sub($pdo, $sub);

    if ($u) {
        return (int)$u['id'];
    }

    $u2 = db_find_user_by_email($pdo, $email);

    if ($u2) {

        $st = $pdo->prepare("
            UPDATE users
            SET google_sub = :sub,
                name = COALESCE(NULLIF(name,''), :name)
            WHERE id = :id
            RETURNING id
        ");

        $st->execute([
            'sub'  => $sub,
            'name' => ($name !== '' ? $name : null),
            'id'   => (int)$u2['id']
        ]);

        return (int)$st->fetchColumn();
    }

    $st = $pdo->prepare("
        INSERT INTO users (email, name, google_sub)
        VALUES (:email, :name, :sub)
        RETURNING id
    ");

    $st->execute([
        'email' => $email,
        'name'  => ($name !== '' ? $name : null),
        'sub'   => $sub
    ]);

    return (int)$st->fetchColumn();
}


/**
 * Update user plan (used by Mono webhook)
 */
function db_set_user_plan(PDO $pdo, int $userId, string $plan, ?string $expires): void {

    $st = $pdo->prepare("
        UPDATE users
        SET plan = :plan,
            expires_at = :exp
        WHERE id = :id
    ");

    $st->execute([
        'plan' => $plan,
        'exp'  => $expires,
        'id'   => $userId
    ]);
}