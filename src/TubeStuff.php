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

   public function __construct($API_KEY)
   {
      $this->client = new Google_Client();
      $this->client->setApplicationName("TubeStuff");
      $this->client->setDeveloperKey($API_KEY);
      $this->youtube = new Google_Service_YouTube($this->client);
   }

    /**
     * parse different urls and determine types and ids
     *
     * @param String $url
     * @return Array
     */
    public function parse($url)
    {

        $type = 'unknown';
        $id = false;

        $parsed = parse_url($url);

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
            $id = $this->getChannelId(explode('/', $parsed['path'])[2]);
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
     * Determine if an id is valid
     * @param String $id
     * @return Boolean
     */
    public function isChannelId($id)
    {
      if ($id[0] === 'U' && $id[1] === 'C') {
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
    public function getChannelId($user)
    {
        $client = new Client();
        $crawler = $client->request('GET', 'https://www.youtube.com/user/'.$user);
        $url = $crawler->filter('link[rel="canonical"]')->attr('href');
        return explode('/', $url)[4];
    }

    public function getChannel($id)
    {

      if (!$this->isChannelId($id)) {
        $id = $this->getChannelId($id);
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
     * @param App\Models\User $user
     * @return Object
     */
    public function getChannelVideos($id,$pageToken=null,$user=false)
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
          'title' => $item->snippet->title,
          'views' => $item->statistics->viewCount,
        ];
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
