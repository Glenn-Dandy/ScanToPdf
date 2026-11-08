<?php

declare(strict_types=1);

namespace OCA\ScanToPdf\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\ScanToPdf\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** @implements IEventListener<LoadAdditionalScriptsEvent> */
class LoadFilesScripts implements IEventListener {
    public function handle(Event $event): void {
        if ($event instanceof LoadAdditionalScriptsEvent) {
            Util::addInitScript(Application::APP_ID, 'files');
            Util::addStyle(Application::APP_ID, 'files');
        }
    }
}
