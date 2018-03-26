<?php
namespace Wa72\Spider\Core;

/**
 * Interface for a queue of URLs to fetch
 *
 */
interface UrlQueueInterface
{
    /**
     *
     * @param string $url
     * @param bool $force Force adding the URL even if already visited
     * @return void
     */
    public function addUrl($url, $force = false);

    /**
     * @return string
     */
    public function next();

    /**
     * @param $url
     * @return boolean
     */
    public function isQueued($url);

    /**
     * @param $url
     * @return boolean
     */
    public function isVisited($url);
}