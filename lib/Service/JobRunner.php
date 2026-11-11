<?php

declare(strict_types=1);

namespace OCA\ScanToPdf\Service;

use OCA\ScanToPdf\Db\Job;
use OCA\ScanToPdf\Db\JobMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\ITempManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class JobRunner {
    public function __construct(
        private JobMapper $jobMapper,
        private SettingsService $settings,
        private Sane $sane,
        private IRootFolder $rootFolder,
        private ITempManager $tempManager,
        private IUserManager $userManager,
        private IUserSession $userSession,
        private ITimeFactory $timeFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function create(string $userId, string $folder, string $kind, ?int $dpi = null, ?string $paper = null, ?int $sheets = null): Job {
        $job = new Job();
        $job->setUserId($userId);
        $job->setCreated($this->timeFactory->getTime());
        $job->setStatus(Job::STATUS_WAITING);
        $job->setKind($kind);
        $job->setFolder($folder);
        $job->setDpi($dpi);
        $job->setPaper($paper);
        $job->setSheets($sheets);
        return $this->jobMapper->insert($job);
    }

    // scanbd releases the scanner itself before it runs the button script, so only other scans have to pause it
    public function run(int $jobId, bool $pauseWatcher = true): void {
        $job = $this->jobMapper->findById($jobId);
        if ($pauseWatcher) {
            // Sane::scan() retries while the scanner is still being released
            $this->sane->pauseWatcher(300000);
        }
        try {
            $this->scan($job);
        } catch (Throwable $e) {
            $this->logger->error('Scan to PDF failed: ' . $e->getMessage(), ['exception' => $e]);
            // The scanner may have been replaced, so look it up again next time
            $this->sane->forget($this->settings->get()['device']);
            $job->setStatus(Job::STATUS_ERROR);
            $job->setMessage(mb_substr($e->getMessage(), 0, 1000));
            $this->jobMapper->update($job);
        } finally {
            if ($pauseWatcher) {
                $this->sane->resumeWatcher();
            }
        }
    }

    private function scan(Job $job): void {
        $userId = $job->getUserId();
        $user = $this->userManager->get($userId);
        if ($user === null) {
            throw new RuntimeException('The user no longer exists');
        }
        $this->userSession->setVolatileActiveUser($user);

        $job->setStatus(Job::STATUS_SCANNING);
        $this->jobMapper->update($job);

        $settings = $this->settings->get();
        // A resolution or page size picked for this one scan beats the saved one
        if ($job->getDpi() !== null) {
            $settings['textDpi'] = $job->getDpi();
            $settings['photoDpi'] = $job->getDpi();
        }
        if ($job->getPaper() !== null) {
            $settings['paper'] = $job->getPaper();
        }
        $settings['sheets'] = $job->getSheets() ?? 0;
        $device = $settings['device'] !== '' ? $settings['device'] : ($this->sane->cachedDevices()[0]['id'] ?? '');
        if ($device === '') {
            throw new RuntimeException('No scanner found');
        }

        $directory = $this->tempManager->getTemporaryFolder();
        if ($directory === false) {
            throw new RuntimeException('Could not create a temporary folder');
        }
        $directory = rtrim($directory, '/');

        $args = OptionBuilder::build($this->sane->cachedOptions($device), $settings, $job->getKind());
        // scanimage itself can only write JPEG at a fixed low quality, so the pages arrive lossless
        $isText = $job->getKind() === 'text';
        $pages = $this->sane->scan($device, $args, $isText ? 'pnm' : 'png', $directory, function (int $count) use ($job): void {
            $job->setPages($count);
            $this->jobMapper->update($job);
        });

        $dpi = $job->getKind() === 'photo' ? $settings['photoDpi'] : $settings['textDpi'];
        $usedDpi = $this->usedDpi($args, $dpi);

        if ($isText) {
            // JPEG would only blur these pages and make them larger
            foreach ($pages as $page) {
                PageCleaner::cleanPnm($page, $usedDpi);
            }
        } else {
            $pages = $this->toJpeg($pages, $settings['quality'], $usedDpi);
        }
        $pdf = $directory . '/result.pdf';
        PdfWriter::write($pages, $usedDpi, $pdf);

        $userFolder = $this->rootFolder->getUserFolder($userId);
        $path = (string)$job->getFolder();
        $folder = $path === '' ? $userFolder : $userFolder->get($path);
        if (!($folder instanceof Folder)) {
            throw new RuntimeException('The target folder does not exist');
        }

        $name = $this->freeName($folder, 'Scan ' . date('Y-m-d H-i-s', $this->timeFactory->getTime()) . '.pdf');
        $file = $folder->newFile($name);
        $stream = fopen($pdf, 'rb');
        $file->putContent($stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        $job->setPages(count($pages));
        $job->setFileId($file->getId());
        $job->setFileName($name);
        $job->setStatus(Job::STATUS_DONE);
        $this->jobMapper->update($job);
    }

    /**
     * @param string[] $pages PNG files
     * @return string[] JPEG files, or the given files if the GD extension is missing
     */
    private function toJpeg(array $pages, int $quality, int $dpi): array {
        if (!function_exists('imagecreatefrompng')) {
            return $pages;
        }

        $result = [];
        foreach ($pages as $page) {
            $image = imagecreatefrompng($page);
            if ($image === false) {
                throw new RuntimeException('A scanned page is not a valid image');
            }
            PageCleaner::cleanImage($image, $dpi);
            $target = substr($page, 0, -3) . 'jpg';
            imagejpeg($image, $target, $quality);
            imagedestroy($image);
            unlink($page);
            $result[] = $target;
        }
        return $result;
    }

    // The scanner may have picked the nearest resolution it supports
    private function usedDpi(array $args, int $fallback): int {
        $index = array_search('--resolution', $args, true);
        return $index === false ? $fallback : (int)$args[$index + 1];
    }

    private function freeName(Folder $folder, string $name): string {
        if (!$folder->nodeExists($name)) {
            return $name;
        }
        $base = substr($name, 0, -4);
        $number = 1;
        while ($folder->nodeExists($base . '_' . $number . '.pdf')) {
            $number++;
        }
        return $base . '_' . $number . '.pdf';
    }
}
