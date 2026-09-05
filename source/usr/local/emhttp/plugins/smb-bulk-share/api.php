<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/scripts/smb-bulk-share.php';

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $controller = new SmbBulkShare();
    $action = (string)($_REQUEST['action'] ?? 'status');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action !== 'status') {
            respond(['ok' => false, 'error' => 'Unsupported request.'], 405);
        }
        respond(['ok' => true, 'data' => $controller->status()]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['ok' => false, 'error' => 'Unsupported request method.'], 405);
    }

    switch ($action) {
        case 'save_settings':
            $controller->saveSettings(
                (string)($_POST['auto_enable'] ?? 'no'),
                (string)($_POST['exclusions'] ?? '')
            );
            respond(['ok' => true, 'message' => 'Settings saved.', 'result' => ['changed' => null]]);

        case 'enable':
        case 'disable':
            $controller->saveSettings(
                (string)($_POST['auto_enable'] ?? 'no'),
                (string)($_POST['exclusions'] ?? '')
            );
            $result = $controller->perform($action, 'manual');
            $message = $result['changed'] === 0
                ? 'No included shares needed changing.'
                : sprintf('%d share(s) changed successfully. Backup: %s', $result['changed'], $result['backup']);
            respond(['ok' => true, 'message' => $message, 'result' => $result]);

        case 'restore':
            $result = $controller->restoreLatest();
            $message = $result['changed'] === 0
                ? 'There was no unrestored change to restore.'
                : sprintf('%d share(s) restored.', $result['changed']);
            respond(['ok' => true, 'message' => $message, 'result' => $result]);

        default:
            respond(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $error) {
    error_log('smb-bulk-share: ' . $error->getMessage());
    respond(['ok' => false, 'error' => $error->getMessage()], 500);
}
