<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace TwitterBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
            ->addOption('dateFrom', 'f', InputOption::VALUE_REQUIRED, 'Date from YYYY-MM-DD')
            ->addOption('dateTo', 't', InputOption::VALUE_OPTIONAL, 'Date to YYYY-MM-DD')
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
        $dateFrom = \DateTime::createFromFormat('Y-m-d', $input->getOption('dateFrom'));
        $dateTo = \DateTime::createFromFormat('Y-m-d', $input->getOption('dateTo'));
        foreach ($this->getContainer()->get('twitter_import_handler')->importTweets($dateFrom, $dateTo, $input->getOption('hashtag'), $input->getOption('max')) as $tweet) {
//            dump($tweet);
        }
        $output->writeln('Process finished OK');
    }
}
