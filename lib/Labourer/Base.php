<?php

namespace Labourer;

class Base
{

  public static function initialize()
  {
    // method override
    if ($_method = (isset($_POST['_method']) ? $_POST['_method'] : FALSE)) {
      $_SERVER['REQUEST_METHOD'] = strtoupper($_method);
      unset($_POST['_method']);
    }


    // CSRF override
    if ($_token = (isset($_POST['_token']) ? $_POST['_token'] : FALSE)) {
      $_SERVER['HTTP_X_CSRF_TOKEN'] = $_POST['_token'];
      unset($_POST['_token']);
    }


    // security
    \Labourer\Web\Session::initialize();

    $salt = \Labourer\Config::get('csrf_salt');
    $token = time() . ' ' . md5($salt . uniqid(session_id()));
    $check = ! empty($_SESSION['__CSRF_TOKEN']) ? $_SESSION['__CSRF_TOKEN'] : '';

    \Labourer\Config::set('csrf_check', $check);
    \Labourer\Config::set('csrf_token', $token);

    $_SESSION['__CSRF_TOKEN'] = $token;
  }

}