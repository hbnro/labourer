<?php

namespace Labourer\Web;

class Upload
{

  private static $handle = NULL;

  private static $files = array();

  private static $error = array();

  private static $status = array(
                    'upload_err_ok' => 'Without errors',
                    'upload_err_ini_size' => 'Directive max_size (php.ini) reached',
                    'upload_err_form_size' => 'Directive max_size (form) reached',
                    'upload_err_partial' => 'Partial upload error',
                    'upload_err_no_file' => 'File not selected',
                    'upload_err_no_tmp_dir' => 'Temporary path missing',
                    'upload_err_cant_write' => 'Write file error',
                    'upload_err_extension' => 'Extension file not allowed',
                    'upload_err_path' => 'Destination path missing',
                    'upload_err_multi' => 'Multi upload error',
                    'upload_err_exists' => 'Uploaded file already exists',
                    'upload_err_min_size' => 'Option min_size error',
                    'upload_err_max_size' => 'Option max_size error',
                    'upload_err_type' => 'Filetype error',
                    'upload_err_ext' => 'Extension error',
                  );

  private static $repl = array(
                    0 => 'upload_err_ok',
                    1 => 'upload_err_ini_size',
                    2 => 'upload_err_form_size',
                    3 => 'upload_err_partial',
                    4 => 'upload_err_no_file',
                    6 => 'upload_err_no_tmp_dir',
                    7 => 'upload_err_cant_write',
                    8 => 'upload_err_extension'
                  );


  public static function setup(array $test = array())
  {
    foreach ($test as $key => $val) {
      \Labourer\Config::set("upload_$key", $val);
    }
  }

  public static function validate(array $test, $skip = FALSE)
  {
    $out = FALSE;

    // reset
    static::$handle = NULL;
    static::$files  = array();
    static::$error  = array();


    if (static::s3()) {
      if ( ! array_key_exists(\Labourer\Config::get('s3_bucket'), \Labourer\AS3::buckets())) {
        return static::set_error('upload_err_path');
      }
    } elseif ( ! is_dir(\Labourer\Config::get('upload_path'))) {
      return static::set_error('upload_err_path');
    }

    $set = static::fix_files($test);

    if (empty($set)) {
      return static::set_error('upload_err_no_file');
    } elseif ( ! \Labourer\Config::get('upload_multiple') && (sizeof($set['name']) > 1)) {
      return static::set_error('upload_err_multi');
    }


    foreach ($set['error'] as $i => $val) {
      if ($val > 0) {
        if ( ! \Labourer\Config::get('upload_skip_error') OR ! $skip) {
          return static::set_error(static::$repl[$val]);
        }
        continue;
      }


      if ($set['size'][$i] > \Labourer\Config::get('upload_max_size')) {
        return static::set_error('upload_err_max_size');
      } elseif ($set['size'][$i] < \Labourer\Config::get('upload_min_size')) {
        return static::set_error('upload_err_min_size');
      }


      $type = FALSE;

      foreach ((array) \Labourer\Config::get('upload_type') as $one) {
        if (fnmatch($one, $set['type'][$i])) {
          $type = TRUE;
          break;
        }
      }

      if ( ! $type) {
        return static::set_error('upload_err_type');
      }


      $ext = FALSE;

      foreach ((array) \Labourer\Config::get('upload_extension') as $one) {
        // TODO: there are better alternatives?
        if (fnmatch("*.$one", strtolower($set['name'][$i]))) {
          $ext = TRUE;
          break;
        }
      }

      if ( ! $ext) {
        return static::set_error('upload_err_ext');
      }

      $name = preg_replace('/[^()\w.-]/', ' ', $set['name'][$i]);
      $name = preg_replace('/\s+/', '-', $name);

      $path = \Labourer\Config::get('upload_path');
      $file = ($path ? $path.DIRECTORY_SEPARATOR : '').$name;

      if ( ! \Labourer\Config::get('upload_unique')) {
        $new = strpos($name, '.') !== FALSE ? substr($name, strrpos($name, '.')) : '';
        $old = $new ? basename($name, $new) : $name;

        while (static::is_file($file)) {
          $file  = \Labourer\Config::get('upload_path').DIRECTORY_SEPARATOR;
          $file .= uniqid($old);
          $file .= $new;
        }
      } elseif (static::is_file($file)) {
        return static::set_error('upload_err_exists');
      }


      if ($test = static::move_file($tmp = $set['tmp_name'][$i], $file))
      {
        static::$files []= array_merge(array(
          'info' => $test,
          'file' => $file,
          'type' => $set['type'][$i],
          'size' => $set['size'][$i],
          'name' => basename($file),
        ));

        $out = TRUE;
      }
    }

    return $out;
  }

  public static function have_files()
  {
    if (static::$handle = array_shift(static::$files)) {
      return TRUE;
    }
    return FALSE;
  }

  public static function error_list()
  {
    $out = array();

    foreach (static::$error as $one) {
      $out []= isset(static::$status[$one]) ? static::$status[$one] : $one;
    }

    return $out;
  }

  public static function get_info()
  {
    return ! empty(static::$handle['info']) ? static::$handle['info'] : FALSE;
  }

  public static function get_file()
  {
    return static::$handle['file'];
  }

  public static function get_size()
  {
    return (int) static::$handle['size'];
  }

  public static function get_type()
  {
    return static::$handle['type'];
  }

  public static function get_name()
  {
    return static::$handle['name'];
  }


  private static function set_error($code)
  {
    static::$error []= $code;
  }

  private static function fix_files($set)
  {
    $out = (array) $set;

    if (isset($out['name']) && ! is_array($out['name'])) {
      $test = $out;
      $out  = array();

      foreach ($test as $key => $val) {
        $out[$key] []= $val;
      }
    }

    return $out;
  }

  private static function move_file($from, $to)
  {
    if (static::s3()) {
      $bucket = \Labourer\Config::get('s3_bucket');
      $perms = \Labourer\Config::get('s3_permission');
      $perms = $perms ? strtr($perms, '_', '-') : \S3::ACL_PUBLIC_READ;

      \Labourer\AS3::put_object_file($from, $bucket, strtr($to, '\\', '/'), $perms);

      return array_merge(\Labourer\AS3::get_object_info($bucket, strtr($to, '\\', '/')), array(
        'url' => \Labourer\AS3::url(strtr($to, '\\', '/'), TRUE),
      ));
    } else {
      return move_uploaded_file($from, $to) OR copy($from, $to);
    }
  }

  private static function is_file($path)
  {
    if (static::s3()) {
      $test = get_headers(\Labourer\AS3::url(strtr($path, '\\', '/')));
      return strpos(array_shift($test), '200') !== FALSE ? TRUE : FALSE;
    } else {
      return is_file($path);
    }
  }

  private static function s3()
  {
    static $loaded = NULL;


    if ($loaded === NULL) {
      $loaded = FALSE;

      if (\Labourer\Config::get('s3_key')) {
        $loaded = TRUE;
        \Labourer\AS3::initialize();
      }
    }

    return $loaded;
  }

}
