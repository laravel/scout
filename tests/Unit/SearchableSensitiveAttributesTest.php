<?php

namespace Laravel\Scout\Tests\Unit;

use Laravel\Scout\Tests\Fixtures\SearchableModel;
use Laravel\Scout\Tests\Fixtures\SearchableModelWithSensitiveAttributes;
use PHPUnit\Framework\TestCase;

class SearchableSensitiveAttributesTest extends TestCase
{
    public function test_update_on_sensitive_attributes_triggers_search()
    {
        $model = new SearchableModelWithSensitiveAttributes([
            'first_name' => 'taylor',
            'last_name' => 'Otwell',
            'remember_token' => 123,
            'password' => 'secret',
        ]);

        // Let's pretend it's in sync with the database.
        $model->syncOriginal();

        // Update
        $model->password = 'extremelySecurePassword';
        $model->first_name = 'Taylor';

        $this->assertTrue($model->searchShouldUpdate(), 'Model should update given that the first_name changed.');
    }

    public function test_update_on_non_sensitive_attributes_doesnt_trigger_search()
    {
        $model = new SearchableModelWithSensitiveAttributes([
            'first_name' => 'taylor',
            'last_name' => 'Otwell',
            'remember_token' => 123,
            'password' => 'secret',
        ]);

        // Let's pretend it's in sync with the database.
        $model->syncOriginal();

        // Update
        $model->password = 'extremelySecurePassword';
        $model->remember_token = 456;

        $this->assertFalse($model->searchShouldUpdate(),
            'Model should not update given that no sensitive attributes changed.');
    }

    public function test_always_should_update_when_sensitive_attributes_are_not_defined()
    {
        $model = (new SearchableModel())->forceFill([
            'first_name' => 'taylor',
            'last_name' => 'Otwell',
            'remember_token' => 123,
            'password' => 'secret',
        ]);

        // Let's pretend it's in sync with the database.
        $model->syncOriginal();

        // Update
        $model->password = 'extremelySecurePassword';

        $this->assertTrue($model->searchShouldUpdate(),
            'Model should always update since sensitive attributes were not defined.');
    }
}
