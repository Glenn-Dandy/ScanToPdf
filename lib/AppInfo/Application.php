<?php

declare(strict_types=1);

namespace OCA\ScanToPdf\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\ScanToPdf\Listener\LoadFilesScripts;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'scantopdf';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesScripts::class);
    }

    public function boot(IBootContext $context): void {
    }
}
