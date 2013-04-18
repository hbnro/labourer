<?php

namespace Labourer;

class AS3
{

  private static $on = FALSE;

  private static $list = array();

  public static function __callStatic($method, $arguments)
  {
    return call_user_func_array(array('\\S3', str_replace(' ', '', ucwords(strtr($method, '_', ' ')))), $arguments);
  }

  public static function url($path, $secure = FALSE)
  {
    $secure = $secure ? 's' : '';
    $path   = strtr($path, '\\', '/');

    return "http$secure://" . static::hostname() . "/$path";
  }

  public static function buckets()
  {
    return static::$list;
  }

  public static function hostname()
  {
    $bucket = \Labourer\Config::get('s3_bucket');

    $location = ! empty(static::$list[$bucket]) ? static::$list[$bucket] : (\Labourer\Config::get('s3_location') ?: 'us');
    $location = $location <> 'us' ? $location : 's3';// its right?

    return "$bucket.$location.amazonaws.com";
  }

  public static function initialize()
  {
    if (! static::$on) {
      $key = \Labourer\Config::get('s3_key');
      $secret = \Labourer\Config::get('s3_secret');

      if (! $key OR ! $secret) {
        throw new \Exception("Missing key/secret for AS3");
      }

      static::$on = TRUE;
      static::set_auth($key, $secret);

      foreach (static::list_buckets() as $one) {// TODO: consider cache?
        static::$list[$one] = strtolower(static::get_bucket_location($one));
      }
    }
  }

}
