<?php

namespace Laravel\Scout\Services\Qdrant;

class QdrantCollection
{
    /**
     * Create a new Qdrant collection instance.
     */
    public function __construct(
        protected QdrantClient $client,
        protected string $name
    ) {
        //
    }

    /**
     * Get the collection details.
     */
    public function info(): array
    {
        return $this->client->request('GET', $this->uri());
    }

    /**
     * Create the collection.
     */
    public function create(array $parameters): array
    {
        return $this->client->request('PUT', $this->uri(), ['json' => $parameters]);
    }

    /**
     * Create a payload index on the collection.
     */
    public function createPayloadIndex(string $field, $schema): array
    {
        return $this->client->request('PUT', $this->uri().'/index', ['json' => [
            'field_name' => $field,
            'field_schema' => $schema,
        ]]);
    }

    /**
     * Delete the collection.
     */
    public function delete(): array
    {
        return $this->client->request('DELETE', $this->uri());
    }

    /**
     * Upsert points into the collection.
     */
    public function upsert(array $points): array
    {
        return $this->client->request('PUT', $this->uri().'/points?wait=true', ['json' => ['points' => $points]]);
    }

    /**
     * Delete points from the collection.
     */
    public function deletePoints(array $ids): array
    {
        return $this->client->request('POST', $this->uri().'/points/delete?wait=true', ['json' => ['points' => $ids]]);
    }

    /**
     * Query the collection.
     */
    public function query(array $parameters): array
    {
        return $this->client->request('POST', $this->uri().'/points/query', ['json' => $parameters]);
    }

    /**
     * Retrieve points from the collection by ID.
     */
    public function retrieve(array $parameters): array
    {
        return $this->client->request('POST', $this->uri().'/points', ['json' => $parameters]);
    }

    /**
     * Count the points in the collection.
     */
    public function count(array $parameters): array
    {
        return $this->client->request('POST', $this->uri().'/points/count', ['json' => $parameters]);
    }

    /**
     * Get the collection API URI.
     */
    protected function uri(): string
    {
        return '/collections/'.rawurlencode($this->name);
    }
}
