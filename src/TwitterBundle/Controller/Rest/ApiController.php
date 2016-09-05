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
        'base' => 'https://twitter.com/search?q=%23YaMeCanse%20since%3A2014-11-01%20until%3A2015-01-01%20include%3Aretweets&src=typd',
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

        $client = new Client();
        $response = [];
        $crawler = $client->request('GET', $this->url['base']);
        $response = $this->parseTweets($crawler);
        for ($i=0; $i<=4; $i++) {
            sleep(1);
            $client->request("GET", 'https://twitter.com/i/search/timeline?'. http_build_query([
                'f' => 'tweets',
                'vertical' => 'default',
                'q' => '%23YaMeCanse%20since%3A2014-11-01%20until%3A2015-01-01%20include%3Aretweets&src=typd',
                'include_available_features' => '1',
                'include_entities' => 1,
                'max_position' => $crawler->filter('.stream-container')->attr('data-max-position'),
                'reset_error_state' => false,
            ]));
            $html = json_decode($client->getResponse()->getContent(), true)['items_html'];
            $c2 = new Crawler($html);
            $response = array_merge($response, $this->parseTweets($c2));
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

        return $crawler->filter('.js-original-tweet')->each(function ($node) {
            return [
                'screenname' => $node->filter('.fullname')->text(),
                'username' => $node->filter('.username b')->text(),
                'text' => $node->filter('.TweetTextSize.js-tweet-text.tweet-text')->text(),
                'createdAt' => date('Y-m-d H:i:s', $node->filter('._timestamp.js-short-timestamp')->attr('data-time')),
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
}
