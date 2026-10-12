<?php

namespace Laravel\Scout\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\DatabaseEngine;
use Laravel\Scout\SearchableSchema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class DatabaseEngineFullTextOptionsTest extends TestCase
{
    public function test_options_on_other_drivers_are_passed_through_unvalidated()
    {
        $this->assertSame(
            ['mode' => 'natural', 'rank' => 'foo', 'expand' => true],
            $this->fullTextOptions(new LegacyFullTextOptionsModel)
        );
    }

    public function test_searchable_schema_requires_postgres()
    {
        SearchableSchema::register();

        $this->expectException(RuntimeException::class);

        Schema::connection('testing')->create('posts', fn (Blueprint $table) => $table->searchable(['title']));
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
            'trigram with websearch' => [['mode' => 'websearch', 'trigram' => ['title']], '[trigram] requires the "plain" [mode]'],
        ];
    }

    protected function fullTextOptions(Model $model): array
    {
        return (fn () => $this->getFullTextOptions(new Builder($model, 'query')))->call(new DatabaseEngine);
    }
}

class LegacyFullTextOptionsModel extends Model
{
    protected $connection = 'testing';

    #[SearchUsingFullText(['title'], ['mode' => 'natural', 'rank' => 'foo', 'expand' => true])]
    public function toSearchableArray()
    {
        return [];
    }
}
