<?php

namespace Laravel\Scout\Services\Qdrant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Laravel\Scout\Exceptions\ScoutException;
use Laravel\Scout\Scout;

class QdrantClient
{
    /**
     * Create a new Qdrant client instance.
     */
    public function __construct(
        protected Factory $http,
        protected array $config
    ) {
        //
    }

    /**
     * Send a request to Qdrant.
     */
    public function request(string $method, string $uri, array $options = []): array
    {
        return $this->pendingRequest()->send($method, $uri, $options)->throw()->json() ?? [];
    }

    /**
     * Create a pending HTTP request.
     */
    protected function pendingRequest(): PendingRequest
    {
        $request = $this->http
            ->baseUrl(rtrim($this->config['host'] ?? 'http://localhost:6333', '/'))
            ->withUserAgent('laravel-scout/'.Scout::VERSION)
            ->acceptJson()
            ->asJson()
            ->timeout($this->config['timeout'] ?? 60)
            ->connectTimeout($this->config['connect_timeout'] ?? 5);

        if (! empty($this->config['api_key'])) {
            $request->withHeaders(['api-key' => $this->config['api_key']]);
        }

        $retries = (int) ($this->config['retries'] ?? 3);

        if ($retries > 0) {
            $request->retry($retries + 1, 250, function ($exception) {
                return $exception instanceof ConnectionException ||
                    ($exception instanceof RequestException && in_array($exception->response->status(), [408, 429, 500, 502, 503, 504], true));
            });
        }

        return $request;
    }

    /**
     * Get a Qdrant collection instance.
     */
    public function collection(string $name): QdrantCollection
    {
        if (! preg_match('/^[A-Za-z0-9_.-]{1,255}$/', $name)) {
            throw new ScoutException("Invalid Qdrant collection [{$name}].");
        }

        return new QdrantCollection($this, $name);
    }
}
