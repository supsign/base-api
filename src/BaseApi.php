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
    protected array|object $requestData = [];
    protected string $requestMethod;
    protected PendingRequest $request;
    protected array|object $response;
    protected string $url;
    protected bool $useCache = false;

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

    protected function cacheResponse(): self
    {
        if ($this->useCache) {
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
        return Http::{$requestMethod}($this->authUrl, $requestData);
    }

    protected function fetchBearerToken(array $requestData = [], $requestMethod = 'post'): string
    {
        if (empty($this->bearerToken)) {
            if (empty($requestData)) {
                $requestData = [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'client_credentials',
                    'scope' => 'token',
                ];
            }

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

        return static::class.':'.$this->endpoint.':'.implode('-', $this->requestData);
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
        $this->request = Http::baseUrl($this->baseUrl)->withHeaders($this->getHeaders());

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
            ->checkResponse($response)
            ->response = json_decode($response->body());

        return $this->cacheResponse();
    }

    protected function useBasicAuth(): self
    {
        $this->authMethod = 'basic';

        return $this;
    }

    protected function useCache(): self
    {
        $this->useCache = true;

        return $this;
    }

    protected function useCustomAuth(): self
    {
        $this->authMethod = 'custom';

        return $this;
    }
}