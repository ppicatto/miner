<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace TwitterBundle\Command;

use Goutte\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\{InputOption, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @author Hernan Andres Picatto <hpicatto@uscd.edu>
 * @author Pablo Gabriel Picatto <p.picatto@gmail.com>
 */
class ImportCommand extends Command
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
        foreach ($this->getTweets($input) as $response) {
            if (!is_array($response)) {
                $output->writeln($response);
            } else {
                dump($response);
            }
        }
    }

    
    private function getTweets(InputInterface $input)
    {
        $hashtag = '%23' . implode('%28OR%28%23', $input->getOption('hashtag') );
        $baseUrl = 'https://twitter.com/search?f=tweets&vertical=default&q='.$hashtag.'%20since%3A'.$input->getOption('dateFrom').'%20until%3A'.$input->getOption('dateTo').'%20include%3Aretweets&src=typd&count='.$input->getOption('max');
        $mongoClient = new \MongoClient();
        $db = $mongoClient->selectDB("twitter"); 
        $metadata = [
            'url' => $baseUrl,
            'pageNumber' => '1'
        ];
        $db->tweetMetadata->insert($metadata);
        $client = new Client();
        $crawler = $client->request('GET', $baseUrl);
        $newTweets = $this->parseTweets($crawler);
        $response = $newTweets;
        yield 'Added new ' . count($newTweets);
        while ($newTweets) {
            $i = 2;
            foreach ($newTweets as $tweet) {
                $tweet['metadataId'] = $metadata['_id'];
                $db->tweet->insert($tweet);
            }
            $mongoClient = new \MongoClient();
            $db = $mongoClient->selectDB("twitter");  
            sleep(1);
            $q = 'https://twitter.com/i/search/timeline?f=tweets&vertical=default&q='.$hashtag.'%20since%3A'.$input->getOption('dateFrom').'%20until%3A'.$input->getOption('dateTo').'%20include%3Aretweets&src=typd&include_available_features=1&include_entities=1&last_note_ts=3099&max_position=TWEET-'.$crawler->filter('.js-original-tweet')->last()->attr('data-item-id').'-'.$crawler->filter('.js-original-tweet')->first()->attr('data-item-id').'-BD1UO2FFu9QAAAAAAAAETAAAAAcAAAASAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA&reset_error_state=false';
            $metadata = [
                'url' => $q,
                'pageNumber' => $i
            ];
            $db->tweetMetadata->insert($metadata);
            yield $q;
            $client->request('GET', $q);
            $html = json_decode($client->getResponse()->getContent(), true)['items_html'];
            $crawler = new Crawler($html);
            $newTweets = $this->parseTweets($crawler);
            $i++;
            yield 'Added new ' . count($newTweets);
            $response = array_merge($response, $newTweets);
        }

        yield [
            'tweet' => $response,
            'totalCount' => count($response),
            'success' => true,
        ];
        
    }

    /**
     * @param Crawler $crawler
     *
     * @return array
     */
    private function parseTweets(Crawler $crawler)
    {

        return $crawler->filter('li.stream-item')->each(function ($node) {
            return [
                'screenname' => $node->filter('.fullname')->text(),
                'username' => $node->filter('.username b')->text(),
                'text' => $node->filter('.TweetTextSize.js-tweet-text.tweet-text')->text(),
                'createdAt' => date('Y-m-d H:i:s', $node->filter('._timestamp.js-short-timestamp')->attr('data-time')),
                'tweetId' => $node->attr('data-item-id'),
            ];
        });
    }
}
