<?php
require_once __DIR__ . '/config/auth.php';
requireManager();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/audit.php';
require_once __DIR__ . '/config/expiry_notifications.php';

$notifications = getExpiryNotifications($mysqli);
$userRoles = ['Manager', 'Cashier', 'Inventory Staff'];
$userStatuses = ['Active', 'Inactive'];
$formError = '';
$showAddModal = false;
$formValues = ['full_name' => '', 'email' => '', 'role' => 'Cashier', 'account_status' => 'Active'];
$editError = '';
$showEditModal = false;
$editValues = ['user_id' => '', 'full_name' => '', 'email' => '', 'role' => 'Manager', 'account_status' => 'Active'];
$resetError = '';
$showResetModal = false;
$resetValues = ['user_id' => '', 'full_name' => ''];

function nextEmployeeCode(mysqli $mysqli): string
{
    $stmt = $mysqli->prepare("SELECT employee_code FROM employees WHERE employee_code LIKE 'EMP-%' FOR UPDATE");
    if (!$stmt || !$stmt->execute()) {
        throw new RuntimeException('Unable to generate employee code.');
    }
    $result = $stmt->get_result();
    $highestNumber = 0;
    while ($row = $result->fetch_assoc()) {
        if (preg_match('/\AEMP-(\d+)\z/D', $row['employee_code'], $matches)) {
            $highestNumber = max($highestNumber, (int)$matches[1]);
        }
    }
    $stmt->close();
    return sprintf('EMP-%03d', $highestNumber + 1);
}

