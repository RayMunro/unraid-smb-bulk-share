<?php
declare(strict_types=1);

final class SmbBulkShare
{
    private string $shareConfigDir;
    private string $userShareDir;
    private string $secIni;
    private string $sharesIni;
    private string $settingsFile;
    private string $backupDir;
    private string $varIni;
    private string $emhttpSocket;
    private bool $testMode;

    public function __construct()
    {
        $this->shareConfigDir = getenv('SMB_BULK_SHARE_CONFIG_DIR') ?: '/boot/config/shares';
        $this->userShareDir = getenv('SMB_BULK_SHARE_USER_DIR') ?: '/mnt/user';
        $this->secIni = getenv('SMB_BULK_SHARE_SEC_INI') ?: '/var/local/emhttp/sec.ini';
        $this->sharesIni = getenv('SMB_BULK_SHARE_SHARES_INI') ?: '/var/local/emhttp/shares.ini';
        $this->settingsFile = getenv('SMB_BULK_SHARE_SETTINGS') ?: '/boot/config/plugins/smb-bulk-share/settings.cfg';
        $this->backupDir = getenv('SMB_BULK_SHARE_BACKUPS') ?: '/boot/config/plugins/smb-bulk-share/backups';
        $this->varIni = getenv('SMB_BULK_SHARE_VAR_INI') ?: '/var/local/emhttp/var.ini';
        $this->emhttpSocket = getenv('SMB_BULK_SHARE_SOCKET') ?: '/var/run/emhttpd.socket';
        $this->testMode = getenv('SMB_BULK_SHARE_TEST_MODE') === '1';
    }

    public function loadSettings(): array
    {
        $settings = is_file($this->settingsFile) ? (parse_ini_file($this->settingsFile) ?: []) : [];
        $auto = strtolower((string)($settings['AUTO_ENABLE'] ?? 'no')) === 'yes' ? 'yes' : 'no';
        $exclusions = $this->normaliseExclusions((string)($settings['EXCLUSIONS'] ?? 'appdata,domains,system,isos'));
        return ['auto_enable' => $auto, 'exclusions' => $exclusions];
    }

    public function saveSettings(string $autoEnable, string $exclusions): void
    {
        $auto = strtolower($autoEnable) === 'yes' ? 'yes' : 'no';
        $items = $this->normaliseExclusions($exclusions);
        $content = 'AUTO_ENABLE="' . $auto . '"' . "\n"
            . 'EXCLUSIONS="' . $this->iniEscape(implode(',', $items)) . '"' . "\n";
        $this->atomicWrite($this->settingsFile, $content, 0644);
    }

    public function status(): array
    {
        $settings = $this->loadSettings();
        $excludedLookup = array_fill_keys(array_map('strtolower', $settings['exclusions']), true);
        $shares = $this->discoverShares();
        $enabled = 0;
        $disabled = 0;
        $excluded = 0;

        foreach ($shares as &$share) {
            $share['excluded'] = isset($excludedLookup[strtolower($share['name'])]);
            $share['export_label'] = $this->exportLabel($share['export']);
            $share['security_label'] = ucfirst($share['security']);
            $share['export'] === '-' ? $disabled++ : $enabled++;
            if ($share['excluded']) {
                $excluded++;
            }
        }
        unset($share);

        $var = is_file($this->varIni) ? (parse_ini_file($this->varIni) ?: []) : [];
        $global = isset($var['shareSMBEnabled']) ? $var['shareSMBEnabled'] !== 'no' : null;

        return [
            'counts' => ['total' => count($shares), 'enabled' => $enabled, 'disabled' => $disabled, 'excluded' => $excluded],
            'settings' => $settings,
            'smb_global_enabled' => $global,
            'can_restore' => $this->latestRestorableBackup() !== null,
            'shares' => array_values($shares),
        ];
    }

    public function perform(string $action, string $source = 'manual'): array
    {
        if (!in_array($action, ['enable', 'disable'], true)) {
            throw new InvalidArgumentException('Unsupported bulk action.');
        }

        $settings = $this->loadSettings();
        if ($source === 'auto' && $settings['auto_enable'] !== 'yes') {
            return ['changed' => 0, 'backup' => null, 'skipped' => 'Automatic mode is disabled.'];
        }

        $excluded = array_fill_keys(array_map('strtolower', $settings['exclusions']), true);
        $targets = [];
        foreach ($this->discoverShares() as $share) {
            if (isset($excluded[strtolower($share['name'])])) {
                continue;
            }
            if ($action === 'enable' && $share['export'] === '-') {
                $targets[] = $share + ['desired_export' => 'e'];
            } elseif ($action === 'disable' && $share['export'] !== '-') {
                $targets[] = $share + ['desired_export' => '-'];
            }
        }

        if (!$targets) {
            return ['changed' => 0, 'backup' => null];
        }

        $this->assertRuntimeReady();
        $backup = $this->createBackup($action, $source, $targets);
        $this->pruneBackups(30);
        $completed = [];

        try {
            foreach ($targets as $share) {
                $this->applyShare($share['name'], $share['desired_export'], $share['security']);
                $completed[] = $share;
            }
        } catch (Throwable $error) {
            $rollbackErrors = [];
            foreach (array_reverse($completed) as $share) {
                try {
                    $this->applyShare($share['name'], $share['export'], $share['security']);
                } catch (Throwable $rollbackError) {
                    $rollbackErrors[] = $share['name'];
                }
            }
            $suffix = $rollbackErrors ? ' Rollback also failed for: ' . implode(', ', $rollbackErrors) . '.' : ' Earlier changes were rolled back.';
            throw new RuntimeException($error->getMessage() . $suffix);
        }

        return ['changed' => count($completed), 'backup' => basename($backup)];
    }

