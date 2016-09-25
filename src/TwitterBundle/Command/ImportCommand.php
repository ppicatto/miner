<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace TwitterBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @author Hernan Andres Picatto <hpicatto@uscd.edu>
 * @author Pablo Gabriel Picatto <p.picatto@gmail.com>
 */
class ImportCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('tweet-import')
            ->setDescription('Import tweets to MongoDb')
            ->addOption('dateFrom', 'f', InputOption::VALUE_REQUIRED, 'Date from Y-m-d')
            ->addOption('dateTo', 't', InputOption::VALUE_OPTIONAL, 'Date to Y-m-d')
            ->addOption('hashtag', '#', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Hastag without hash and spaces symbols')
            ->addOption('geocode', 'g', InputOption::VALUE_OPTIONAL, 'Geocode separated by comma')
            ->addOption('max', 'm', InputOption::VALUE_OPTIONAL, 'Maximum tweets retreived', 100)
            ->addOption('stop', 's', InputOption::VALUE_OPTIONAL, 'Stop mining')
            ->setHelp('
                This command allows you to imports tweets to MonogoDB filtering
                them hashtag, date from and date to.
                ex:
                `tweet-import -f 08-10-2016 -t 08-21-2016 -g 37.781157,-122.398720,1mi -m 100 -# hashtag1 -# hashtag2...`
            ')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);
        if(
            !($dateFrom = \DateTime::createFromFormat('Y-m-d', $input->getOption('dateFrom'))) ||
            !($dateTo = \DateTime::createFromFormat('Y-m-d', $input->getOption('dateTo')))
        ) {
            $io->error('Date to and date from are required with `Y-m-d` format');
            return;
        }
        if ($dateFrom > $dateTo) {
            $io->error('Date to could\'t be greather than date from');
            return;
        }
        $tweetsCount = 0;
        foreach ($this->getContainer()->get('twitter_import_handler')->importTweets($dateFrom, $dateTo, $input->getOption('hashtag'), $input->getOption('max')) as $tweet) {
            $tweetsCount++;
            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $output->writeln(sprintf(
                    'Importing tweet with external identifier <info>%s</info> and creation date <info>%s</info>',
                    $tweet['externalId'],
                    $tweet['createdAt']
                ));
            }
        }
        $io->block(sprintf('Imported %s in total', $tweetsCount), 'INFO', 'fg=black;bg=cyan;options=bold', ' ', true);
        $io->success('Process finished OK');
    }
}
