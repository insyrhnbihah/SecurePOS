<?php

/**
 * Write one concise general SecurePOS audit event.
 *
 * Callers must provide only safe operational text. Passwords, hashes, session or
 * CSRF identifiers, QR or pairing data, kiosk credentials, precise coordinates,
 * and raw request payloads must never be passed to this helper.
 */
function auditLog(
    mysqli $mysqli,
    ?int $userId,
    ?string $actorName,
    ?string $actorRole,
    string $module,
    string $action,
    string $result,
    ?string $description = null
): bool {
    $allowedModules = [
        'Authentication',
        'Users',
        'Inventory',
        'POS',
        'Product Expiry',
        'Attendance',
        'Reports',
    ];
    $allowedResults = ['Success', 'Failed', 'Failure', 'Rejected'];

    $action = trim($action);
    if (!in_array($module, $allowedModules, true)
        || !in_array($result, $allowedResults, true)
        || $action === '') {
        return false;
    }

    $userId = $userId !== null && $userId > 0 ? $userId : null;
    $actorName = $actorName !== null ? trim($actorName) : null;
    $actorRole = $actorRole !== null ? trim($actorRole) : null;
    $description = $description !== null ? trim($description) : null;

    $actorName = $actorName === null || $actorName === '' ? null : mb_substr($actorName, 0, 100, 'UTF-8');
    $actorRole = $actorRole === null || $actorRole === '' ? null : mb_substr($actorRole, 0, 50, 'UTF-8');
    $action = mb_substr($action, 0, 100, 'UTF-8');
    $description = $description === null || $description === '' ? null : mb_substr($description, 0, 255, 'UTF-8');

    try {
        $stmt = $mysqli->prepare(
            'INSERT INTO audit_logs
                (user_id, actor_name, actor_role, module, action, result, description)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'issssss',
            $userId,
            $actorName,
            $actorRole,
            $module,
            $action,
            $result,
            $description
        );
        $written = $stmt->execute();
        $stmt->close();

        return $written;
    } catch (Throwable $exception) {
        return false;
    }
}
