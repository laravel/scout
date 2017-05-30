<?php

namespace Tests\Fixtures;

class AdditionalActions
{
  public $whereIns = [];

  public function whereIn($column, $values)
  {
      return $this->whereIns[$column] = $values;
  }

}
