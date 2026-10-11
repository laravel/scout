<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\DatabaseEngine;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseEngineFullTextOptionsTest extends TestCase
{
    public function test_config_defaults_are_overridden_by_attribute_options()
    {
        config(['scout.database.pgsql' => ['language' => 'simple', 'rank' => 'ts_rank_cd', 'mode' => 'phrase']]);

        $this->assertSame(
            ['language' => 'simple', 'rank' => 'ts_rank_cd', 'mode' => 'websearch', 'vector' => 'search_vector'],
            $this->fullTextOptions(new FullTextOptionsModel)
        );
    }

    public function test_config_defaults_only_apply_to_postgres_connections()
    {
        config(['scout.database.pgsql' => ['language' => 'simple']]);

        $this->assertSame(
            ['mode' => 'websearch', 'vector' => 'search_vector'],
            $this->fullTextOptions((new FullTextOptionsModel)->setConnection('testing'))
        );
    }

    /** @dataProvider invalidOptions */
    #[DataProvider('invalidOptions')]
    public function test_invalid_options_throw(array $options, string $message)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (fn () => $this->ensureValidFullTextOptions($options))->call(new DatabaseEngine);
    }

    public static function invalidOptions(): array
    {
        return [
            'unknown key' => [['trigram_mod' => 'blend'], 'unknown [trigram_mod]'],
            'rank' => [['rank' => 'foo'], '[rank] must be one of'],
            'trigram mode' => [['trigram_mode' => 'always'], '[trigram_mode] must be one of'],
            'weights' => [['weights' => ['trigam' => 1]], '[weights] accepts'],
            'raw without vector' => [['mode' => 'raw'], '[mode] "raw" requires the [vector] option'],
        ];
    }

    protected function fullTextOptions(Model $model): array
    {
        return (fn () => $this->getFullTextOptions(new Builder($model, 'query')))->call(new DatabaseEngine);
    }
}

class FullTextOptionsModel extends Model
{
    protected $connection = 'pgsql';

    #[SearchUsingFullText(['title'], ['mode' => 'websearch', 'vector' => 'search_vector'])]
    public function toSearchableArray()
    {
        return [];
    }
}
