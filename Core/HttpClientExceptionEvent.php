<?php
namespace Wa72\Spider\Core;

use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

class HttpClientExceptionEvent
{
    /**
     * @var string
     */
    private $request_url;
    /**
     * @var RequestException
     */
    private $exception;
    /**
     * @var ResponseInterface
     */
    private $response;

    public function __construct($request_url, RequestException $exception)
    {
        $this->request_url = $request_url;
        $this->exception = $exception;
        if ($exception->hasResponse()) {
            $this->response = $exception->getResponse();
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
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @return RequestException
     */
    public function getException()
    {
        return $this->exception;
    }
}