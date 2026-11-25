<?php
namespace Wa72\Spider\Core;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;

class HttpClientExceptionEvent
{
    /**
     * @var string
     */
    private $request_url;
    /**
     * @var TransferException
     */
    private $exception;
    /**
     * @var ResponseInterface|null
     */
    private $response;

    public function __construct($request_url, TransferException $exception)
    {
        $this->request_url = $request_url;
        $this->exception = $exception;
        if ($exception instanceof RequestException && $exception->hasResponse()) {
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
     * @return ResponseInterface|null
     */
    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    /**
     * @return TransferException
     */
    public function getException(): TransferException
    {
        return $this->exception;
    }
}