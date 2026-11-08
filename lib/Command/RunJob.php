<?php

declare(strict_types=1);

namespace OCA\ScanToPdf\Command;

use OCA\ScanToPdf\Service\JobRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunJob extends Command {
    public function __construct(private JobRunner $runner) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('scantopdf:run')
            ->setDescription('Runs a queued scan')
            ->addArgument('job', InputArgument::REQUIRED, 'ID of the scan job');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $this->runner->run((int)$input->getArgument('job'));
        return 0;
    }
}
