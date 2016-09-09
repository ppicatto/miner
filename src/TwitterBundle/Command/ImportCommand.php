<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace TwitterBundle\Command;

use Goutte\Client;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;
use TwitterAPIExchange;

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
        $dateFrom = new \DateTime($input->getOption('dateFrom'));
        $dateTo = new \DateTime($input->getOption('dateTo'));
        while ($dateFrom < $dateTo) {
            $startAt = clone $dateFrom;
            $dateFrom->modify('+1 day');
            $output->writeln(sprintf('Importing from <info>%s</info> to <info>%s</info>', $startAt->format('Y-m-d'), $dateFrom->format('Y-m-d')));
            foreach ($this->getTweets($input->getOption('hashtag'), $startAt->format('Y-m-d'), $dateFrom->format('Y-m-d'), $input->getOption('max')) as $page) {
                $output->writeln(sprintf('Importing page <info>%s</info> with <info>%s</info> items', $page['number'], $page['items']));
                if ($page['pageUrl']) {
                    $output->writeln(sprintf('<info>%s</info>', $page['pageUrl']));
                }
            }
        }
        $output->writeln('Process finished OK');
    }

    
    private function getTweets(array $hashtag = [], $dateFrom, $dateTo, $max = 100)
    {
        $mongoClient = new \MongoClient();
        $db = $mongoClient->selectDB("twitter"); 
        $previousMetadata = $db->tweetMetadata->find()->sort(['createdAt'=>-1])->limit(1)->next();
        $newTweets = [];
        if (!$previousMetadata) {
            $hashtag = '%23' . implode('%28OR%28%23', $hashtag);
            $baseUrl = 'https://twitter.com/search?f=tweets&vertical=default&q='.$hashtag.'%20since%3A'.$dateFrom.'%20until%3A'.$dateTo.'%20include%3Aretweets&src=typd&count='.$max;

            $client = new Client();
            $crawler = $client->request('GET', $baseUrl);
            $newTweets = $this->parseTweets($crawler);
            $metadata = [
                'firstTweet' => $crawler->filter('.js-original-tweet')->last()->attr('data-item-id'),
                'lastTweet' => $crawler->filter('.js-original-tweet')->first()->attr('data-item-id'),
                'url' => $baseUrl,
                'pageNumber' => 1
            ];
            $db->tweetMetadata->insert($metadata);
            if ($newTweets) {
                $this->persistTweets($newTweets, $metadata);
            }
            $response = $newTweets;
            yield [
                'pageUrl' => $baseUrl,
                'number' => 1,
                'items' => count($newTweets)
            ];      
            $pageNumber = 2;
        } else {
            $pageNumber = $previousMetadata['pageNumber'];
        }
        
        while ($newTweets || $previousMetadata) {
            if ($previousMetadata) {
                $metadata = $previousMetadata;
                $newTweets = $this->getNextPage($metadata['url']);
                $previousMetadata = null;
            } else {
                $metadata = [
                    'pageNumber' => $pageNumber,
                    'url' => 'https://twitter.com/i/search/timeline?f=tweets&vertical=default&q='.$hashtag.'%20since%3A'.$dateFrom.'%20until%3A'.$dateTo.'%20include%3Aretweets&src=typd&include_available_features=1&include_entities=1&last_note_ts=3099&max_position=TWEET-'.$metadata['lastTweet'].'-'.$metadata['firstTweet'].'-BD1UO2FFu9QAAAAAAAAETAAAAAcAAAASAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA&reset_error_state=false',
                ];
                $newTweets = $this->getNextPage($metadata['url']);
                $metadata['firstTweet'] = $newTweets['firstTweet'];
                $metadata['lastTweet'] = $newTweets['lastTweet'];
                $db->tweetMetadata->insert($metadata);
            }
            if ($newTweets['tweets']) {
                $this->persistTweets($newTweets['tweets'], $metadata);
            }
            yield [
                'pageUrl' => $metadata['url'],
                'number' => $pageNumber,
                'items' => count($newTweets['tweets']),
            ];
            $response = array_merge($response, $newTweets['tweets']);
            $pageNumber += 1;
        }

        yield [
            'number' => $pageNumber,
            'items' => count($newTweets['tweets']),
            'success' => true,
        ]; 
    }

    private function persistTweets($tweets, $metadata) {
        $mongoClient = new \MongoClient();
        $db = $mongoClient->selectDB("twitter"); 
        foreach ($tweets as $tweet) {
            if (!$db->tweet->findOne(['tweetId' => $tweet['tweetId']])) {
                $tweet['metadataId'] = $metadata['_id'];
                $db->tweet->insert($tweet);
            }
        }
    }
    
    private function getNextPage($url) {
        sleep(4);
        $client = new Client();
        $client->request('GET', $url);
        $html = json_decode($client->getResponse()->getContent(), true)['items_html'];
        $crawler = new Crawler($html);

        return [
            'firstTweet' => $crawler->filter('.js-original-tweet')->last()->attr('data-item-id'),
            'lastTweet' => $crawler->filter('.js-original-tweet')->first()->attr('data-item-id'),
            'tweets' => $this->parseTweets($crawler),
        ];
        
    }

    /**
     * @param string $twitterPlaceId
     */
    private function getCoordinates($twitterPlaceId) {
        if (!$twitterPlaceId) {
            return null;
        }
        $mongoClient = new \MongoClient();
        $db = $mongoClient->selectDB("twitter");

        if ($place = $db->places->findOne(['id' => $twitterPlaceId])) {

            return $place['_id'];
        }
        $client = new Client();
        $twitter = new TwitterAPIExchange($this->getContainer()->getParameter('twitter'));

        try {
            $response = json_decode($twitter
                ->buildOauth(sprintf('https://api.twitter.com/1.1/geo/id/%s.json', $twitterPlaceId), 'GET')
                ->performRequest()
            );
        } catch (\Exception $e) {
            dump($e);
        }
        
        if (isset($response->id)) {
            $db->places->insert($response);

            return $response->_id;
        }
        dump($response->errors[0]->message . sprintf(' waiting %s seconds', 60));
        sleep(60);
        $this->getCoordinates($twitterPlaceId);
    }

    /**
     * @param Crawler $crawler
     *
     * @return array
     */
    private function parseTweets(Crawler $crawler)
    {
        return $crawler->filter('li.stream-item')->each(function ($node) {
            $placeId = null;
            $placeNode = $node->filter('.ProfileTweet-actionButton.u-linkClean.js-nav.js-geo-pivot-link');
            if ($placeNode->count()) {
                $placeId = $placeNode->attr('data-place-id');
            }

            return [
                'screenname' => $node->filter('.fullname')->text(),
                'username' => $node->filter('.username b')->text(),
                'text' => $node->filter('.TweetTextSize.js-tweet-text.tweet-text')->text(),
                'createdAt' => date('Y-m-d H:i:s', $node->filter('._timestamp.js-short-timestamp')->attr('data-time')),
                'tweetId' => $node->attr('data-item-id'),
                'place' => $this->getCoordinates($placeId)
            ];
        });
    }
}
