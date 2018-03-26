<?php
namespace Wa72\Spider\Core;

use Psr\Http\Message\ResponseInterface;
use Symfony\Component\EventDispatcher\Event;

class SpiderRedirectEvent extends Event
{
    const NAME = 'wa72.spider.redirect';

    /**
     * @var string
     */
    private $request_url;
    /**
     * @var ResponseInterface
     */
    private $response;
    /**
     * @var int
     */
    private $statuscode;
    /**
     * @var string
     */
    private $redirect_url;

    public function __construct($request_url, $redirect_url, $statuscode, ResponseInterface $response)
    {
        $this->request_url = $request_url;
        $this->response = $response;
        $this->statuscode = $statuscode;
        $this->redirect_url = $redirect_url;
    }

    /**
     * @return string
     */
    public function getRequestUrl()
    {
        return $this->request_url;
    }

    /**
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @return int
     */
    public function getStatuscode()
    {
        return $this->statuscode;
    }

    /**
     * @return string
     */
    public function getRedirectUrl()
    {
        return $this->redirect_url;
    }

}