    public function restoreLatest(): array
    {
        $backup = $this->latestRestorableBackup();
        if ($backup === null) {
            return ['changed' => 0, 'backup' => null];
        }

        $manifestFile = $backup . '/manifest.json';
        $manifest = json_decode((string)file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
        $targets = $manifest['targets'] ?? [];
        if (!$targets) {
            return ['changed' => 0, 'backup' => basename($backup)];
        }

        $this->assertRuntimeReady();
        foreach ($targets as $share) {
            $this->applyShare((string)$share['name'], (string)$share['export'], (string)$share['security']);
        }
        $manifest['restored_at'] = gmdate('c');
        $this->atomicWrite($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
        return ['changed' => count($targets), 'backup' => basename($backup)];
    }

    private function discoverShares(): array
    {
        $names = [];
        $effective = [];

        if (is_file($this->secIni)) {
            $sections = parse_ini_file($this->secIni, true) ?: [];
            foreach ($sections as $name => $values) {
                if ($this->validShareName((string)$name)) {
                    $effective[(string)$name] = [
                        'export' => $this->normaliseExport((string)($values['export'] ?? '-')),
                        'security' => $this->normaliseSecurity((string)($values['security'] ?? 'public')),
                    ];
                }
            }
        }

        if (is_file($this->sharesIni)) {
            $sections = parse_ini_file($this->sharesIni, true) ?: [];
            foreach (array_keys($sections) as $name) {
                if ($this->validShareName((string)$name) && strtolower((string)$name) !== 'flash') {
                    $names[(string)$name] = true;
                }
            }
        }

        if (is_dir($this->userShareDir)) {
            foreach (new DirectoryIterator($this->userShareDir) as $entry) {
                if (!$entry->isDot() && $entry->isDir() && strtolower($entry->getFilename()) !== 'flash' && $this->validShareName($entry->getFilename())) {
                    $names[$entry->getFilename()] = true;
                }
            }
        }

        uksort($names, 'strnatcasecmp');
        $shares = [];
        foreach (array_keys($names) as $name) {
            $cfgFile = $this->shareConfigDir . '/' . $name . '.cfg';
            $cfg = is_file($cfgFile) ? (parse_ini_file($cfgFile) ?: []) : [];
            $export = $effective[$name]['export'] ?? $this->normaliseExport((string)($cfg['shareExport'] ?? 'e'));
            $security = $effective[$name]['security'] ?? $this->normaliseSecurity((string)($cfg['shareSecurity'] ?? 'public'));
            $shares[] = ['name' => $name, 'export' => $export, 'security' => $security, 'has_config' => is_file($cfgFile)];
        }
        return $shares;
    }

    private function applyShare(string $name, string $export, string $security): void
    {
        if (!$this->validShareName($name)) {
            throw new InvalidArgumentException('Invalid share name.');
        }
        $export = $this->normaliseExport($export);
        $security = $this->normaliseSecurity($security);

        if ($this->testMode) {
            $file = $this->shareConfigDir . '/' . $name . '.cfg';
            $cfg = is_file($file) ? (parse_ini_file($file) ?: []) : [];
            $cfg['shareExport'] = $export;
            $cfg['shareSecurity'] = $security;
            $text = '';
            foreach ($cfg as $key => $value) {
                $text .= $key . '="' . $this->iniEscape((string)$value) . '"' . "\n";
            }
            $this->atomicWrite($file, $text, 0644);
            return;
        }

        $var = parse_ini_file($this->varIni) ?: [];
        $token = (string)($var['csrf_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Unraid CSRF token is unavailable; no changes were made.');
        }

        $post = http_build_query([
            'shareName' => $name,
            'shareExport' => $export,
            'shareSecurity' => $security,
            'changeShareSecurity' => 'Apply',
            'csrf_token' => $token,
        ], '', '&', PHP_QUERY_RFC3986);

        $command = '/usr/bin/curl --silent --show-error --fail --max-time 30'
            . ' --unix-socket ' . escapeshellarg($this->emhttpSocket)
            . ' --data ' . escapeshellarg($post)
            . ' ' . escapeshellarg('http://localhost/update.htm') . ' 2>&1';
        exec($command, $output, $status);
        if ($status !== 0) {
            throw new RuntimeException('Unraid rejected the update for share "' . $name . '": ' . trim(implode("\n", $output)));
        }

        $cfgFile = $this->shareConfigDir . '/' . $name . '.cfg';
        clearstatcache(true, $cfgFile);
        $cfg = is_file($cfgFile) ? (parse_ini_file($cfgFile) ?: []) : [];
        if (($cfg['shareExport'] ?? null) !== $export) {
            throw new RuntimeException('The update for share "' . $name . '" could not be verified.');
        }
    }

    private function createBackup(string $action, string $source, array $targets): string
    {
        $suffix = bin2hex(random_bytes(2));
        $path = $this->backupDir . '/' . gmdate('Ymd-His') . '-' . $action . '-' . $suffix;
        $filesPath = $path . '/shares';
        if (!mkdir($filesPath, 0700, true) && !is_dir($filesPath)) {
            throw new RuntimeException('Unable to create the share configuration backup.');
        }

        foreach (glob($this->shareConfigDir . '/*.cfg', GLOB_NOSORT) ?: [] as $file) {
            if (!copy($file, $filesPath . '/' . basename($file))) {
                throw new RuntimeException('Unable to back up ' . basename($file) . '.');
            }
        }

        $originals = array_map(static function (array $share): array {
            return ['name' => $share['name'], 'export' => $share['export'], 'security' => $share['security']];
        }, $targets);
        $manifest = [
            'created_at' => gmdate('c'),
            'action' => $action,
            'source' => $source,
            'targets' => $originals,
        ];
        $this->atomicWrite($path . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
        return $path;
    }

    private function latestRestorableBackup(): ?string
    {
        $paths = glob($this->backupDir . '/*/manifest.json', GLOB_NOSORT) ?: [];
        usort($paths, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach ($paths as $manifestFile) {
            $manifest = json_decode((string)@file_get_contents($manifestFile), true);
            if (is_array($manifest) && empty($manifest['restored_at']) && !empty($manifest['targets'])) {
                return dirname($manifestFile);
            }
        }
        return null;
    }

    private function pruneBackups(int $keep): void
    {
        $paths = glob($this->backupDir . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [];
        usort($paths, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($paths, $keep) as $path) {
            $this->removeBackupTree($path);
        }
    }

    private function removeBackupTree(string $path): void
    {
        $root = realpath($this->backupDir);
        $target = realpath($path);
        if ($root === false || $target === false || !str_starts_with($target . '/', rtrim($root, '/') . '/') || $target === $root) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($target);
    }

    private function assertRuntimeReady(): void
    {
        if ($this->testMode) {
            return;
        }
        if (!file_exists($this->emhttpSocket) || !is_file($this->varIni)) {
            throw new RuntimeException('The Unraid management service is not ready. Start the array and try again.');
        }
        if (!is_executable('/usr/bin/curl')) {
            throw new RuntimeException('The curl command required to update Unraid is unavailable.');
        }
    }

    private function atomicWrite(string $path, string $content, int $mode): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create ' . $directory . '.');
        }
        $temporary = tempnam($directory, '.smbbulk-');
        if ($temporary === false || file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write ' . basename($path) . '.');
        }
        chmod($temporary, $mode);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to replace ' . basename($path) . '.');
        }
    }

    private function normaliseExclusions(string $value): array
    {
        $items = preg_split('/[,\r\n]+/', $value) ?: [];
        $clean = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item !== '' && $this->validShareName($item)) {
                $clean[strtolower($item)] = $item;
            }
        }
        natcasesort($clean);
        return array_values($clean);
    }

