<?php

declare(strict_types=1);

namespace OCA\ScanToPdf\Service;

use OCA\ScanToPdf\AppInfo\Application;
use OCP\IConfig;
use RuntimeException;

class Sane {
    // Exit status of scanimage when the feeder ran empty
    private const STATUS_NO_DOCS = 7;

    // scanbd may need a moment to let go of the scanner
    private const OPEN_RETRIES = 6;
    private const OPEN_RETRY_DELAY = 400000;

    private const CACHE_DEVICES = 'cacheDevices';
    private const CACHE_OPTIONS = 'cacheOptions';

    public function __construct(private IConfig $config) {
    }

    // Not IBinaryFinder: it caches a missing program, so the settings would stay red after installing it
    private function find(): ?string {
        $paths = array_merge(explode(':', (string)getenv('PATH')), ['/usr/local/bin', '/usr/bin', '/bin', '/opt/bin']);
        foreach (array_unique(array_filter($paths)) as $path) {
            if (@is_executable($path . '/scanimage')) {
                return $path . '/scanimage';
            }
        }
        return null;
    }

    public function isAvailable(): bool {
        return $this->find() !== null;
    }

    private function binary(): string {
        return $this->find() ?? throw new RuntimeException('scanimage is not installed');
    }

    /** @return array<array{id: string, name: string}> */
    public function devices(): array {
        exec(escapeshellarg($this->binary()) . " -f '%d^%v %m%n' 2>/dev/null", $lines);
        $devices = [];
        foreach ($lines as $line) {
            [$id, $name] = array_pad(explode('^', $line, 2), 2, '');
            if ($id !== '') {
                $devices[] = ['id' => $id, 'name' => trim($name)];
            }
        }
        if ($devices !== []) {
            $this->remember(self::CACHE_DEVICES, $devices);
        }
        return $devices;
    }

    // Asking a scanner for its list and options takes seconds, and neither changes between scans
    public function cachedDevices(): array {
        return $this->recall(self::CACHE_DEVICES) ?? $this->devices();
    }

    public function cachedOptions(string $device): array {
        return $this->recall(self::CACHE_OPTIONS . md5($device)) ?? $this->options($device);
    }

    public function forget(string $device): void {
        $this->config->deleteAppValue(Application::APP_ID, self::CACHE_DEVICES);
        $this->config->deleteAppValue(Application::APP_ID, self::CACHE_OPTIONS . md5($device));
    }

    private function remember(string $key, array $value): void {
        $this->config->setAppValue(Application::APP_ID, $key, json_encode($value));
    }

    private function recall(string $key): ?array {
        $value = json_decode($this->config->getAppValue(Application::APP_ID, $key, ''), true);
        return is_array($value) && $value !== [] ? $value : null;
    }

    public function isWatcherRunning(): bool {
        exec('pgrep -x scanbd 2>/dev/null', $output, $exitCode);
        return $exitCode === 0;
    }

    // scanbd holds the scanner open to watch its button, USR1 makes it let go until USR2.
    // It keeps root as its real user, so the web server needs the sudo rule from setup-button.sh.
    public function pauseWatcher(int $settleMicroseconds = 1000000): void {
        if ($this->isWatcherRunning()) {
            exec('sudo -n pkill -USR1 -x scanbd 2>/dev/null');
            usleep($settleMicroseconds);
        }
    }

    public function resumeWatcher(): void {
        if ($this->isWatcherRunning()) {
            exec('sudo -n pkill -USR2 -x scanbd 2>/dev/null');
        }
    }

    public function options(string $device): array {
        exec(escapeshellarg($this->binary()) . ' -d ' . escapeshellarg($device) . ' -A 2>/dev/null', $lines);
        $options = OptionBuilder::parseOptions(implode("\n", $lines));
        if ($options !== []) {
            $this->remember(self::CACHE_OPTIONS . md5($device), $options);
        }
        return $options;
    }

    /**
     * Scans into $directory as page0001.jpeg, page0002.jpeg, ... or .png
     *
     * @param callable(int): void $onPage called with the number of pages scanned so far
     * @return string[] paths of the scanned pages in order
     */
    public function scan(string $device, array $args, string $format, string $directory, callable $onPage): array {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->runScan($device, $args, $format, $directory, $onPage);
            } catch (RuntimeException $e) {
                if ($attempt >= self::OPEN_RETRIES || preg_match('/open of device .* failed/', $e->getMessage()) !== 1) {
                    throw $e;
                }
                usleep(self::OPEN_RETRY_DELAY);
            }
        }
    }

    private function runScan(string $device, array $args, string $format, string $directory, callable $onPage): array {
        $command = array_merge([$this->binary(), '-d', $device, '--format=' . $format, '--batch=' . $directory . '/page%04d.' . $format], $args);
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('scanimage could not be started');
        }

        $lastLine = '';
        $pages = 0;
        while (($line = fgets($pipes[2])) !== false) {
            if (preg_match('/^Scanned page (\d+)/', $line) === 1) {
                $onPage(++$pages);
            } elseif (trim($line) !== '') {
                $lastLine = trim($line);
            }
        }
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $files = glob($directory . '/page*.' . $format) ?: [];
        sort($files, SORT_NATURAL);
        // An interrupted page leaves an empty file behind
        $files = array_values(array_filter($files, static fn (string $file) => filesize($file) > 0));

        if ($files === []) {
            throw new RuntimeException($exitCode === self::STATUS_NO_DOCS ? 'There is no paper in the scanner' : ($lastLine ?: 'The scanner did not deliver a page'));
        }
        if ($exitCode !== 0 && $exitCode !== self::STATUS_NO_DOCS) {
            throw new RuntimeException($lastLine ?: 'scanimage failed with exit code ' . $exitCode);
        }

        return $files;
    }
}