if (empty($_SESSION['users_csrf'])) {
    $_SESSION['users_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_user') {
    $showAddModal = true;
    $formValues = [
        'full_name' => trim((string)($_POST['full_name'] ?? '')),
        'email' => strtolower(trim((string)($_POST['email'] ?? ''))),
        'role' => trim((string)($_POST['role'] ?? '')),
        'account_status' => trim((string)($_POST['account_status'] ?? '')),
    ];
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $submittedCsrf = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['users_csrf'], $submittedCsrf)) {
        $formError = 'The form session has expired. Please try again.';
    } elseif ($formValues['full_name'] === '' || mb_strlen($formValues['full_name']) > 100) {
        $formError = 'Enter a full name of no more than 100 characters.';
    } elseif (!filter_var($formValues['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($formValues['email']) > 100) {
        $formError = 'Enter a valid email address.';
    } elseif (!in_array($formValues['role'], $userRoles, true)) {
        $formError = 'Select a valid user role.';
    } elseif (!in_array($formValues['account_status'], $userStatuses, true)) {
        $formError = 'Select a valid account status.';
    } elseif (strlen($password) < 8) {
        $formError = 'Password must contain at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $formError = 'Password and confirmation do not match.';
    }

    if ($formError === '') {
        $employeeCode = null;
        $mysqli->begin_transaction();
        try {
            $duplicate = $mysqli->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            if (!$duplicate) {
                throw new RuntimeException('Unable to validate email.');
            }
            $duplicate->bind_param('s', $formValues['email']);
            $duplicate->execute();
            $emailExists = (bool)$duplicate->get_result()->fetch_assoc();
            $duplicate->close();
            if ($emailExists) {
                throw new DomainException('A user with this email address already exists.');
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if ($passwordHash === false) {
                throw new RuntimeException('Unable to secure the password.');
            }
            $insert = $mysqli->prepare('INSERT INTO users (full_name, email, password_hash, role, account_status) VALUES (?, ?, ?, ?, ?)');
            if (!$insert) {
                throw new RuntimeException('Unable to create user.');
            }
            $insert->bind_param('sssss', $formValues['full_name'], $formValues['email'], $passwordHash, $formValues['role'], $formValues['account_status']);
            if (!$insert->execute()) {
                throw new RuntimeException('Unable to create user.');
            }
            $newUserId = (int)$insert->insert_id;
            $insert->close();

            if ($formValues['role'] !== 'Manager') {
                $employeeCode = nextEmployeeCode($mysqli);

                $employeeInsert = $mysqli->prepare('INSERT INTO employees (employee_code, full_name, role, email, account_status, user_id) VALUES (?, ?, ?, ?, ?, ?)');
                if (!$employeeInsert) {
                    throw new RuntimeException('Unable to create employee profile.');
                }
                $employeeInsert->bind_param('sssssi', $employeeCode, $formValues['full_name'], $formValues['role'], $formValues['email'], $formValues['account_status'], $newUserId);
                if (!$employeeInsert->execute()) {
                    throw new RuntimeException('Unable to create employee profile.');
                }
                $employeeInsert->close();
            }

            $mysqli->commit();
            $createdUserLabel = $formValues['full_name'];
            if ($employeeCode !== null) {
                $createdUserLabel .= ' (' . $employeeCode . ')';
            }
            auditLog(
                $mysqli,
                (int)$_SESSION['user_id'],
                currentUserName(),
                currentUserRole(),
                'Users',
                'Create User',
                'Success',
                'User account created for ' . $createdUserLabel . '.'
            );
            $_SESSION['users_flash'] = 'User account created successfully.';
            header('Location: users.php');
            exit;
        } catch (DomainException $exception) {
            $mysqli->rollback();
            $formError = $exception->getMessage();
        } catch (Throwable $exception) {
            $mysqli->rollback();
            error_log('Add user failed: ' . $exception->getMessage());
            $formError = 'SecurePOS could not create the user. Please try again.';
        }
    }
    unset($password, $confirmPassword, $passwordHash);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    $showResetModal = true;
    $resetValues = ['user_id' => trim((string)($_POST['user_id'] ?? '')), 'full_name' => trim((string)($_POST['full_name'] ?? ''))];
    $temporaryPassword = (string)($_POST['temporary_password'] ?? '');
    $confirmTemporaryPassword = (string)($_POST['confirm_temporary_password'] ?? '');
    $submittedCsrf = (string)($_POST['csrf_token'] ?? '');
    $validatedUserId = filter_var($resetValues['user_id'], FILTER_VALIDATE_INT);

    if (!hash_equals($_SESSION['users_csrf'], $submittedCsrf)) {
        $resetError = 'The form session has expired. Please try again.';
    } elseif ($validatedUserId === false || $validatedUserId < 1) {
        $resetError = 'Select a valid user account.';
    } elseif (strlen($temporaryPassword) < 8) {
        $resetError = 'Temporary password must contain at least 8 characters.';
    } elseif ($temporaryPassword !== $confirmTemporaryPassword) {
        $resetError = 'Temporary password and confirmation do not match.';
    }

    if ($resetError === '') {
        $targetUserId = (int)$validatedUserId;
        $mysqli->begin_transaction();
        try {
            $targetStmt = $mysqli->prepare('SELECT full_name, email FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
            if (!$targetStmt) { throw new RuntimeException('Unable to load user.'); }
            $targetStmt->bind_param('i', $targetUserId);
            $targetStmt->execute();
            $target = $targetStmt->get_result()->fetch_assoc();
            $targetStmt->close();
            if (!$target) { throw new DomainException('The selected user no longer exists.'); }

            $temporaryPasswordHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
            if ($temporaryPasswordHash === false) { throw new RuntimeException('Unable to secure the temporary password.'); }
            $update = $mysqli->prepare('UPDATE users SET password_hash = ?, force_password_change = 1, failed_login_attempts = 0, locked_until = NULL WHERE id = ?');
            if (!$update) { throw new RuntimeException('Unable to reset password.'); }
            $update->bind_param('si', $temporaryPasswordHash, $targetUserId);
            if (!$update->execute()) { $update->close(); throw new RuntimeException('Unable to reset password.'); }
            $update->close();
            $mysqli->commit();

            auditLog($mysqli, (int)$_SESSION['user_id'], currentUserName(), currentUserRole(), 'Users', 'Reset Password', 'Success', 'Temporary password issued for ' . $target['full_name'] . ' (' . $target['email'] . ').');
            $_SESSION['users_flash'] = 'Temporary password issued. The user must change it after authentication.';
            unset($temporaryPassword, $confirmTemporaryPassword, $temporaryPasswordHash);
            header('Location: users.php');
            exit;
        } catch (DomainException $exception) {
            $mysqli->rollback();
            $resetError = $exception->getMessage();
        } catch (Throwable $exception) {
            $mysqli->rollback();
            error_log('Reset password failed: ' . $exception->getMessage());
            $resetError = 'SecurePOS could not reset the password. Please try again.';
        }
    }
    unset($temporaryPassword, $confirmTemporaryPassword, $temporaryPasswordHash);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_user') {
    $showEditModal = true;
    $editValues = [
        'user_id' => trim((string)($_POST['user_id'] ?? '')),
        'full_name' => trim((string)($_POST['full_name'] ?? '')),
        'email' => strtolower(trim((string)($_POST['email'] ?? ''))),
        'role' => trim((string)($_POST['role'] ?? '')),
        'account_status' => trim((string)($_POST['account_status'] ?? '')),
    ];
    $submittedCsrf = (string)($_POST['csrf_token'] ?? '');
    $validatedUserId = filter_var($editValues['user_id'], FILTER_VALIDATE_INT);

    if (!hash_equals($_SESSION['users_csrf'], $submittedCsrf)) {
        $editError = 'The form session has expired. Please try again.';
    } elseif ($validatedUserId === false || $validatedUserId < 1) {
        $editError = 'Select a valid user account.';
    } elseif ($editValues['full_name'] === '' || mb_strlen($editValues['full_name']) > 100) {
        $editError = 'Enter a full name of no more than 100 characters.';
    } elseif (!filter_var($editValues['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($editValues['email']) > 100) {
        $editError = 'Enter a valid email address.';
    } elseif (!in_array($editValues['role'], $userRoles, true)) {
        $editError = 'Select a valid user role.';
    } elseif (!in_array($editValues['account_status'], $userStatuses, true)) {
        $editError = 'Select a valid account status.';
    }

    if ($editError === '') {
        $targetUserId = (int)$validatedUserId;
        $rejectedAuditDescription = null;
        $mysqli->begin_transaction();
        try {
            $targetStmt = $mysqli->prepare('SELECT u.full_name, u.email, u.role, u.account_status, e.id AS employee_id, e.employee_code FROM users u LEFT JOIN employees e ON e.user_id = u.id WHERE u.id = ? LIMIT 1 FOR UPDATE');
            if (!$targetStmt) {
                throw new RuntimeException('Unable to load user.');
            }
            $targetStmt->bind_param('i', $targetUserId);
            $targetStmt->execute();
            $target = $targetStmt->get_result()->fetch_assoc();
            $targetStmt->close();
            if (!$target) {
                throw new DomainException('The selected user no longer exists.');
            }

            $isCurrentUser = $targetUserId === (int)$_SESSION['user_id'];
            if ($isCurrentUser && $target['role'] === 'Manager' && $editValues['role'] !== 'Manager') {
                $rejectedAuditDescription = 'Manager self-demotion was rejected.';
                throw new DomainException('You cannot demote your currently authenticated Manager account.');
            }
            if ($isCurrentUser && $target['account_status'] === 'Active' && $editValues['account_status'] !== 'Active') {
                $rejectedAuditDescription = 'Manager self-deactivation was rejected.';
                throw new DomainException('You cannot set your currently authenticated account to Inactive.');
            }

            $removesActiveManager = $target['role'] === 'Manager' && $target['account_status'] === 'Active'
                && ($editValues['role'] !== 'Manager' || $editValues['account_status'] !== 'Active');
            if ($removesActiveManager) {
                $managerStmt = $mysqli->prepare("SELECT id FROM users WHERE role = 'Manager' AND account_status = 'Active' FOR UPDATE");
                if (!$managerStmt || !$managerStmt->execute()) {
                    throw new RuntimeException('Unable to validate Manager accounts.');
                }
                $managerResult = $managerStmt->get_result();
                $otherActiveManagers = 0;
                while ($manager = $managerResult->fetch_assoc()) {
                    if ((int)$manager['id'] !== $targetUserId) {
                        $otherActiveManagers++;
                    }
                }
                $managerStmt->close();
                if ($otherActiveManagers === 0) {
                    $rejectedAuditDescription = 'Removal or inactivation of the final active Manager was rejected.';
                    throw new DomainException('SecurePOS must retain at least one active Manager account.');
                }
            }

            $duplicate = $mysqli->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            if (!$duplicate) {
                throw new RuntimeException('Unable to validate email.');
            }
            $duplicate->bind_param('si', $editValues['email'], $targetUserId);
            $duplicate->execute();
            $emailExists = (bool)$duplicate->get_result()->fetch_assoc();
            $duplicate->close();
            if ($emailExists) {
                throw new DomainException('A user with this email address already exists.');
            }

            $update = $mysqli->prepare('UPDATE users SET full_name = ?, email = ?, role = ?, account_status = ? WHERE id = ?');
            if (!$update) {
                throw new RuntimeException('Unable to update user.');
            }
            $update->bind_param('ssssi', $editValues['full_name'], $editValues['email'], $editValues['role'], $editValues['account_status'], $targetUserId);
            if (!$update->execute()) {
                throw new RuntimeException('Unable to update user.');
            }
            $update->close();

            $employeeId = $target['employee_id'] !== null ? (int)$target['employee_id'] : null;
            if ($employeeId !== null && $editValues['role'] === 'Manager') {
                $employeeUpdate = $mysqli->prepare('UPDATE employees SET user_id = NULL WHERE id = ? AND user_id = ?');
                if (!$employeeUpdate) {
                    throw new RuntimeException('Unable to unlink employee profile.');
                }
                $employeeUpdate->bind_param('ii', $employeeId, $targetUserId);
            } elseif ($employeeId !== null) {
                $employeeUpdate = $mysqli->prepare('UPDATE employees SET full_name = ?, email = ?, role = ?, account_status = ? WHERE id = ? AND user_id = ?');
                if (!$employeeUpdate) {
                    throw new RuntimeException('Unable to synchronize employee profile.');
                }
                $employeeUpdate->bind_param('ssssii', $editValues['full_name'], $editValues['email'], $editValues['role'], $editValues['account_status'], $employeeId, $targetUserId);
            } elseif ($editValues['role'] !== 'Manager') {
                $employeeCode = nextEmployeeCode($mysqli);
                $employeeUpdate = $mysqli->prepare('INSERT INTO employees (employee_code, full_name, role, email, account_status, user_id) VALUES (?, ?, ?, ?, ?, ?)');
                if (!$employeeUpdate) {
                    throw new RuntimeException('Unable to create employee profile.');
                }
                $employeeUpdate->bind_param('sssssi', $employeeCode, $editValues['full_name'], $editValues['role'], $editValues['email'], $editValues['account_status'], $targetUserId);
            } else {
                $employeeUpdate = null;
            }
            if ($employeeUpdate !== null) {
                if (!$employeeUpdate->execute()) {
                    throw new RuntimeException('Unable to synchronize employee profile.');
                }
                $employeeUpdate->close();
            }

            $mysqli->commit();
            $changedFields = $target['full_name'] !== $editValues['full_name']
                || $target['email'] !== $editValues['email']
                || $target['role'] !== $editValues['role']
                || $target['account_status'] !== $editValues['account_status'];
            if ($changedFields) {
                $updatedUserLabel = $editValues['full_name'];
                if ($target['employee_code'] !== null) {
                    $updatedUserLabel .= ' (' . $target['employee_code'] . ')';
                }
                if ($target['role'] !== $editValues['role'] && $target['account_status'] !== $editValues['account_status']) {
                    $auditDescription = 'User role changed from ' . $target['role'] . ' to ' . $editValues['role']
                        . ' and account status changed from ' . $target['account_status'] . ' to ' . $editValues['account_status']
                        . ' for ' . $updatedUserLabel . '.';
                } elseif ($target['role'] !== $editValues['role']) {
                    $auditDescription = 'User role changed from ' . $target['role'] . ' to ' . $editValues['role']
                        . ' for ' . $updatedUserLabel . '.';
                } elseif ($target['account_status'] !== $editValues['account_status']) {
                    $auditDescription = 'User account ' . $updatedUserLabel . ' changed from '
                        . $target['account_status'] . ' to ' . $editValues['account_status'] . '.';
                } else {
                    $auditDescription = 'User profile updated for ' . $updatedUserLabel . '.';
                }
                auditLog(
                    $mysqli,
                    (int)$_SESSION['user_id'],
                    currentUserName(),
                    currentUserRole(),
                    'Users',
                    'Update User',
                    'Success',
                    $auditDescription
                );
            }
            $_SESSION['users_flash'] = 'User account updated successfully.';
            header('Location: users.php');
            exit;
        } catch (DomainException $exception) {
            $mysqli->rollback();
            if ($rejectedAuditDescription !== null) {
                auditLog(
                    $mysqli,
                    (int)$_SESSION['user_id'],
                    currentUserName(),
                    currentUserRole(),
                    'Users',
                    'Update User',
                    'Rejected',
                    $rejectedAuditDescription
                );
            }
            $editError = $exception->getMessage();
        } catch (Throwable $exception) {
            $mysqli->rollback();
            error_log('Edit user failed: ' . $exception->getMessage());
            $editError = 'SecurePOS could not update the user. Please try again.';
        }
    }
}

$flashMessage = (string)($_SESSION['users_flash'] ?? '');
unset($_SESSION['users_flash']);

$searchTerm = trim((string)($_GET['search'] ?? ''));
$roleFilter = trim((string)($_GET['role'] ?? 'all'));
$statusFilter = trim((string)($_GET['status'] ?? 'all'));
$allowedRoles = array_merge(['all'], $userRoles);
$allowedStatuses = array_merge(['all'], $userStatuses);

if (!in_array($roleFilter, $allowedRoles, true)) {
    $roleFilter = 'all';
}
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'all';
}

$sql = "SELECT u.id, u.full_name, u.email, u.role, u.account_status, u.created_at, e.employee_code,
        CASE WHEN uft.id IS NULL THEN 0 ELSE 1 END AS face_enrolled
    FROM users u
    LEFT JOIN employees e ON e.user_id = u.id
    LEFT JOIN user_face_templates uft ON uft.user_id = u.id
    WHERE (? = '' OR u.full_name LIKE CONCAT('%', ?, '%') OR u.email LIKE CONCAT('%', ?, '%'))
      AND (? = 'all' OR u.role = ?)
      AND (? = 'all' OR u.account_status = ?)
    ORDER BY FIELD(u.role, 'Manager', 'Cashier', 'Inventory Staff'), u.full_name ASC";
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    die('Unable to load users.');
}
$stmt->bind_param('sssssss', $searchTerm, $searchTerm, $searchTerm, $roleFilter, $roleFilter, $statusFilter, $statusFilter);
$stmt->execute();
$result = $stmt->get_result();
$users = [];
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script src="assets/js/theme.js?v=20261007"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Management | SecurePOS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-3e6pZLlYGnJotkXptUsH4FJsuMx6Knc4fNdZ3K4BVhEME8GVerSSTpfaYfZ2C8Ux" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/style.css?v=20260829-filters">
    <style>
        .users-header { display: flex; justify-content: space-between; align-items: flex-end; gap: 18px; margin-bottom: 20px; }
        .users-header h1 { margin: 0 0 7px; font-size: 1.55rem; }
        .users-header p { margin: 0; color: var(--muted); }
        .users-toolbar { display: grid; grid-template-columns: minmax(240px, 1fr) minmax(170px, 210px) minmax(150px, 190px) auto auto; gap: 10px; align-items: center; margin-bottom: 16px; }
        .users-toolbar .form-control, .users-toolbar .form-select { min-height: 40px; border-color: var(--theme-89, rgba(255,255,255,.09)); background: var(--theme-79, rgba(255,255,255,.04)); color: var(--text); }
        .users-toolbar .form-control::placeholder { color: var(--muted); }
        .users-toolbar .form-select option { background: var(--theme-90, #101d2d); color: var(--text); }
        .users-table { width: 100%; min-width: 1050px; table-layout: auto; margin-bottom: 0; }
        .users-table th, .users-table td { padding: 9px 12px; vertical-align: middle; }
        .users-table .col-name { min-width: 155px; text-align: left; white-space: nowrap; }
        .users-table .col-email { min-width: 250px; text-align: left; white-space: nowrap; }
        .users-table .col-role { min-width: 150px; padding-left: 20px; text-align: center; white-space: nowrap; }
        .users-table .col-employee { min-width: 130px; text-align: center; white-space: nowrap; }
        .users-table .col-status { min-width: 145px; text-align: center; white-space: nowrap; }
        .users-table .col-face { min-width: 135px; text-align: center; white-space: nowrap; }
        .users-table .col-created { min-width: 125px; text-align: center; white-space: nowrap; }
        .users-table .col-action { width: 118px; min-width: 118px; padding-left: 8px; padding-right: 8px; text-align: center; white-space: nowrap; }
        .user-badge { display: inline-flex; align-items: center; justify-content: center; min-height: 27px; padding: 5px 10px; border: 1px solid var(--theme-80, rgba(255,255,255,.1)); border-radius: 999px; font-size: .76rem; font-weight: 700; white-space: nowrap; }
        .user-badge.active { border-color: rgba(85,214,209,.25); background: rgba(85,214,209,.13); color: var(--theme-112, #9feee9); }
        .user-badge.inactive { border-color: rgba(252,92,125,.25); background: rgba(252,92,125,.13); color: var(--theme-114, #ffb3c1); }
        .user-badge.role { color: var(--theme-158, #c8d5e6); background: var(--theme-139, rgba(255,255,255,.045)); }
        .users-empty { padding: 22px 14px !important; color: var(--muted) !important; text-align: center; }
        .users-alert { margin-bottom: 16px; border: 1px solid rgba(85,214,209,.24); background: rgba(85,214,209,.1); color: var(--theme-120, #b9f5f1); }
        .users-modal-backdrop { position: fixed; inset: 0; z-index: 1200; display: none; align-items: center; justify-content: center; padding: 24px; background: var(--theme-159, rgba(8,15,32,.78)); }
        .users-modal-backdrop.visible { display: flex; }
        .users-modal { width: min(100%, 680px); max-height: calc(100vh - 48px); overflow-y: auto; padding: 26px; border: 1px solid var(--theme-80, rgba(255,255,255,.1)); border-radius: 20px; background: var(--theme-90, #101d2d); box-shadow: 0 28px 80px var(--theme-160, rgba(0,0,0,.45)); }
        .users-modal-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; margin-bottom: 20px; }
        .users-modal-header h3 { margin: 0 0 5px; font-size: 1.35rem; }
        .users-modal-header p { margin: 0; color: var(--muted); font-size: .9rem; }
        .users-modal-close { border: 0; background: transparent; color: var(--muted); font-size: 1.6rem; line-height: 1; cursor: pointer; }
        .users-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 15px; }
        .users-form-group { display: grid; gap: 7px; }
        .users-form-group.full { grid-column: 1 / -1; }
        .users-form-group label { color: var(--theme-158, #c8d5e6); font-size: .86rem; font-weight: 600; }
        .users-form-group .form-control, .users-form-group .form-select { min-height: 42px; border-color: var(--theme-80, rgba(255,255,255,.1)); background: var(--theme-139, rgba(255,255,255,.045)); color: var(--text); }
        .users-form-group .form-select option { background: var(--theme-90, #101d2d); color: var(--text); }
        .users-form-note { margin: 0; color: var(--muted); font-size: .78rem; }
        .users-form-note.full { grid-column: 1 / -1; }
        .users-form-error { grid-column: 1 / -1; margin: 0; padding: 11px 13px; border: 1px solid rgba(252,92,125,.25); border-radius: 9px; background: rgba(252,92,125,.1); color: var(--theme-114, #ffb3c1); }
        .users-header #open-add-user, .users-modal-actions .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 38px; padding: 8px 15px; border-radius: 9px; font-size: .86rem; font-weight: 700; line-height: 1.2; box-shadow: none; }
        .users-header #open-add-user, .users-modal-actions .btn-primary { border: 1px solid #55d6d1; background: linear-gradient(135deg, #55d6d1, #3db5ff); color: #07101c; }
        .users-header #open-add-user:hover, .users-header #open-add-user:focus, .users-modal-actions .btn-primary:hover, .users-modal-actions .btn-primary:focus { border-color: #72e2de; background: linear-gradient(135deg, #72e2de, #59c3ff); color: #07101c; box-shadow: 0 8px 18px rgba(85,214,209,.16); }
        .users-modal-actions .btn-outline-light { border: 1px solid var(--theme-91, rgba(255,255,255,.16)); background: var(--theme-79, rgba(255,255,255,.04)); color: var(--theme-161, rgba(255,255,255,.92)); }
        .users-modal-actions .btn-outline-light:hover, .users-modal-actions .btn-outline-light:focus { border-color: var(--theme-162, rgba(255,255,255,.25)); background: var(--theme-89, rgba(255,255,255,.09)); color: var(--theme-13, #fff); }
        .users-modal-actions { grid-column: 1 / -1; display: flex; justify-content: flex-end; gap: 12px; margin-top: 5px; }
        .user-actions { display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .edit-user-button, .more-actions-button { display: inline-flex; min-height: 32px; height: 32px; align-items: center; justify-content: center; border: 1px solid rgba(85,214,209,.24); border-radius: 8px; background: rgba(85,214,209,.07); color: var(--theme-163, #a8e9e5); font-size: .78rem; font-weight: 700; }
        .edit-user-button { padding: 5px 11px; }
        .more-actions-button { width: 34px; padding: 0; font-size: 1.15rem; line-height: 1; letter-spacing: 1px; }
        .edit-user-button:hover, .edit-user-button:focus, .more-actions-button:hover, .more-actions-button:focus, .more-actions-button[aria-expanded="true"] { border-color: rgba(85,214,209,.48); background: rgba(85,214,209,.14); color: var(--theme-164, #d1faf7); }
        .user-actions-menu { position: fixed; z-index: 1150; display: none; min-width: 172px; padding: 6px; border: 1px solid var(--theme-78, rgba(255,255,255,.12)); border-radius: 10px; background: var(--theme-90, #101d2d); box-shadow: 0 16px 38px var(--theme-165, rgba(0,0,0,.42)); }
        .user-actions-menu.visible { display: block; }
        .user-actions-menu button { width: 100%; padding: 9px 11px; border: 0; border-radius: 7px; background: transparent; color: var(--theme-166, #d4dfed); text-align: left; font: inherit; font-size: .8rem; font-weight: 600; }
        .user-actions-menu button:hover, .user-actions-menu button:focus { outline: none; background: rgba(85,214,209,.11); color: var(--theme-167, #baf4f0); }
        .face-enrollment-cell { display: grid; justify-items: center; gap: 4px; }
        .face-status { font-size: .76rem; font-weight: 700; color: var(--muted); }
        .face-status.enrolled { color: var(--theme-168, #83e6b4); }
        .face-enroll-button { min-height: 27px; padding: 3px 8px; border: 1px solid var(--theme-78, rgba(255,255,255,.12)); border-radius: 7px; background: var(--theme-92, rgba(255,255,255,.035)); color: var(--theme-169, #aebdce); font-size: .72rem; font-weight: 600; }
        .face-enroll-button:hover, .face-enroll-button:focus { border-color: rgba(85,214,209,.28); background: rgba(85,214,209,.07); color: var(--theme-167, #baf4f0); }
        .face-enroll-button:disabled { cursor: not-allowed; opacity: .45; }
        .face-video-wrap { overflow: hidden; border-radius: 14px; background: var(--theme-81, #050b13); aspect-ratio: 4 / 3; }
        .face-video { display: block; width: 100%; height: 100%; object-fit: cover; transform: scaleX(-1); }
        .face-enrollment-message { min-height: 1.4em; margin: 12px 0 0; color: var(--muted); font-size: .86rem; }
        .face-enrollment-message.error { color: var(--theme-114, #ffb3c1); }
        body.users-modal-open { overflow: hidden; }
        @media (max-width: 1000px) { .users-toolbar { grid-template-columns: repeat(2, minmax(180px, 1fr)); } }
        @media (max-width: 620px) { .users-header { align-items: stretch; flex-direction: column; } .users-header .btn { width: 100%; } .users-toolbar, .users-form-grid { grid-template-columns: 1fr; } .users-modal-backdrop { padding: 12px; } .users-modal { padding: 20px; } }
    </style>
    <link rel="stylesheet" href="assets/css/theme.css?v=20261007">
</head>
<body class="<?php echo ($showAddModal || $showEditModal || $showResetModal) ? 'users-modal-open' : ''; ?>">
    <div class="dashboard-shell">
        <aside class="sidebar">
            <div class="sidebar-header"><div class="brand"><div class="brand-icon">S</div><div><h1>SecurePOS</h1><p>Restoran Kencana Sari</p></div></div></div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="pos.php" class="nav-link">Point of Sale</a>
                <a href="inventory.php" class="nav-link">Inventory</a>
                <a href="product_expiry.php" class="nav-link">Product Expiry</a>
                <a href="attendance.php" class="nav-link">Employee Attendance</a>
                <a href="users.php" class="nav-link active">Users</a>
                <a href="reports.php" class="nav-link">Reports</a>
                <a href="audit_logs.php" class="nav-link">Audit Logs</a>
            </nav>
            <div class="sidebar-footer"><div class="profile-avatar"><?php echo htmlspecialchars(currentUserInitials(), ENT_QUOTES, 'UTF-8'); ?></div><div><p class="profile-name"><?php echo htmlspecialchars(currentUserName(), ENT_QUOTES, 'UTF-8'); ?></p><p class="profile-role"><?php echo htmlspecialchars(currentUserRole(), ENT_QUOTES, 'UTF-8'); ?></p><a class="profile-logout" href="logout.php">Logout</a></div></div>
        </aside>

        <main class="content-area">
            <header class="topbar">
                <div class="page-title"><p class="breadcrumb">SecurePOS / Users</p><h2>Users Management</h2></div>
                <div class="topbar-actions"><div class="topbar-chip secondary"><span id="current-datetime">Loading...</span></div><?php echo renderExpiryNotificationBell($notifications, 'product_expiry.php'); ?><div class="topbar-profile"><span class="profile-initials"><?php echo htmlspecialchars(currentUserInitials(), ENT_QUOTES, 'UTF-8'); ?></span><div><p><?php echo htmlspecialchars(currentUserName(), ENT_QUOTES, 'UTF-8'); ?></p><small><?php echo htmlspecialchars(currentUserRole(), ENT_QUOTES, 'UTF-8'); ?></small></div></div></div>
            </header>

            <section class="users-header">
                <div><h1>User Accounts</h1><p>View and find SecurePOS user accounts and linked employee profiles.</p></div>
                <button type="button" class="btn btn-primary" id="open-add-user">Add User</button>
            </section>

            <?php if ($flashMessage !== '') : ?><div class="alert users-alert" role="status"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

            <section class="panel">
                <div class="panel-header"><h3>Users</h3><span><?php echo count($users); ?> result<?php echo count($users) === 1 ? '' : 's'; ?></span></div>
                <form method="get" class="users-toolbar securepos-filter">
                    <input type="search" class="form-control" name="search" value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search name or email" aria-label="Search users by name or email">
                    <select class="form-select" name="role" aria-label="Filter users by role">
                        <?php foreach ($allowedRoles as $role) : ?><option value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $roleFilter === $role ? 'selected' : ''; ?>><?php echo $role === 'all' ? 'All Roles' : htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                    </select>
                    <select class="form-select" name="status" aria-label="Filter users by status">
                        <?php foreach ($allowedStatuses as $status) : ?><option value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo $status === 'all' ? 'All Status' : htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="users.php" class="btn btn-outline-light">Reset</a>
                </form>
                <div class="table-responsive">
                    <table class="table users-table">
                        <thead><tr><th class="col-name">Full Name</th><th class="col-email">Email</th><th class="col-role">Role</th><th class="col-employee">Employee Code</th><th class="col-status">Account Status</th><th class="col-face">Face Verification</th><th class="col-created">Created Date</th><th class="col-action">Action</th></tr></thead>
                        <tbody>
                        <?php if (!$users) : ?>
                            <tr><td colspan="8" class="users-empty">No users match the selected search and filters.</td></tr>
                        <?php else : ?>
                            <?php foreach ($users as $user) : ?>
                                <tr>
                                    <td class="col-name"><?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="col-email"><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="col-role"><span class="user-badge role"><?php echo htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td class="col-employee"><?php echo htmlspecialchars($user['employee_code'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="col-status"><span class="user-badge <?php echo strtolower($user['account_status']); ?>"><?php echo htmlspecialchars($user['account_status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                    <td class="col-face"><div class="face-enrollment-cell"><span class="face-status<?php echo (int)$user['face_enrolled'] === 1 ? ' enrolled' : ''; ?>"><?php echo (int)$user['face_enrolled'] === 1 ? '&#10003; Enrolled' : 'Not Enrolled'; ?></span><button type="button" class="face-enroll-button" data-user-id="<?php echo (int)$user['id']; ?>" data-user-name="<?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $user['account_status'] !== 'Active' ? 'disabled' : ''; ?>><?php echo (int)$user['face_enrolled'] === 1 ? 'Re-enroll' : 'Enroll'; ?></button></div></td>
                                    <td class="col-created"><?php echo htmlspecialchars((new DateTimeImmutable($user['created_at']))->format('d M Y'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="col-action"><div class="user-actions"><button type="button" class="edit-user-button" data-user-id="<?php echo (int)$user['id']; ?>" data-full-name="<?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>" data-email="<?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?>" data-role="<?php echo htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?>" data-status="<?php echo htmlspecialchars($user['account_status'], ENT_QUOTES, 'UTF-8'); ?>">Edit</button><button type="button" class="more-actions-button" aria-label="More actions for <?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>" aria-haspopup="menu" aria-controls="user-actions-menu" aria-expanded="false" data-user-id="<?php echo (int)$user['id']; ?>" data-user-name="<?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>">&#8230;</button></div></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
    <div class="user-actions-menu" id="user-actions-menu" role="menu" aria-hidden="true"><button type="button" id="menu-reset-password" role="menuitem">Reset Password</button></div>
    <div class="users-modal-backdrop <?php echo $showAddModal ? 'visible' : ''; ?>" id="add-user-modal" role="dialog" aria-modal="true" aria-labelledby="add-user-title">
        <div class="users-modal">
            <div class="users-modal-header">
                <div><h3 id="add-user-title">Add User</h3><p>Create a SecurePOS user account.</p></div>
                <button type="button" class="users-modal-close" id="close-add-user" aria-label="Close Add User modal">&times;</button>
            </div>
            <form method="post" class="users-form-grid" autocomplete="off">
                <input type="hidden" name="action" value="add_user">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['users_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($formError !== '') : ?><p class="users-form-error" role="alert"><?php echo htmlspecialchars($formError, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                <div class="users-form-group">
                    <label for="user-full-name">Full Name</label>
                    <input class="form-control" id="user-full-name" name="full_name" maxlength="100" value="<?php echo htmlspecialchars($formValues['full_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="users-form-group">
                    <label for="user-email">Email</label>
                    <input class="form-control" id="user-email" name="email" type="email" maxlength="100" value="<?php echo htmlspecialchars($formValues['email'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="users-form-group">
                    <label for="user-role">Role</label>
                    <select class="form-select" id="user-role" name="role" required>
                        <?php foreach ($userRoles as $role) : ?><option value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $formValues['role'] === $role ? 'selected' : ''; ?>><?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="users-form-group">
                    <label for="user-status">Account Status</label>
                    <select class="form-select" id="user-status" name="account_status" required>
                        <?php foreach ($userStatuses as $status) : ?><option value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $formValues['account_status'] === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="users-form-group">
                    <label for="user-password">Password</label>
                    <input class="form-control" id="user-password" name="password" type="password" minlength="8" autocomplete="new-password" required>
                </div>
                <div class="users-form-group">
                    <label for="user-confirm-password">Confirm Password</label>
                    <input class="form-control" id="user-confirm-password" name="confirm_password" type="password" minlength="8" autocomplete="new-password" required>
                </div>
                <div class="users-modal-actions"><button type="button" class="btn btn-outline-light" id="cancel-add-user">Cancel</button><button type="submit" class="btn btn-primary">Create User</button></div>
            </form>
        </div>
    </div>
    <div class="users-modal-backdrop <?php echo $showEditModal ? 'visible' : ''; ?>" id="edit-user-modal" role="dialog" aria-modal="true" aria-labelledby="edit-user-title">
        <div class="users-modal">
            <div class="users-modal-header">
                <div><h3 id="edit-user-title">Edit User</h3><p>Update account information and keep any linked employee profile synchronized.</p></div>
                <button type="button" class="users-modal-close" id="close-edit-user" aria-label="Close Edit User modal">&times;</button>
            </div>
            <form method="post" class="users-form-grid">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['users_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="user_id" id="edit-user-id" value="<?php echo htmlspecialchars($editValues['user_id'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($editError !== '') : ?><p class="users-form-error" role="alert"><?php echo htmlspecialchars($editError, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                <div class="users-form-group">
                    <label for="edit-full-name">Full Name</label>
                    <input class="form-control" id="edit-full-name" name="full_name" maxlength="100" value="<?php echo htmlspecialchars($editValues['full_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="users-form-group">
                    <label for="edit-email">Email</label>
                    <input class="form-control" id="edit-email" name="email" type="email" maxlength="100" value="<?php echo htmlspecialchars($editValues['email'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="users-form-group">
                    <label for="edit-role">Role</label>
                    <select class="form-select" id="edit-role" name="role" required>
                        <?php foreach ($userRoles as $role) : ?><option value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $editValues['role'] === $role ? 'selected' : ''; ?>><?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="users-form-group">
                    <label for="edit-status">Account Status</label>
                    <select class="form-select" id="edit-status" name="account_status" required>
                        <?php foreach ($userStatuses as $status) : ?><option value="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $editValues['account_status'] === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="users-modal-actions"><button type="button" class="btn btn-outline-light" id="cancel-edit-user">Cancel</button><button type="submit" class="btn btn-primary">Save Changes</button></div>
            </form>
        </div>
    </div>
    <div class="users-modal-backdrop <?php echo $showResetModal ? 'visible' : ''; ?>" id="reset-password-modal" role="dialog" aria-modal="true" aria-labelledby="reset-password-title">
        <div class="users-modal">
            <div class="users-modal-header">
                <div><h3 id="reset-password-title">Reset Password</h3><p>Issue a temporary password for <span id="reset-password-user-name"><?php echo htmlspecialchars($resetValues['full_name'], ENT_QUOTES, 'UTF-8'); ?></span>.</p></div>
                <button type="button" class="users-modal-close" id="close-reset-password" aria-label="Close Reset Password modal">&times;</button>
            </div>
            <form method="post" class="users-form-grid" autocomplete="off">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['users_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="user_id" id="reset-password-user-id" value="<?php echo htmlspecialchars($resetValues['user_id'], ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="full_name" id="reset-password-full-name" value="<?php echo htmlspecialchars($resetValues['full_name'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($resetError !== '') : ?><p class="users-form-error" role="alert"><?php echo htmlspecialchars($resetError, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                <div class="users-form-group"><label for="temporary-password">Temporary Password</label><input class="form-control" id="temporary-password" name="temporary_password" type="password" minlength="8" autocomplete="new-password" required></div>
                <div class="users-form-group"><label for="confirm-temporary-password">Confirm Temporary Password</label><input class="form-control" id="confirm-temporary-password" name="confirm_temporary_password" type="password" minlength="8" autocomplete="new-password" required></div>
                <p class="users-form-note full">The password-login lockout will be cleared. The user must replace this temporary password before accessing SecurePOS modules.</p>
                <div class="users-modal-actions"><button type="button" class="btn btn-outline-light" id="cancel-reset-password">Cancel</button><button type="submit" class="btn btn-primary">Issue Temporary Password</button></div>
            </form>
        </div>
    </div>
    <div class="users-modal-backdrop" id="face-enrollment-modal" role="dialog" aria-modal="true" aria-labelledby="face-enrollment-title">
        <div class="users-modal">
            <div class="users-modal-header">
                <div><h3 id="face-enrollment-title">Enroll Face</h3><p id="face-enrollment-user">Position exactly one face clearly in the camera.</p></div>
                <button type="button" class="users-modal-close" id="close-face-enrollment" aria-label="Close Face Enrollment modal">&times;</button>
            </div>
            <div class="face-video-wrap"><video class="face-video" id="face-enrollment-video" autoplay muted playsinline></video></div>
            <p class="face-enrollment-message" id="face-enrollment-message" role="status">Camera has not started.</p>
            <div class="users-modal-actions"><button type="button" class="btn btn-outline-light" id="cancel-face-enrollment">Cancel</button><button type="button" class="btn btn-primary" id="capture-face" disabled>Capture and Enroll</button></div>
        </div>
    </div>
    <script src="assets/js/dashboard.js"></script>
    <script src="assets/vendor/face-api/face-api.min.js"></script>
    <script>window.securePosFaceEnrollment = <?php echo json_encode(['csrfToken' => $_SESSION['users_csrf'], 'endpoint' => 'face_enrollment.php', 'modelPath' => 'assets/vendor/face-api/models'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
    <script src="assets/js/face_enrollment.js"></script>
    <script>
    (function () {
        var modal = document.getElementById('add-user-modal');
        var openButton = document.getElementById('open-add-user');
        var closeButton = document.getElementById('close-add-user');
        var cancelButton = document.getElementById('cancel-add-user');
        var editModal = document.getElementById('edit-user-modal');
        var editButtons = document.querySelectorAll('.edit-user-button');
        var closeEditButton = document.getElementById('close-edit-user');
        var cancelEditButton = document.getElementById('cancel-edit-user');
        var resetModal = document.getElementById('reset-password-modal');
        var closeResetButton = document.getElementById('close-reset-password');
        var cancelResetButton = document.getElementById('cancel-reset-password');
        var moreActionButtons = document.querySelectorAll('.more-actions-button');
        var actionsMenu = document.getElementById('user-actions-menu');
        var menuResetButton = document.getElementById('menu-reset-password');
        var activeActionsButton = null;

        function openModal() { modal.classList.add('visible'); document.body.classList.add('users-modal-open'); }
        function closeModal() { modal.classList.remove('visible'); document.body.classList.remove('users-modal-open'); }
        function closeEditModal() { editModal.classList.remove('visible'); document.body.classList.remove('users-modal-open'); }
        function closeResetModal() { resetModal.classList.remove('visible'); document.body.classList.remove('users-modal-open'); }
        function closeActionsMenu(restoreFocus) {
            if (!activeActionsButton) { return; }
            activeActionsButton.setAttribute('aria-expanded', 'false');
            actionsMenu.classList.remove('visible');
            actionsMenu.setAttribute('aria-hidden', 'true');
            if (restoreFocus) { activeActionsButton.focus(); }
            activeActionsButton = null;
        }
        function openActionsMenu(button) {
            if (activeActionsButton === button) { closeActionsMenu(true); return; }
            closeActionsMenu(false);
            activeActionsButton = button;
            button.setAttribute('aria-expanded', 'true');
            actionsMenu.classList.add('visible');
            actionsMenu.setAttribute('aria-hidden', 'false');
            var buttonRect = button.getBoundingClientRect();
            var menuWidth = actionsMenu.offsetWidth;
            var menuHeight = actionsMenu.offsetHeight;
            var left = Math.min(buttonRect.right - menuWidth, window.innerWidth - menuWidth - 8);
            left = Math.max(8, left);
            var belowTop = buttonRect.bottom + 6;
            var top = belowTop + menuHeight <= window.innerHeight - 8 ? belowTop : buttonRect.top - menuHeight - 6;
            actionsMenu.style.left = left + 'px';
            actionsMenu.style.top = Math.max(8, top) + 'px';
        }
        function openResetModal(button) {
            var userName = button.getAttribute('data-user-name');
            document.getElementById('reset-password-user-id').value = button.getAttribute('data-user-id');
            document.getElementById('reset-password-full-name').value = userName;
            document.getElementById('reset-password-user-name').textContent = userName;
            resetModal.classList.add('visible');
            document.body.classList.add('users-modal-open');
        }
        function openEditModal(button) {
            document.getElementById('edit-user-id').value = button.getAttribute('data-user-id');
            document.getElementById('edit-full-name').value = button.getAttribute('data-full-name');
            document.getElementById('edit-email').value = button.getAttribute('data-email');
            document.getElementById('edit-role').value = button.getAttribute('data-role');
            document.getElementById('edit-status').value = button.getAttribute('data-status');
            editModal.classList.add('visible');
            document.body.classList.add('users-modal-open');
        }

        openButton.addEventListener('click', openModal);
        closeButton.addEventListener('click', closeModal);
        cancelButton.addEventListener('click', closeModal);
        modal.addEventListener('click', function (event) { if (event.target === modal) { closeModal(); } });
        Array.prototype.forEach.call(editButtons, function (button) { button.addEventListener('click', function () { openEditModal(button); }); });
        closeEditButton.addEventListener('click', closeEditModal);
        cancelEditButton.addEventListener('click', closeEditModal);
        editModal.addEventListener('click', function (event) { if (event.target === editModal) { closeEditModal(); } });
        Array.prototype.forEach.call(moreActionButtons, function (button) {
            button.addEventListener('click', function (event) { event.stopPropagation(); openActionsMenu(button); });
            button.addEventListener('keydown', function (event) {
                if (event.key === 'ArrowDown') { event.preventDefault(); if (activeActionsButton !== button) { openActionsMenu(button); } menuResetButton.focus(); }
            });
        });
        menuResetButton.addEventListener('click', function () {
            var selectedButton = activeActionsButton;
            closeActionsMenu(false);
            if (selectedButton) { openResetModal(selectedButton); }
        });
        actionsMenu.addEventListener('click', function (event) { event.stopPropagation(); });
        document.addEventListener('click', function () { closeActionsMenu(false); });
        window.addEventListener('resize', function () { closeActionsMenu(false); });
        window.addEventListener('scroll', function () { closeActionsMenu(false); }, true);
        closeResetButton.addEventListener('click', closeResetModal);
        cancelResetButton.addEventListener('click', closeResetModal);
        resetModal.addEventListener('click', function (event) { if (event.target === resetModal) { closeResetModal(); } });
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape') { closeActionsMenu(true); closeModal(); closeEditModal(); closeResetModal(); } });
    }());
    </script>
</body>
</html>
