<?php

namespace Laravel\Scout\Contracts;

interface UpdatesIndexSettings
{
    /**
     * Update the index settings for the given index.
     *
     * @return void
     */
    public function updateIndexSettings(string $name, array $settings = []);

    /**
     * Configure the soft delete filter within the given settings.
     *
     * @return array
     */
    public function configureSoftDeleteFilter(array $settings = []);
}
