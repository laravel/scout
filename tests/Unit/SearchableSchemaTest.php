<?php

namespace Laravel\Scout\Tests\Unit;

use InvalidArgumentException;
use Laravel\Scout\SearchableSchema;
use PHPUnit\Framework\TestCase;

class SearchableSchemaTest extends TestCase
{
    public function test_vector_expression_weights_columns_and_coalesces_nulls()
    {
        $this->assertSame(
            "setweight(to_tsvector('english', coalesce(\"title\"::text, '')), 'A') || setweight(to_tsvector('english', coalesce(\"body\"::text, '')), 'D')",
            SearchableSchema::vectorExpression(['title' => 'a', 'body'], 'english')
        );
    }

    public function test_vector_expression_accepts_schema_qualified_languages()
    {
        $this->assertStringContainsString("to_tsvector('public.english_unaccent'", SearchableSchema::vectorExpression(['title'], 'public.english_unaccent'));
    }

    public function test_vector_expression_rejects_invalid_input()
    {
        foreach ([[[], 'english'], [['title'], "english'); drop table users; --"], [['title' => 'E'], 'english'], [['ti"tle'], 'english']] as [$columns, $language]) {
            try {
                SearchableSchema::vectorExpression($columns, $language);
                $this->fail('Expected an InvalidArgumentException.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
