<?php
/**
 * Created by PhpStorm.
 * User: johnor
 * Date: 2020/8/12
 * Time: 11:30
 */

namespace Cmb;


use GuzzleHttp\Client as HttpClient;

class Http
{
    private $client;

    /**
     *
     *[
        // Base URI is used with relative requests
        'base_uri' => 'http://httpbin.org',
        // You can set any number of default request options.
        'timeout'  => 2.0,
     ]
     * Http constructor.
     * @param array $options
     */
    public function __construct($options = [])
    {

        $this->client = new HttpClient($options);
    }


    /**
     * Make a request.
     *
     * @param  string $url
     * @param  string $method
     * @param  array $options
     *
     * @return mixed|\Psr\Http\Message\ResponseInterface
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function request($method, $url, $options = [])
    {
        $method = strtoupper($method);

        Log::debug('Client Request:', compact('url', 'method', 'options'));

        $response = $this->client->request($method, $url,$options);

        Log::debug('API response:', [
            'Status' => $response->getStatusCode(),
            'Reason' => $response->getReasonPhrase(),
            'Headers' => $response->getHeaders(),
            'Body' => strval($response->getBody()),
        ]);

        return $response;
    }
}