<?php

namespace Labourer;

class Config
{

  private static $bag = array(
                    // security
                    'csrf_salt' => '',
                    'csrf_token' => '',
                    'csrf_expire' => 300,
                    // about session
                    'session_path' => '/',
                    'session_expire' => 3600,
                    // about uploads
                    'upload_path' => './',
                    'upload_name' => 'file',
                    'upload_type' => '*/*',
                    'upload_min_size' => 1024,
                    'upload_max_size' => 2097152,
                    'upload_extension' => '*',
                    'upload_skip_error' => FALSE,
                    'upload_multiple' => FALSE,
                    'upload_unique' => FALSE,
                    // S3 settings
                    's3_key' => '',
                    's3_secret' => '',
                    's3_bucket' => '',
                    's3_location' => FALSE,
                    's3_permission' => 'public_read',
                  );

  public static function set($key, $value = NULL)
  {
    static::$bag[$key] = $value;
  }

  public static function get($key, $default = FALSE)
  {
    return isset(static::$bag[$key]) ? static::$bag[$key] : $default;
  }

}
