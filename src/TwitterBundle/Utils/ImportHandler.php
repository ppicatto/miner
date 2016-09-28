<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace TwitterBundle\Utils;

use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;
use TwitterAPIExchange;

/**
 * @author Hernan Andres Picatto <hpicatto@uscd.edu>
 * @author Pablo Gabriel Picatto <p.picatto@gmail.com>
 */
class ImportHandler {

    /**
     * @var int 
     */
    private $page = 1;

    /**
     * @var string
     */
    private $firstExternalId = null;

    /**
     * @var string
     */
    private $lastExternalId = null;

    /**
     * @var array
     */
    private $twitterConnection;

    /**
     * @var \MongoDB 
     */
    private $db = null;

    public function __construct($twitterConnection, $dbParams) {
        $mongoClient = new \MongoClient();
        $this->db = $mongoClient->selectDB($dbParams['name']);
        $this->twitterConnection = $twitterConnection;
    }

    /**
     * @param array $hashtag
     * @param \DateTime $dateFrom
     * @param \DateTime $dateTo
     * @param int $max
     *
     * @return string
     */
    private function urlEncode(array $hashtag = [], \DateTime $dateFrom, \DateTime $dateTo, int $max = 200): string
    {
        return sprintf('%s&include:retweets&src=typd&count=%s', str_replace(
            [':', '&', '#'],
            ['%3A', '%20', '%23'],
            sprintf(
                'q=%s&since:%s&until:%s', 
                '#' . implode('+OR+#', $hashtag),
                $dateFrom->format('Y-m-d'),
                $dateTo->format('Y-m-d')
            )
        ),$max);
    }

    /**
     * @param array $hashtag
     * @param \DateTime $dateFrom
     * @param \DateTime $dateTo
     * @param int $max
     *
     * @return array
     */
    private function getFirstPage(array $hashtag, \DateTime $dateFrom, \DateTime $dateTo, int $max = 200): array
    {
        $client = new Client();

        $crawler = $client->request('GET', sprintf('https://twitter.com/search?%s', $this->urlEncode($hashtag, $dateFrom, $dateTo, $max)));

        return $this->parseTweets($crawler);
    }

    /**
     * @param array $hashtag
     * @param \DateTime $dateFrom
     * @param \DateTime $dateTo
     * @param int $max
     *
     * @return array
     */
    private function getNextPage(array $hashtag, \DateTime $dateFrom, \DateTime $dateTo, int $max): array
    {
        sleep(4);
        $client = new Client();
        $client->request('GET', sprintf(
            'https://twitter.com/i/search/timeline?%s&f=tweets&vertical=default&include_available_features=1&include_entities=1&last_note_ts=3099&max_position=TWEET-%s-%s-BD1UO2FFu9QAAAAAAAAETAAAAAcAAAASAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA&reset_error_state=false',
            $this->urlEncode($hashtag, $dateFrom, $dateTo, $max),
            $this->lastExternalId,
            $this->firstExternalId
        ));
        $html = json_decode($client->getResponse()->getContent(), true)['items_html'];
        $crawler = new Crawler($html);

        return $this->parseTweets($crawler);
    }

    /**
     * @param string $twitterPlaceId
     *
     * @param return $placeId
     */
    private function getCoordinates($twitterPlaceId = 'n/a'): string {
        if ('n/a' === $twitterPlaceId ) {
            return 'n/a';
        }

        if ($place = $this->db->places->findOne(['id' => $twitterPlaceId])) {

            return $place['_id'];
        }
        $client = new Client();
        $twitter = new TwitterAPIExchange($this->twitterConnection);

        try {
            $response = json_decode($twitter
                ->buildOauth(sprintf('https://api.twitter.com/1.1/geo/id/%s.json', $twitterPlaceId), 'GET')
                ->performRequest()
            );
        } catch (\Exception $e) {
            dump($e);
        }
        
        if (isset($response->id)) {
            $this->db->places->insert($response);

            return $response->_id;
        }
        dump($response->errors[0]->message . sprintf(' waiting %s seconds', 60));
        sleep(60);
        return $this->getCoordinates($twitterPlaceId);
    }

    /**
     * @param Crawler $crawler
     *
     * @return array
     */
    private function parseTweets(Crawler $crawler): array
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
                'externalId' => $node->attr('data-item-id'),
                'place' => $this->getCoordinates($placeId)
            ];
        });
    }

    /**
     * @param \DateTime $dateFrom
     * @param \DateTime $dateTo
     * @param array $hashtag
     * @param int $max
     */
    public function importTweets(\DateTime $dateFrom, \DateTime $dateTo, array $hashtag = [], int $max = 200): \Generator
    {
        if ($previousMetadata = $this->db->tweetMetadata->find()->sort(['importedDate' => -1, 'createdAt' => -1])->limit(1)->next()) {
            $this->page = $previousMetadata['page'];
            $dateFrom = new \DateTime($previousMetadata['importedDate']['date']);
            $this->firstExternalId = $previousMetadata['firstExternalId'];
            $this->lastExternalId = $previousMetadata['lastExternalId'];
            $this->page++;
        }
        while ($dateFrom < $dateTo) {
            $startAt = clone $dateFrom;
            $dateFrom->modify('+1 day');
            if (1 === $this->page) {
                $tweets = $this->getFirstPage($hashtag, $startAt, $dateFrom, $max);
            } else {
                $tweets = $this->getNextPage($hashtag, $startAt, $dateFrom, $max);
            }

            if (!$tweets) {
                $this->page = 1;
                continue;
            }
            while ($tweets) {
                $metadata = [
                    'hashtags' => $hashtag,
                    'dateFrom' => $dateFrom,
                    'dateTo' => $dateTo,
                    'importedDate' => $startAt,
                    'limit' => $max,
                    'page' => $this->page,
                ];
                yield from $this->persistData($tweets, $metadata);
                $this->page++;
                $tweets = $this->getNextPage($hashtag, $startAt, $dateFrom, $max);
            }

            $this->page = 1;
        }
    }

    private function persistData($tweets, $metadata) {
        $this->firstExternalId = $tweets[0]['externalId'];
        $this->lastExternalId = end($tweets)['externalId'];
        $metadata['firstExternalId'] = $this->firstExternalId;
        $metadata['lastExternalId'] = $this->lastExternalId;

        $this->db->tweetMetadata->insert($metadata);
        foreach ($tweets as $tweet) {
            if (!$this->db->tweet->findOne(['externalId' => $tweet['externalId']])) {
                $tweet['metadataId'] = $metadata['_id'];
                $this->db->tweet->insert($tweet);
            }
            yield $tweet;
        }
    }
}
