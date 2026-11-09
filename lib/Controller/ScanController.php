<?php

declare(strict_types=1);

namespace OCA\ScanToPdf\Controller;

use OCA\ScanToPdf\AppInfo\Application;
use OCA\ScanToPdf\Db\Job;
use OCA\ScanToPdf\Db\JobMapper;
use OCA\ScanToPdf\Service\JobRunner;
use OCA\ScanToPdf\Service\OptionBuilder;
use OCA\ScanToPdf\Service\Sane;
use OCA\ScanToPdf\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IBinaryFinder;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;

class ScanController extends Controller {
    private const USER_LIST_LIMIT = 500;
    private const RESOLUTIONS = [75, 150, 300, 600];

    public function __construct(
        IRequest $request,
        private JobMapper $jobMapper,
        private SettingsService $settings,
        private Sane $sane,
        private IRootFolder $rootFolder,
        private IBinaryFinder $binaryFinder,
        private ITimeFactory $timeFactory,
        private JobRunner $runner,
        private IUserManager $userManager,
        private IAppManager $appManager,
        private ?string $userId,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[FrontpageRoute(verb: 'GET', url: '/settings')]
    public function getSettings(): DataResponse {
        return new DataResponse([
            'settings' => $this->settings->get(),
            'available' => $this->sane->isAvailable(),
            'watcherRunning' => $this->sane->isWatcherRunning(),
            'setupCommand' => 'sudo sh ' . $this->appManager->getAppPath(Application::APP_ID) . '/scripts/setup-button.sh',
        ]);
    }

    #[FrontpageRoute(verb: 'PUT', url: '/settings')]
    public function saveSettings(string $device = '', string $source = '', string $side = 'front', string $paper = 'a4', int $textDpi = 300, int $photoDpi = 300, int $quality = 90, int $brightness = 0, int $contrast = 0, bool $deskew = false, bool $skipBlank = false, bool $buttonEnabled = false, string $buttonUser = '', string $buttonFolder = '', string $buttonKind = 'text', string $buttonKindDouble = 'photo', int $buttonWindow = 5): DataResponse {
        if (!in_array($side, ['front', 'back', 'both'], true) || !in_array($paper, ['auto', 'a4', 'a5', 'a6', 'letter', 'legal'], true)) {
            return new DataResponse(['message' => 'Invalid setting'], Http::STATUS_BAD_REQUEST);
        }

        $buttonUser = trim($buttonUser);
        $buttonFolder = trim(trim($buttonFolder), '/');
        if (!in_array($buttonKind, ['text', 'photo'], true) || !in_array($buttonKindDouble, ['', 'text', 'photo'], true)) {
            return new DataResponse(['message' => 'Invalid setting'], Http::STATUS_BAD_REQUEST);
        }
        if ($buttonEnabled) {
            if ($this->userManager->get($buttonUser) === null) {
                return new DataResponse(['message' => 'This user does not exist'], Http::STATUS_BAD_REQUEST);
            }
            if ($this->resolveFolder($buttonUser, $buttonFolder) === null) {
                return new DataResponse(['message' => 'The target folder does not exist'], Http::STATUS_BAD_REQUEST);
            }
        }

        $this->settings->save([
            'device' => $device,
            'source' => $source,
            'side' => $side,
            'paper' => $paper,
            'textDpi' => max(50, min(1200, $textDpi)),
            'photoDpi' => max(50, min(1200, $photoDpi)),
            'quality' => max(10, min(100, $quality)),
            'brightness' => max(-100, min(100, $brightness)),
            'contrast' => max(-100, min(100, $contrast)),
            'deskew' => $deskew ? 1 : 0,
            'skipBlank' => $skipBlank ? 1 : 0,
            'buttonEnabled' => $buttonEnabled ? 1 : 0,
            'buttonUser' => $buttonUser,
            'buttonFolder' => $buttonFolder,
            'buttonKind' => $buttonKind,
            'buttonKindDouble' => $buttonKindDouble,
            'buttonWindow' => max(3, min(15, $buttonWindow)),
        ]);
        return new DataResponse($this->settings->get());
    }

    #[FrontpageRoute(verb: 'GET', url: '/devices')]
    public function devices(): DataResponse {
        if (!$this->sane->isAvailable()) {
            return new DataResponse([]);
        }
        // A scanner held open by scanbd does not show up in the list
        $this->sane->pauseWatcher();
        $devices = $this->sane->devices();
        $this->sane->resumeWatcher();
        return new DataResponse($devices);
    }

    #[FrontpageRoute(verb: 'GET', url: '/capabilities')]
    public function capabilities(string $device = ''): DataResponse {
        $options = [];
        if ($device !== '' && $this->sane->isAvailable()) {
            $this->sane->pauseWatcher();
            $options = $this->sane->options($device);
            $this->sane->resumeWatcher();
        }
        return new DataResponse([
            'sources' => $options['source']['values'] ?? [],
            'deskew' => isset($options['swdeskew']),
            'skipBlank' => isset($options['swskip']['max']),
            'button' => OptionBuilder::hasButton($options),
        ]);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/defaults')]
    public function defaults(): DataResponse {
        return new DataResponse(['dpi' => $this->settings->get()['textDpi'], 'resolutions' => self::RESOLUTIONS]);
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'POST', url: '/scan')]
    public function scan(string $folder = '', string $kind = 'text', int $dpi = 0): DataResponse {
        if (!in_array($kind, ['text', 'photo'], true) || ($dpi !== 0 && !in_array($dpi, self::RESOLUTIONS, true))) {
            return new DataResponse(['message' => 'Invalid setting'], Http::STATUS_BAD_REQUEST);
        }
        if (!$this->sane->isAvailable()) {
            return new DataResponse(['message' => 'scanimage is not installed'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        $folder = trim($folder, '/');
        if ($this->resolveFolder($this->userId, $folder) === null) {
            return new DataResponse(['message' => 'The target folder does not exist'], Http::STATUS_BAD_REQUEST);
        }

        if ($this->jobMapper->hasActiveJob($this->timeFactory->getTime())) {
            return new DataResponse(['message' => 'The scanner is busy'], Http::STATUS_CONFLICT);
        }

        $job = $this->runner->create($this->userId, $folder, $kind, $dpi === 0 ? null : $dpi);

        // Scanning takes minutes, so it runs on its own instead of holding the request open
        $php = $this->binaryFinder->findBinaryPath('php');
        if ($php === false) {
            $job->setStatus(Job::STATUS_ERROR);
            $job->setMessage('The php command line program was not found');
            $this->jobMapper->update($job);
            return new DataResponse($job);
        }
        exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg(\OC::$SERVERROOT . '/occ') . ' scantopdf:run ' . (int)$job->getId() . ' >/dev/null 2>&1 &');

        return new DataResponse($job);
    }

    #[FrontpageRoute(verb: 'GET', url: '/users')]
    public function users(): DataResponse {
        $users = array_map(static fn (IUser $user) => ['id' => $user->getUID(), 'name' => $user->getDisplayName()], array_values($this->userManager->search('', self::USER_LIST_LIMIT)));
        usort($users, static fn (array $a, array $b) => strnatcasecmp($a['name'], $b['name']));
        return new DataResponse($users);
    }

    // Browses the folders of the user who receives the button scans, the regular file picker only knows the admin's own files
    #[FrontpageRoute(verb: 'GET', url: '/folders')]
    public function folders(string $user = '', string $path = ''): DataResponse {
        $path = trim($path, '/');
        $folder = $this->userManager->userExists($user) ? $this->resolveFolder($user, $path) : null;
        if ($folder === null) {
            return new DataResponse(['message' => 'The target folder does not exist'], Http::STATUS_NOT_FOUND);
        }

        $names = [];
        foreach ($folder->getDirectoryListing() as $node) {
            if ($node instanceof Folder) {
                $names[] = $node->getName();
            }
        }
        natcasesort($names);
        return new DataResponse(['path' => $path, 'folders' => array_values($names)]);
    }

    #[FrontpageRoute(verb: 'GET', url: '/jobs')]
    public function jobs(): DataResponse {
        return new DataResponse($this->jobMapper->findLatest(10));
    }

    private function resolveFolder(string $userId, string $path): ?Folder {
        if (in_array('..', explode('/', $path), true)) {
            return null;
        }
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $folder = $path === '' ? $userFolder : $userFolder->get($path);
        } catch (NotFoundException) {
            return null;
        }
        return $folder instanceof Folder && $folder->isCreatable() ? $folder : null;
    }

    #[NoAdminRequired]
    #[FrontpageRoute(verb: 'GET', url: '/job/{id}')]
    public function job(int $id): DataResponse {
        try {
            return new DataResponse($this->jobMapper->findForUser($id, $this->userId));
        } catch (DoesNotExistException) {
            return new DataResponse([], Http::STATUS_NOT_FOUND);
        }
    }
}
