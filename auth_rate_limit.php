<?php

function getClientIpAddress() {
    $forwardedFor = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwardedFor !== '') {
        $parts = array_filter(array_map('trim', explode(',', $forwardedFor)));
        if (!empty($parts)) {
            return (string) reset($parts);
        }
    }

    $realIp = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if ($realIp !== '') {
        return $realIp;
    }

    return trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function getLoginRateLimitConfig() {
    return [
        'max_attempts' => max(1, (int) getRuntimeConfigValue('RESERVA_LOGIN_MAX_ATTEMPTS', 5)),
        'window_seconds' => max(60, (int) getRuntimeConfigValue('RESERVA_LOGIN_WINDOW_SECONDS', 900)),
        'block_seconds' => max(60, (int) getRuntimeConfigValue('RESERVA_LOGIN_BLOCK_SECONDS', 900)),
    ];
}

function buildLoginAttemptKey($schoolCode, $email, $ipAddress) {
    return strtolower(trim((string) $schoolCode)) . '|' .
        strtolower(trim((string) $email)) . '|' .
        trim((string) $ipAddress);
}

function isLoginRateLimitAvailable(PDO $pdo) {
    return databaseTableExists($pdo, 'auth_login_attempts');
}

function getLoginAttemptRecord(PDO $pdo, $attemptKey) {
    $stmt = $pdo->prepare("
        SELECT attempt_key, failure_count, first_failed_at, last_failed_at, blocked_until
        FROM auth_login_attempts
        WHERE attempt_key = ?
        LIMIT 1
    ");
    $stmt->execute([$attemptKey]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function enforceLoginRateLimit(PDO $pdo, $attemptKey) {
    if (!isLoginRateLimitAvailable($pdo)) {
        return;
    }

    $attempt = getLoginAttemptRecord($pdo, $attemptKey);
    if (!$attempt || empty($attempt['blocked_until'])) {
        return;
    }

    $blockedUntil = strtotime((string) $attempt['blocked_until']);
    $now = time();
    if ($blockedUntil === false || $blockedUntil <= $now) {
        return;
    }

    jsonResponse(
        false,
        "Muitas tentativas de login. Aguarde alguns minutos antes de tentar novamente.",
        null,
        429,
        [
            'retry_after_seconds' => $blockedUntil - $now,
            'error_code' => 'LOGIN_RATE_LIMITED',
        ]
    );
}

function registerFailedLoginAttempt(PDO $pdo, $attemptKey, $schoolCode, $email, $ipAddress) {
    if (!isLoginRateLimitAvailable($pdo)) {
        return;
    }

    $config = getLoginRateLimitConfig();
    $now = new DateTimeImmutable();
    $windowStart = $now->modify('-' . $config['window_seconds'] . ' seconds');
    $blockedUntil = null;

    $existing = getLoginAttemptRecord($pdo, $attemptKey);
    if ($existing === null) {
        $failureCount = 1;
        $firstFailedAt = $now;
    } else {
        $firstFailedAtValue = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            (string) $existing['first_failed_at']
        ) ?: new DateTimeImmutable((string) $existing['first_failed_at']);

        if ($firstFailedAtValue < $windowStart) {
            $failureCount = 1;
            $firstFailedAt = $now;
        } else {
            $failureCount = (int) $existing['failure_count'] + 1;
            $firstFailedAt = $firstFailedAtValue;
        }
    }

    if ($failureCount >= $config['max_attempts']) {
        $blockedUntil = $now->modify('+' . $config['block_seconds'] . ' seconds');
    }

    if ($existing === null) {
        $stmt = $pdo->prepare("
            INSERT INTO auth_login_attempts (
                attempt_key,
                school_code,
                email,
                ip_address,
                failure_count,
                first_failed_at,
                last_failed_at,
                blocked_until
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $attemptKey,
            $schoolCode,
            strtolower(trim((string) $email)),
            $ipAddress,
            $failureCount,
            $firstFailedAt->format('Y-m-d H:i:s'),
            $now->format('Y-m-d H:i:s'),
            $blockedUntil?->format('Y-m-d H:i:s'),
        ]);
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE auth_login_attempts
        SET school_code = ?,
            email = ?,
            ip_address = ?,
            failure_count = ?,
            first_failed_at = ?,
            last_failed_at = ?,
            blocked_until = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE attempt_key = ?
    ");
    $stmt->execute([
        $schoolCode,
        strtolower(trim((string) $email)),
        $ipAddress,
        $failureCount,
        $firstFailedAt->format('Y-m-d H:i:s'),
        $now->format('Y-m-d H:i:s'),
        $blockedUntil?->format('Y-m-d H:i:s'),
        $attemptKey,
    ]);
}

function clearFailedLoginAttempts(PDO $pdo, $attemptKey) {
    if (!isLoginRateLimitAvailable($pdo)) {
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM auth_login_attempts WHERE attempt_key = ?");
    $stmt->execute([$attemptKey]);
}
