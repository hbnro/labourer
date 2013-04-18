<?php

namespace Labourer\Web;

class Session
{

  private static $cache = array();

  // TODO: try another session drivers?
  public static function initialize()
  {
    // default session handler
    // http://php.net/session_set_save_handler

    if ( ! session_id()) {
      if ( ! \Postman\Request::is_local()) {
        $host = \Postman\Request::env('SERVER_NAME');
        $host = preg_match('/\d+\.\d+/', $host) ? $host : ".$host";

        session_set_cookie_params(\Labourer\Config::get('session_expire'), \Labourer\Config::get('session_path'), $host);
      }

      session_name('__SESSION_' . preg_replace('/\W/', '-', phpversion()));
      session_start();
    }

    // expires+hops
    foreach ($_SESSION as $key => $val) {
      if ( ! is_array($val) OR ! array_key_exists('value', $val)) {
        continue;
      }

      if (isset($_SESSION[$key]['expires']) && (time() >= $val['expires'])) {
        unset($_SESSION[$key]);
      }

      if (isset($_SESSION[$key]['hops']) && ($_SESSION[$key]['hops']-- <= 0)) {
        unset($_SESSION[$key]);
      }
    }

    // flashes
    if (isset($_SESSION['__FLASHES'])) {
      static::$cache = (array) $_SESSION['__FLASHES'];
      unset($_SESSION['__FLASHES']);
    }
  }

  public static function is_safe()
  {
    $check = \Labourer\Config::get('csrf_check');
    $token = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : NULL;

    @list($old_time, $old_token) = explode(' ', $check);
    @list($new_time, $new_token) = explode(' ', $token);

    if (((time() - $old_time) < \Labourer\Config::get('csrf_expire')) && ($old_token === $new_token)) {
      return TRUE;
    }

    return FALSE;
  }

  public static function token()
  {
    return \Labourer\Config::get('csrf_token');
  }

  public static function flash($key = -1, $value = FALSE)
  {
    if (func_num_args() <= 1) {
      return isset(static::$cache[$key]) ? static::$cache[$key] : static::$cache;
    }

    if ( ! isset(static::$cache[$key])) {
      static::$cache[$key] = $value;
    } else {
      static::$cache[$key]   = (array) static::$cache[$key];
      static::$cache[$key] []= $value;
    }

    $_SESSION['__FLASHES'] = static::$cache;
  }

  public static function get($key)
  {
    $hash =  "__SESSION$$key";

    if (is_null($key)) {
      session_destroy();//FIX
      $test = session_get_cookie_params();
      setcookie(session_name(), 0, 1, $test['path']);
    } elseif ( ! is_array($test = (isset($_SESSION[$hash]) ? $_SESSION[$hash] : NULL))) {
      return FALSE;
    } elseif (array_key_exists('value', $test)) {
      return $test['value'];
    }

    return FALSE;
  }

  public static function set($key, $value, $option = array())
  {
    $hash =  "__SESSION$$key";

    if (is_null($value) && isset($_SESSION[$hash])) {
      unset($_SESSION[$hash]);
    } else {
      if ( ! is_array($option)) {
        $option = array('expires' => (int) $option);
      }

      if ( ! empty($option['expires'])) {
        $plus = $option['expires'] < time() ? time() : 0;
        $option['expires'] += $plus;
      }

      $_SESSION[$hash] = $option;
      $_SESSION[$hash]['value'] = $value;
    }
  }

}
