<?php
declare(strict_types=1);

/**
 * src/oauth_store.php
 *
 * Compatibility layer for existing code that does:
 *   require __DIR__ . '/../../../src/oauth_store.php';
 *
 * We must NOT redeclare functions if they are already defined
 * (for example, if users_store.php already defines oauth_*).
 */

require_once __DIR__ . '/users_store.php';

/**
 * Find oauth link by provider+sub.
 * Returns array like:
 *  ['provider'=>..., 'sub'=>..., 'user_id'=>..., ...]
 */
if (!function_exists('oauth_find')) {
  function oauth_find(string $provider, string $sub): ?array {
    if (function_exists('\\oauth_find')) {
      return \oauth_find($provider, $sub);
    }

    // fallback (should not happen if users_store.php loaded)
    return null;
  }
}

/**
 * Get user_id by provider+sub (returns string user_id or null)
 */
if (!function_exists('oauth_user_id_by_provider_sub')) {
  function oauth_user_id_by_provider_sub(string $provider, string $sub): ?string {
    if (function_exists('\\oauth_user_id_by_provider_sub')) {
      return \oauth_user_id_by_provider_sub($provider, $sub);
    }

    $r = oauth_find($provider, $sub);
    if (!$r) return null;
    $uid = (string)($r['user_id'] ?? '');
    return $uid !== '' ? $uid : null;
  }
}

/**
 * Link provider+sub to userId (upsert).
 */
if (!function_exists('oauth_link')) {
  function oauth_link(string $provider, string $sub, string $userId, string $email = '', string $name = ''): array {
    if (function_exists('\\oauth_link')) {
      return \oauth_link($provider, $sub, $userId, $email, $name);
    }

    // fallback (should not happen if users_store.php loaded)
    return [];
  }
}