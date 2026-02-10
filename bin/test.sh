#!/usr/bin/env bash

echo "Ensuring Docker is running"

if ! docker info > /dev/null 2>&1; then
  echo "Please start docker first."
  exit 1
fi

echo "Ensuring services are running"

docker-compose up -d

echo "Running tests"

if MEILISEARCH_HOST='http://localhost:7700' MEILISEARCH_KEY='masterKey' TYPESENSE_HOST='localhost' TYPESENSE_PORT=8108 TYPESENSE_API_KEY='xyz' ./vendor/bin/phpunit; then
    exit 0
else
    exit 1
fi
