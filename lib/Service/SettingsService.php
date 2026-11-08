<?php

declare(strict_types=1);

namespace OCA\ScanToPdf\Service;

use OCA\ScanToPdf\AppInfo\Application;
use OCP\IConfig;

class SettingsService {
    private const DEFAULTS = [
        'device' => '',
        'source' => '',
        'side' => 'front',
        'paper' => 'a4',
        'textDpi' => 300,
        'photoDpi' => 300,
        'quality' => 90,
        'brightness' => 0,
        'contrast' => 0,
        'deskew' => 0,
        'skipBlank' => 0,
        'buttonEnabled' => 0,
        'buttonUser' => '',
        'buttonFolder' => '',
        'buttonKind' => 'text',
        'buttonKindDouble' => 'photo',
        'buttonWindow' => 5,
    ];

    public function __construct(private IConfig $config) {
    }

    public function get(): array {
        $settings = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $this->config->getAppValue(Application::APP_ID, $key, (string)$default);
            $settings[$key] = is_int($default) ? (int)$value : $value;
        }
        return $settings;
    }

    public function save(array $settings): void {
        foreach (self::DEFAULTS as $key => $default) {
            if (array_key_exists($key, $settings)) {
                $this->config->setAppValue(Application::APP_ID, $key, (string)$settings[$key]);
            }
        }
    }
}
