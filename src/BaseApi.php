<?php

namespace Supsign\Laravel;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class BaseApi
{
    protected const REQUEST_METHODS = ['delete', 'get', 'patch', 'post', 'put'];

    protected string $authMethod = 'token';
    protected string $authUrl;
    protected string $baseUrl;
    protected string $bearerToken;
    protected int $cacheLifetime = 30;  //  in minutes
    protected string $clientId;
    protected string $clientSecret;
    protected string $endpoint;
    protected ?string $endpointCache = null;
    protected array $headers = [];
    protected PendingRequest $request;
    protected array|object $requestData = [];
    protected ?string $requestEncoding = null;
    protected bool|null|string $requestEncodingCache = false;
    protected string $requestMethod;
    protected array|object $response;
    protected int $timeout = 30;    //  in seconds
    protected string $url;
    protected bool $useCache = false;

    public function setCacheLifetime(int $minutes): self
    {
        $this->cacheLifetime = abs($minutes);

        return $this;
    }

    public function useCache(bool $use = true): self
    {
        $this->useCache = $use;

        return $this;
    }

    protected function authenticateRequest(): self
    {
        switch ($this->authMethod) {
            case 'basic':
                $this->request->withBasicAuth($this->clientId, $this->clientSecret);
                break;

            case 'custom':
                break;

            case 'token':
                $this->request->withToken($this->getBearerToken());
                break;
        }

        return $this;
    }

    protected function applyRequestEncoding(PendingRequest $request): PendingRequest
    {
        switch (strtolower($this->requestEncoding)) {
            case 'asform':
            case 'form':
            case 'x-www-form-urlencoded':
                return $request->asForm();

            default:
                return $request;
        }
    }

    protected function cacheResponse(): self
    {
        if ($this->useCache && !empty($this->response)) {
            Cache::put($this->getCacheKey(), $this->response, $this->cacheLifetime * 60);
        }

        return $this;
    }

    protected function checkResponse(Response $response): self
    {
        $response->throw();

        return $this;
    }

    protected function executeCall(): self
    {
        if ($this->loadResponseFromCache()) {
            return $this;
        }

        return $this
            ->makeRequest()
            ->authenticateRequest()
            ->setResponse(
                $this->request->{$this->requestMethod}($this->getEndpoint(), $this->getRequestData())
            );
    }

    protected function executeTokenCall(array $requestData, string $requestMethod): string
    {
        //  We need some static method that create a "PendingRequest" object without changing anything.
        $request = $this->applyRequestEncoding(Http::timeout($this->timeout));

        return $request->{$requestMethod}($this->authUrl, $requestData);
    }

    protected function fetchBearerToken(array $requestData = [], string $requestMethod = 'post'): string
    {
        if (empty($this->bearerToken)) {
            $requestData = array_merge([
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials',
                'scope' => 'token',
            ], $requestData);

            $this->bearerToken = $this->executeTokenCall($requestData, $requestMethod);
        }

        return $this->bearerToken;
    }

    protected function getBearerToken(): string
    {
        return $this->fetchBearerToken();
    }

    protected function getCacheKey(): string
    {
        if (empty($this->endpoint)) {
            throw new \Exception('no endpoint was specified');
        }

        return static::class.':'.$this->endpoint.':'.md5(serialize($this->requestData));
    }

    protected function getHeaders(): array
    {
        return $this->headers;
    }

    protected function getEndpoint(): string
    {
        if (!is_null($this->endpointCache)) {
            return $this->endpointCache;
        }

        $endpoint = $this->endpoint;

        foreach ($this->requestData as $key => $value) {
            $urlParameter = ':'.$key;

            if (str_contains($endpoint, $urlParameter)) {
                $endpoint = str_replace($urlParameter, $value, $endpoint);

                unset($this->requestData[$key]);
            }
        }

        $this->endpointCache = $endpoint;

        return $endpoint;
    }

    protected function getRequestData(): mixed
    {
        return $this->requestData;
    }

    protected function getResponse(): array|object
    {
        return $this->response;
    }

    protected function loadResponseFromCache(): bool
    {
        if ($existsInCache = $this->useCache && Cache::has($this->getCacheKey())) {
            $this->response = Cache::get($this->getCacheKey());
        }

        return $existsInCache;
    }

    protected function makeCall(string $endpoint, array|object $requestData = [], string $requestMethod = 'get'): array|object
    {
        return $this
            ->setEndpoint($endpoint)
            ->setRequestData($requestData)
            ->setRequestMethod($requestMethod)
            ->executeCall()
            ->getResponse();
    }

    protected function makeRequest(): self
    {
        $this->request = $this->applyRequestEncoding(
            Http::baseUrl($this->baseUrl)
                ->connectTimeout($this->timeout)
                ->timeout($this->timeout)
                ->withHeaders($this->getHeaders())
        );

        return $this;
    }

    protected function setEndpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;
        $this->endpointCache = null;

        return $this;
    }

    protected function setRequestData(array|object $requestData): self
    {
        $this->requestData = $requestData;

        return $this;
    }

    protected function restoreRequestEncoding(): self
    {
        if ($this->requestEncodingCache === false) {
            return $this;
        }

        $this->requestEncoding = $this->requestEncodingCache;
        $this->requestEncodingCache = false;

        return $this;
    }

    protected function setRequestMethod(string $requestMethod): self
    {
        $requestMethod = strtolower($requestMethod);

        if (!in_array($requestMethod, self::REQUEST_METHODS)) {
            throw new \Exception('"'.$requestMethod.'" is not a valid HTTP method');
        }

        $this->requestMethod = $requestMethod;

        return $this;
    }

    protected function setResponse(Response $response): self
    {
        $this
            ->restoreRequestEncoding()
            ->checkResponse($response)
            ->response = json_decode($response->body());

        return $this->cacheResponse();
    }

    protected function setTemporaryRequestEncoding(?string $encoding): self
    {
        $this->requestEncodingCache = $this->requestEncoding;
        $this->requestEncoding = $encoding;

        return $this;
    }

    protected function useBasicAuth(): self
    {
        $this->authMethod = 'basic';

        return $this;
    }

    protected function useCustomAuth(): self
    {
        $this->authMethod = 'custom';

        return $this;
    }
}