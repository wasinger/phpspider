<?php
namespace Wa72\Spider\Core;

use Psr\Http\Message\ResponseInterface;
use Symfony\Component\EventDispatcher\Event;

class SpiderResponseEvent extends Event
{
    const NAME = 'wa72.spider.response';

    /**
     * @var string
     */
    private $request_url;
    /**
     * @var string
     */
    private $content_type;
    /**
     * @var ResponseInterface
     */
    private $response;

    public function __construct($request_url, ResponseInterface $response)
    {
        $this->request_url = $request_url;
        $this->response = $response;
        $this->content_type = $response->getHeaderLine('content-type');
        if (($pos = strpos($this->content_type, ';')) > 0) {
            // remove charset from content type
            $this->content_type = substr($this->content_type, 0, $pos);
        }
    }

    /**
     * @return string
     */
    public function getRequestUrl()
    {
        return $this->request_url;
    }

    /**
     * @return string
     */
    public function getContentType()
    {
        return $this->content_type;
    }

    /**
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }
}