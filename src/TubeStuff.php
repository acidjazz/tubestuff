<?php

namespace acidjazz\TubeStuff;
/**
 * YouTube URL and API support
 *
 * @package TubeStuff
 * @author kevin olson <acidjazz@gmail.com>
 * @version 0.1
 * @copyright (C) 2018 kevin olson <acidjazz@gmail.com>
 * @license APACHE
 */

use Google_Client;
use Google_Service_YouTube;
use Goutte\Client;

 class TubeStuff {

   /**
    * Google Client
    */
   private $client;

   /**
    * Google_Services_YouTube
    */
   private $youtube;

   /**
    * Category list for the US region
    */
   private $categories = [
      1 => 'Film & Animation',
      2 => 'Autos & Vehicles',
      10 => 'Music',
      15 => 'Pets & Animals',
      17 => 'Sports',
      18 => 'Short Movies',
      19 => 'Travel & Events',
      20 => 'Gaming',
      21 => 'Videoblogging',
      22 => 'People & Blogs',
      23 => 'Comedy',
      24 => 'Entertainment',
      25 => 'News & Politics',
      26 => 'Howto & Style',
      27 => 'Education',
      28 => 'Science & Technology',
      29 => 'Nonprofits & Activism',
      30 => 'Movies',
      31 => 'Anime/Animation',
      32 => 'Action/Adventure',
      33 => 'Classics',
      34 => 'Comedy',
      35 => 'Documentary',
      36 => 'Drama',
      37 => 'Family',
      38 => 'Foreign',
      39 => 'Horror',
      40 => 'Sci-Fi/Fantasy',
      41 => 'Thriller',
      42 => 'Shorts',
      43 => 'Shows',
      44 => 'Trailers',
   ];

   public function __construct($API_KEY=false)
   {
     if ($API_KEY === false) {
       $API_KEY = config('services.google.api_key');
     }
     
     if ($API_KEY !== null) {
        $this->client = new Google_Client();
        $this->client->setApplicationName("TubeStuff");
        $this->client->setDeveloperKey($API_KEY);
        $this->youtube = new Google_Service_YouTube($this->client);
     }
   }

    /**
     * parse different string and determine types and ids
     *
     * @param String $string
     * @return Array
     */
    public function parse($string)
    {

        $type = 'unknown';
        $id = false;

        preg_match("/[a-zA-Z0-9_-]+/", $string, $output);

        if (count($output) > 0) {

          if ($this->isChannelId($string)) {
            $type = 'channel';
            $id = $string;
          } else if ($this->isVideoId($string)) {
            $type = 'video';
            $id = $string;
          }

        }

        if (substr($string, 0, 3) === 'www' || substr($string, 0, 8) === 'youtube.') {
          $string = 'https://'.$string;
        }

        $parsed = parse_url($string);

        if (!isset($parsed['host'])) {
          return ['type' => $type, 'id' => $id];
        }

        // https://www.youtube.com/channel/UC6MFZAOHXlKK1FI7V0XQVeA
        if (strpos($parsed['path'], '/channel') === 0)
        {
            $id = explode('/', $parsed['path'])[2];
            if ($this->isChannelId($id)) {
              $type = 'channel';
            }
        }

        // https://www.youtube.com/user/ProZD
        if (strpos($parsed['path'], '/user') === 0)
        {
            $type = 'channel';
            $id = $this->getChannelId('user/'.explode('/', $parsed['path'])[2]);
        }


        // https://www.youtube.com/idubbbztv
        if (count((explode('/', $parsed['path']))) == 2 && !isset($parsed['query'])) {
          $type = 'channel';
          $id = $this->getChannelId(explode('/', $parsed['path'])[1]);
        }

        // https://www.youtube.com/watch?v=4ZK8Z8hulFg
        if (strpos($parsed['path'], '/watch') === 0)
        {
            $type = 'video';
            parse_str($parsed['query'], $query);
            $id = $query['v'];
        }

        // https://youtu.be/aJX4ytfqw6k
        if ($parsed['host'] ===  'youtu.be')
        {
            $type = 'video';
            $id = substr($parsed['path'], 1);
        }

        return ['type' => $type, 'id' => $id];
    }

    /**
     * Determine if an id is a channel
     * @param String $id
     * @return Boolean
     */
    public function isChannelId($id)
    {
      if ($id[0] === 'U' && $id[1] === 'C' && strlen($id) == 24) {
        return true;
      }
      return false;
    }

    /**
     * Determine if an id is video
     * @param String $id
     * @return Boolean
     */
    public function isVideoId($id)
    {
      preg_match("/[a-zA-Z0-9_-]+/", $id, $output);
      if (count($output) > 0 && strlen($id) == 11) {
        return true;
      }
      return false;
    }

    /**
     * Get a channel ID from a user
     *
     * @param String $user
     * @return String
     */
    private function getChannelId($user)
    {
        $client = new Client();
        $crawler = $client->request('GET', 'https://www.youtube.com/'.$user);
        $url = $crawler->filter('link[rel="canonical"]')->attr('href');
        return explode('/', $url)[4];
    }

    public function getChannel($id)
    {

      if (!$this->isChannelId($id)) {
        $id = $this->getChannelId('user/'.$id);
      }

      $channel = [];
      $item = $this->youtube->channels->listChannels(
        ['id,snippet,topicDetails,statistics'],
        ['id' => $id]
      )->items[0];

      $channel['id'] = $id;
      $channel['name'] = $item->snippet->title;
      $channel['description'] = $item->snippet->description;
      $channel['logo'] = $item->snippet->thumbnails->high->url;

      if (isset($item->topicDetails)) {
        $categories = [];
        foreach ($item->topicDetails->topicCategories as $category) {
          $categories[] = $this->refine(str_replace('_', ' ', explode('wiki/', $category)[1]));
        }
        $channel['categories'] = array_values($categories);
      }

      $channel['subs'] = $item->statistics->subscriberCount;
      $channel['uploads'] = $item->statistics->videoCount;
      $channel['views'] = $item->statistics->viewCount;

      return $channel;
    }

    /**
     * Get list of videos from a channel ID
     *
     * @param String $id
     * @param String $pageToken
     * @return Object
     */
    public function getChannelVideos($id,$pageToken=null)
    {

      $list = $this->youtube->search->listSearch(
        ['snippet'],
        ['channelId' => $id, 'maxResults' => 9,'pageToken' => $pageToken, 'order' => 'date']
      );

      $results = [];
      $results['channelId'] = $list->items[0]->snippet->channelId;
      $results['channelTitle'] = $list->items[0]->snippet->channelTitle;
      $results['nextPageToken'] = $list->nextPageToken;
      $results['prevPageToken'] = $list->prevPageToken;
      $results['totalResults'] = $list->pageInfo->totalResults;

      foreach ($list->items as $item) {
        $results['videos'][$item->id->videoId] = [
          'id' => $item->id->videoId,
          'title' => $item->snippet->title,
          'description' => $item->snippet->description,
          'cover' => self::cover($item->id->videoId),
          'added' => false,
        ];
      }

      return $results;

    }

    /**
     * Return a video cover URL from its id
     *
     */
    public static function cover($id)
    {
      return 'https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg';
    }

    /**
     * Get YouTube videos
     *
     * @param Array $ids
     * @return Object
     */
    public function getVideos($ids)
    {

      $videos = [];
      $list = $this->youtube->videos->listVideos(
        ['statistics,snippet'],
        ['id' => implode(',', $ids), 'maxResults' => count($ids)]
      );


      foreach ($list->items as $item) {
        $videos[$item->id] = [
          'id' => $item->id,
          'title' => $item->snippet->title,
          'description' => $item->snippet->description,
          'views' => $item->statistics->viewCount,
        ];
        if (isset($this->categories[$item->snippet->categoryId])) {
          $videos[$item->id]['category'] = $this->categories[$item->snippet->categoryId];
        }
      }

      return $videos;
    }

    /**
     * Grab the most popular video of a channel
     * @param String $id
     * @return Object
     */
    public function getPopularVideo($id) {
      $videos = [];
      $listSearch = $this->youtube->search->listSearch(['snippet'], [
        'channelId' => $id, 
        'type' => 'video',
        'order' => 'viewCount',
        'maxResults' => 1,
      ]);

      if (isset($listSearch->items[0])) {
        return $listSearch->items[0]->id->videoId;
      }

      return false;

    }

    /**
     * Normalize the returned YouTube Channel Categories
     *
     * @param String $category
     * @return String
     */
    private function refine($category)
    {
      $category =  trim(ucwords(strtolower($category)));
      if ($category == 'Lifestyle (sociology)') {
        return 'Lifestyle';
      }
      if ($category == 'Sports') {
        return 'Sport';
      }
      if ($category == 'Humor') {
        return 'Comedy';
      }
      if ($category == 'Humour') {
        return 'Comedy';
      }
      if ($category == 'Pet') {
        return 'Animals';
      }
      if ($category == 'Diy') {
        return 'DIY';
      }
      if ($category == 'Association Football') {
        return 'Soccer';
      }
      return $category;
    }

 }