    private function validShareName(string $name): bool
    {
        return $name !== '' && $name !== '.' && $name !== '..' && !str_contains($name, '/') && !preg_match('/[\x00-\x1F\x7F]/', $name);
    }

    private function normaliseExport(string $export): string
    {
        return in_array($export, ['-', 'e', 'eh', 'et', 'eth'], true) ? $export : '-';
    }

    private function normaliseSecurity(string $security): string
    {
        return in_array($security, ['public', 'secure', 'private'], true) ? $security : 'public';
    }

    private function exportLabel(string $export): string
    {
        return [
            '-' => 'No',
            'e' => 'Yes',
            'eh' => 'Yes (hidden)',
            'et' => 'Yes / Time Machine',
            'eth' => 'Yes / Time Machine (hidden)',
        ][$export] ?? 'No';
    }

    private function iniEscape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $controller = new SmbBulkShare();
        $command = $argv[1] ?? '--status';
        if ($command === '--auto') {
            $result = $controller->perform('enable', 'auto');
        } elseif ($command === '--enable') {
            $result = $controller->perform('enable', 'cli');
        } elseif ($command === '--disable') {
            $result = $controller->perform('disable', 'cli');
        } elseif ($command === '--restore') {
            $result = $controller->restoreLatest();
        } elseif ($command === '--status') {
            $result = $controller->status();
        } else {
            throw new InvalidArgumentException('Usage: smb-bulk-share.php [--status|--enable|--disable|--restore|--auto]');
        }
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'smb-bulk-share: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
