<?php
namespace Wa72\Spider\Core;

use Psr\Log\LoggerAwareTrait;

class UrlQueue implements UrlQueueInterface
{
    use LoggerAwareTrait;

    /**
     * @var array
     */
    private $queued = [];

    /**
     * @var array
     */
    private $visited = [];

    /**
     * @param string $url
     * @param bool $force
     */
    public function addUrl($url, $force = false)
    {
        if (!\in_array($url, $this->queued) && ($force || !\in_array($url, $this->visited))) {
            \array_push($this->queued, $url);
            if ($this->logger) $this->logger->info(sprintf('URL %s added to spider queue.', $url));
        } else if ($this->logger) {
            if (!$force) {
                $this->logger->debug(sprintf('URL %s has already been visited.', $url));
            } else {
                $this->logger->debug(sprintf('URL %s is already queued.', $url));
            }
        }
    }

    /**
     * @return string
     */
    public function next()
    {
        $url = \array_shift($this->queued);
        \array_push($this->visited, $url);
        return $url;
    }

    /**
     * @param $url
     * @return bool
     */
    public function isQueued($url)
    {
        return \in_array($url, $this->queued);
    }

    /**
     * @param $url
     * @return bool
     */
    public function isVisited($url)
    {
        return \in_array($url, $this->visited);
    }
}