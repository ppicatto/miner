<?php

namespace TwitterBundle\Controller\Rest;


use FOS\RestBundle\Controller\Annotations\Delete;
use FOS\RestBundle\Controller\Annotations\Get;
use FOS\RestBundle\Controller\Annotations\Post;
use FOS\RestBundle\Controller\Annotations\Put;
use FOS\RestBundle\Controller\Annotations\View;
use FOS\RestBundle\Controller\FOSRestController;
use Nelmio\ApiDocBundle\Annotation\ApiDoc;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @author Hernan Andres Picatto <hpicatto@uscd.edu>
 * @author Pablo Gabriel Picatto <p.picatto@gmail.com>
 */
class ApiController extends FOSRestController
{
    private $config = [];
    private $url = [
        'base_api' => 'https://api.twitter.com/1.1/search/tweets.json',
        'param' => null
    ];

    /**
     * @View()
     *
     * @ApiDoc(
     *  section="twitter",
     *  resource=false,
     *  description="Get a tweets",
     *  tags={
     *    "beta"
     *  },
     *  filters={
     *      {
     *        "name"="dateFrom",
     *        "dataType"="datetime",
     *        "description"="Date from YYYY-MM-DD"
     *      }, {
     *        "name"="dateTo",
     *        "dataType"="datetime",
     *        "description"="Date to YYYY-MM-DD"
     *      }, {
     *        "name"="hashtag",
     *        "dataType"="array",
     *        "description"="Hastag without hash symbol"
     *      }, {
     *        "name"="geocode",
     *        "dataType"="array",
     *        "description"="Geocode separated by comma"
     *      }, {
     *         "name"="max",
     *         "dataType"="integer",
     *         "description"="Maximum tweets retreived"
     *      }
     *  }
     * )
     */
    public function getApiTweeterAction(Request $request)
    {
        $hashtag = explode(',', $request->get("hashtag"));
        $hashtag = '%23' . implode( '%28OR%28%23', $hashtag );
        $baseUrl = 'https://twitter.com/search?f=tweets&vertical=default&q='.$hashtag.'%20since%3A'.$request->get("dateFrom").'%20until%3A'.$request->get("dateTo").'%20include%3Aretweets&src=typd&count='.$request->get("max");
        $client = new Client();
        dump($baseUrl);
        $crawler = $client->request('GET', $baseUrl);
        $newTweets = $this->parseTweets($crawler);
        $response = $newTweets;
        while ($newTweets) {
            sleep(1);
            $q = 'https://twitter.com/i/search/timeline?f=tweets&vertical=default&q='.$hashtag.'%20since%3A'.$request->get("dateFrom").'%20until%3A'.$request->get("dateTo").'%20include%3Aretweets&src=typd&include_available_features=1&include_entities=1&last_note_ts=3099&max_position=TWEET-'.$crawler->filter('.js-original-tweet')->last()->attr('data-tweet-id').'-'.$crawler->filter('.js-original-tweet')->first()->attr('data-tweet-id').'-BD1UO2FFu9QAAAAAAAAETAAAAAcAAAASAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA&reset_error_state=false';
            
            $client->request("GET", $q);
            $html = json_decode($client->getResponse()->getContent(), true)['items_html'];
            $c2 = new Crawler($html);
            $newTweets = $this->parseTweets($c2);
            $response = array_merge($response, $newTweets);
        }

        return [
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
                'tweetId' => $node->attr('data-tweet-id'),
            ];
        });
    }

    /**
     * @param string $paramName
     * @param string $paramValue
     * @param string $propertySeparator
     * @param string $glue
     */
    private function addTweeterParam($paramName, $paramValue, $propertySeparator = '=', $glue = '&')
    {
        $this->url['param'] = preg_replace('/(^|&)since_id=[^&]*/', '', $this->url['param']);
        if ($this->url['param']) {
            $this->url['param'] .= $glue; 
        }
        $this->url['param'] .= sprintf('%s%s%s', $paramName, $propertySeparator, $paramValue);
    }

    /**
     * @View()
     *
     * @ApiDoc(
     *  section="twitter",
     *  resource=false,
     *  description="Get a tweets from mongo",
     *  tags={
     *    "beta"
     *  },
     *  filters={
     *      {
     *        "name"="limit",
     *        "dataType"="int",
     *        "description"="Max quantity of tweets to return"
     *      }
     *  }
     * )
     */
    public function getMongoTweetsAction(Request $request)
    {
        $mongoClient = new \MongoClient();
        $db = $mongoClient->selectDB("twitter");
        
        $metadatas = $db->tweetMetadata->find();
        if ($limit = $request->query->getInt('limit')) {
            $tweets = $db->tweet->find()->limit($limit);
        } else {
            $tweets = $db->tweet->find();
        }

        return [
            'tweets' => iterator_to_array($tweets),
            'countTweets' => $tweets->count(),
            'metadata' => iterator_to_array($metadatas),
            'countMetadatas' => $metadatas->count(),
        ];
    }
}
