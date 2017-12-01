<?php

namespace Laravel\Scout;

use Composer\Script\Event;

class ComposerScripts
{
    /**
     * Handle the post-install Composer event.
     *
     * @param  \Composer\Script\Event  $event
     * @return void
     */
    public static function postInstall(Event $event)
    {
        require_once $event->getComposer()->getConfig()->get('vendor-dir').'/autoload.php';

        static::setVersion();
    }

    /**
     * Handle the post-update Composer event.
     *
     * @param  \Composer\Script\Event  $event
     * @return void
     */
    public static function postUpdate(Event $event)
    {
        require_once $event->getComposer()->getConfig()->get('vendor-dir').'/autoload.php';

        static::setVersion();
    }

    /**
     * Set the Laravel Scout version.
     *
     * @return void
     */
    protected static function setVersion()
    {
        $version = collect(
            json_decode(file_get_contents(base_path('vendor/composer/installed.json')))
        )->where('name', 'laravel/scout')->first()->version;

        $manager = realpath(__DIR__ . '/../EngineManager.php');

        $content = preg_replace(
            "/const VERSION = [^;]+;/",
            "const VERSION = '{$version}';",
            file_get_contents($manager)
        );

        file_put_contents($manager, $content);
    }
}
