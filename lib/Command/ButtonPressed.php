<?php

declare(strict_types=1);

namespace OCA\ScanToPdf\Command;

use OCA\ScanToPdf\AppInfo\Application;
use OCA\ScanToPdf\Db\JobMapper;
use OCA\ScanToPdf\Service\JobRunner;
use OCA\ScanToPdf\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ButtonPressed extends Command {
    private const ACTIVE_LIMIT = 3600;

    public function __construct(
        private SettingsService $settings,
        private JobMapper $jobMapper,
        private JobRunner $runner,
        private ITimeFactory $timeFactory,
        private IConfig $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('scantopdf:button')
            ->setDescription('Starts a scan after the button on the scanner was pressed')
            ->addOption('finish', null, InputOption::VALUE_NONE, 'Internal: the time for further presses is over');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $settings = $this->settings->get();
        $now = $this->timeFactory->getTime();
        if (!$settings['buttonEnabled'] || $settings['buttonUser'] === '' || $this->jobMapper->hasActiveJob($now - self::ACTIVE_LIMIT)) {
            return 0;
        }

        if ($input->getOption('finish')) {
            $this->finish($settings);
        } elseif ($settings['buttonKindDouble'] === '') {
            // scanbd has released the scanner for this script
            $this->scan($settings, $settings['buttonKind'], false);
        } else {
            $this->countPress($settings, $now);
        }
        return 0;
    }

    // scanbd only watches the button again once this script has ended, so a second press has to be waited for elsewhere
    private function countPress(array $settings, int $now): void {
        $first = (int)$this->config->getAppValue(Application::APP_ID, 'buttonFirstPress', '0');
        if ($first > 0 && $now - $first <= $settings['buttonWindow']) {
            $presses = (int)$this->config->getAppValue(Application::APP_ID, 'buttonPresses', '1');
            $this->config->setAppValue(Application::APP_ID, 'buttonPresses', (string)($presses + 1));
            return;
        }

        $this->config->setAppValue(Application::APP_ID, 'buttonFirstPress', (string)$now);
        $this->config->setAppValue(Application::APP_ID, 'buttonPresses', '1');

        $finish = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(\OC::$SERVERROOT . '/occ') . ' scantopdf:button --finish';
        exec('nohup sh -c ' . escapeshellarg('sleep ' . (int)$settings['buttonWindow'] . '; exec ' . $finish) . ' >/dev/null 2>&1 &');
    }

    private function finish(array $settings): void {
        $presses = (int)$this->config->getAppValue(Application::APP_ID, 'buttonPresses', '1');
        $this->config->setAppValue(Application::APP_ID, 'buttonFirstPress', '0');
        $this->scan($settings, $presses >= 2 ? $settings['buttonKindDouble'] : $settings['buttonKind'], true);
    }

    private function scan(array $settings, string $kind, bool $pauseWatcher): void {
        $job = $this->runner->create($settings['buttonUser'], $settings['buttonFolder'], $kind);
        $this->runner->run($job->getId(), $pauseWatcher);
    }
}
