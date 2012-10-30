<?php

namespace Labourer;

class Helpers
{

  public static function is_assoc($set)
  {
    return is_array($set) && is_string(key($set));
  }

}
