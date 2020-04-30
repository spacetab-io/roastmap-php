<?php

declare(strict_types=1);

namespace Prerender\Roastmap\Console;

use Amp\Loop;
use League\Uri\Uri;
use Prerender\Roastmap\Roastmap;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends Command
{
    protected function configure()
    {
        $this->setName('start');
        $this->setHelp('Example of usage: `roastmap start`.');
        $this->addArgument('uri', InputArgument::REQUIRED, 'Uri address used for cache warm-up. Example: https://google.com');
        $this->addOption('times', 't', InputOption::VALUE_OPTIONAL, 'Repeat counts.', 1);
        $this->addOption('parallel', 'p', InputOption::VALUE_OPTIONAL, 'Maximum parallel requests for one time.', 3);
        $this->addOption('delay', 'd', InputOption::VALUE_OPTIONAL, 'Delay between parallel requests in ms.', 3000);
    }

    /**
     * Execute command, captain.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uri = Uri::createFromString($input->getArgument('uri'));

        $stats = [];
        Loop::run(static function () use ($uri, &$stats) {
            $roastmap = new Roastmap($uri);
            $stats = yield $roastmap->run();
        });

        $rows = [];
        foreach ($stats as $url => $item) {
            $rows[] = [$url, $item[0], $item[1]];
        }

        $rows[] = ['Total', '', count($stats)];

        $table = new Table($output);
        $table
            ->setHeaders(['Url', 'Status', 'Body Length'])
            ->setRows($rows);

        $table->render();

        return 0;
    }
}
