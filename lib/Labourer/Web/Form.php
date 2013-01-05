<?php

namespace Labourer\Web;

class Form
{

  private static $self = NULL;

  private static $buffer = array();

  private static $allow = array('get', 'put', 'post', 'patch', 'delete');

  private static $types = array(
            'hidden',
            'text',
            'search',
            'tel',
            'url',
            'email',
            'password',
            'datetime',
            'date',
            'month',
            'week',
            'time',
            'datetime-local',
            'number',
            'range',
            'color',
            'checkbox',
            'radio',
            'file',
            'submit',
            'image',
            'reset',
            'button'
          );



  public static function __callStatic($method, $arguments)
  {
    $type = strtr($method, '_', '-');

    if ( ! in_array($type, static::$types)) {
      $params = array();
      $lambda = array_pop($arguments);
      $action = array_shift($arguments);

      foreach (explode('_', $method) as $part) {
        if (in_array($part, static::$allow)) {
          $params['method'] = $part;
        } else {
          $params[$part] = array_shift($arguments) ?: TRUE;
        }
      }


      if ( ! empty($params['method'])) {
        return static::to($action, $lambda, $params);
      }

      throw new \Exception("Unknown form '$method' field or type");
    }

    array_unshift($arguments, $type);

    return call_user_func_array('static::input', $arguments);
  }

  public function __call($method, $arguments)
  {
    return static::__callStatic($method, $arguments);
  }

  public static function to($action, $content, array $params = array())
  {
    $out = '';

    if (is_array($action)) {
      $params = array_merge($action, $params);
    } elseif ( ! isset($params['action'])) {
      $params['action'] = $action;
    }

    if (is_array($content)) {
      $params = array_merge($content, $params);
    } elseif ( ! isset($params['content'])) {
      $params['content'] = $content;
    }


    $params = array_merge(array(
      'action'    => '',
      'method'    => 'GET',
      'content'   => 'print',
      'multipart' => FALSE,
    ), $params);

    if ( ! ($params['content'] instanceof \Closure)) {
      throw new \Exception('You must provide a closure');
    }


    if ( ! empty($params['method']) && ($params['method'] <> 'GET')) {
      if ($params['multipart']) {
        $params['enctype'] = 'multipart/form-data';
      }
    }


    $callback = $params['content'];
    $params   = static::ujs($params, TRUE);

    $params['type'] && $params['data']['type'] = $params['type'];

    unset($params['multipart'], $params['content'], $params['type']);

    $params['method'] = strtolower($params['method'] ?: 'GET');
    $params['action'] = $params['action'] === '.' ? '' : $params['action'];


    if (preg_match('/^(put|get|post|patch|delete)\s+(.+?)$/i', $params['action'], $match)) {
      $params['method'] = strtolower($match[1]);
      $params['action'] = $match[2];
    }


    $tmp = array();
    $post = FALSE;

    if (preg_match('/^(?:put|patch|delete)$/', $params['method'])) {
      $post = TRUE;
      $tmp []= \Labourer\Web\Html::tag('input', array(
        'type' => 'hidden',
        'name' => '_method',
        'value' => $params['method'],
      ));
    }

    if ($post) {
      $params['method'] = 'post';
      $tmp []= static::csrf_token();
    }

    if ($tmp = join("\n", $tmp)) {
      $out .= "<div style=\"display:none\">\n$tmp\n</div>\n";
    }

    ob_start() && $callback(static::instance());
    $out .= ob_get_clean();

    return \Labourer\Web\Html::tag('form', $params, "\n$out");
  }

  public static function file($name, array $args = array())
  {
    return static::input('file', $name, '', $args);
  }

  public static function field($params)
  {
    $out  = array();
    $args = func_get_args();


    foreach ($args as $one) {
      if (is_array($one) && is_string(key($one))) {
        $one = array_merge(array(
          'type'    => '',
          'name'    => '',
          'value'   => '',
          'label'   => '',
          'options' => array(),
          'before'  => '',
          'after'   => '',
          'div'     => '',
        ), $one);

        switch ($one['type']) {
          case 'file';
            $input = static::file($one['name'], (array) $one['options']);
          break;
          case 'group';
          case 'select';
            $one['value'] = (array) $one['value'];
          case 'textarea';
            $input = static::$one['type']($one['name'], $one['value'], (array) $one['options']);
          break;
          default;
            $input = static::input($one['type'], $one['name'], $one['value'], (array) $one['options']);
          break;
        }

        $format = is_array($one['div']) ? sprintf('<div%s>%%s</div>', \Labourer\Web\Html::attrs($one['div'])) : '%s';
        $label  = ! empty($one['label']) ? static::label($one['name'] ?: FALSE, "<span>$one[label]</span>\n$input\n") : $input;

        $out  []= sprintf($format, "$one[before]$label$one[after]\n");
      } elseif (is_array($one)) {
        $out []= call_user_func_array('static::input', $one);
      } elseif (is_scalar($one)) {
        $out []= $one;
      }
    }

    return join('', $out);
  }

  public static function input($type, $name, $value = '', array $params = array())
  {
    if (is_array($type)) {
      $params = array_merge($type, $params);
    } elseif ( ! isset($params['type'])) {
      $params['type'] = $type;
    }

    if (is_array($name)) {
      $params = array_merge($name, $params);
    } elseif ( ! isset($params['name'])) {
      $params['name'] = $name;
    }

    if (is_array($value)) {
      $params = array_merge($value, $params);
    } elseif ( ! isset($params['value'])) {
      $params['value'] = $value;
    }


    $params = array_merge(array(
      'name'    => FALSE,
      'type'    => FALSE,
      'value'   => '',
    ), $params);

    $uncheck = '';
    $params  = static::ujs($params);
    $key     = static::index($params['name'], TRUE);

    switch ($params['type']) {
      case 'radio';
      case 'checkbox';
        $test = array();
        $uncheck = TRUE;

        if($test = isset($params['default'])) {
          $test = (array) $params['default'];
          unset($params['default']);
        }

        $received = static::value($params['name'], static::value($key));
        $params['checked'] = in_array($params['value'], $received ?: $test);
      break;
      default;
        $params['value'] = static::value($key, $params['value']);
      break;
    }


    if (empty($params['id'])) {
      $params['id'] = strtr($key, '.', '_');
    }

    foreach (array_keys($params) as $key) {
      if (empty($params[$key])) {
        unset($params[$key]);
      }
    }

    $uncheck && $uncheck = static::input('hidden', $params['name'], array('id' => "$params[id]_off"));

    return $uncheck . \Labourer\Web\Html::tag('input', $params);
  }

  public static function select($name, array $options, array $params = array())
  {
    if (is_array($name)) {
      $params = array_merge($name, $params);
    } elseif ( ! isset($params['name'])) {
      $params['name'] = $name;
    }


    if (empty($params['name'])) {
      throw new \Exception("The input 'name' for select() is required");
    }


    if ( ! isset($params['default'])) {
      $params['default'] = key($options);
    }


    $out     = '';
    $args    = array();

    $params  = static::ujs($params);
    $key     = static::index($params['name'], TRUE);
    $default = static::value($key, $params['default']);

    $params['type'] && $params['data']['type'] = $params['type'];

    unset($params['type']);


    foreach ($options as $key => $value) {
      if (is_array($value)) {
        $sub = '';

        foreach ($value as $key => $val) {
          $sub .= \Labourer\Web\Html::tag('option', array(
            'value' => $key,
            'selected' => is_array($default) ? in_array($key, $default, TRUE) : ! strcmp($key, $default),
          ), \Labourer\Web\Html::ents($val, TRUE));
        }

        $out .= \Labourer\Web\Html::tag('optgroup', array(
          'label' => \Labourer\Web\Html::ents($key, TRUE),
        ), $sub);

        continue;
      }

      $out  .= \Labourer\Web\Html::tag('option', array(
        'value' => $key,
        'selected' => is_array($default) ? in_array($key, $default) : ! strcmp($key, $default),
      ), \Labourer\Web\Html::ents($value, TRUE));
    }


    if ( ! empty($params['multiple']) && (substr($params['name'], -2) <> '[]')) {
      $params['name'] .= $params['multiple'] ? '[]' : '';
    }

    if (empty($params['id'])) {
      $args['id'] = $params['name'];
    }
    $args['name'] = $params['name'];

    unset($params['default']);

    return \Labourer\Web\Html::tag('select', array_merge($params, $args), $out);
  }

  public static function group($name, array $options, array $params = array())
  {
    if (is_array($name)) {
      $params = array_merge($name, $params);
    } elseif ( ! isset($params['name'])) {
      $params['name'] = $name;
    }


    if (empty($params['name'])) {
      throw new \Exception("The input 'name' for group() is required");
    }


    $params = array_merge(array(
      'name'      => '',
      'default'   => '',
      'multiple'  => FALSE,
      'placement' => 'before',
      'wrapper'   => '<div><h3>%s</h3>%s</div>',
      'break'     => '<br/>',
    ), $params);

    $out = '';
    $key = static::index($params['name'], TRUE);

    $default = (array) static::value($params['name'], static::value($key, $params['default']));
    $index   = strtr($key, '.', '_');
    $name    = $params['name'];
    $old     = $params;

    unset($old['name']);

    if ($params['multiple'] && (substr($params['name'], -2) <> '[]')) {
      $params['name'] .= '[]';
    }

    foreach ($options as $key => $value) {
      if (is_array($value)) {
        $out .= sprintf($params['wrapper'], \Labourer\Web\Html::ents($key, TRUE), static::group($name, $value, $params));
        continue;
      }

      $input = \Labourer\Web\Html::tag('input', array(
        'type' => $params['multiple'] ? 'checkbox' : 'radio',
        'name' => $params['name'],
        'value' => $key,
        'checked' => in_array($key, $default, TRUE),
        'title' => $value,
        'id' => $index . '_' . $key,
      ));


      $text = ($params['placement'] === 'before' ? $input : '')
            . \Labourer\Web\Html::ents($value, TRUE)
            . ($params['placement'] === 'after' ? $input : '');

      $label = \Labourer\Web\Html::tag('label', array(
        'for' => $index . '_' . $key,
      ), $text);

      $out .= $label . $params['break'];
    }

    return $out;
  }

  public static function textarea($name, $value = '', array $args = array())
  {
    if (is_array($name)) {
      $args = array_merge($name, $args);
    } elseif ( ! isset($args['name'])) {
      $args['name'] = $name;
    }

    if (is_array($value)) {
      $args = array_merge($value, $args);
    } elseif ( ! isset($params['text'])) {
      $args['text'] = $value;
    }


    if (empty($args['name'])) {
      throw new \Exception("The input 'name' for textarea() is required");
    }


    $args = static::ujs($args);

    $args['type'] && $args['data']['type'] = $args['type'];

    unset($args['type']);

    if ($id = static::index($args['name'], TRUE)) {
      $args['text'] = static::value($id, $value);
      $args['id']   = strtr($id, '.', '_');
      $args['name'] = $args['name'];
    }

    $args  = array_merge(array(
      'cols' => 40,
      'rows' => 6,
    ), $args);

    $value = \Labourer\Web\Html::ents($args['text'], TRUE);

    unset($args['text']);

    return \Labourer\Web\Html::tag('textarea', $args, $value);
  }

  public static function label($for, $text = '', $args = array())
  {
    if (is_array($for)) {
      $args = array_merge($for, $args);
    } elseif ( ! isset($args['for'])) {
      $args['for'] = $for;
    }

    if (is_array($text)) {
      $args = array_merge($text, $args);
    } elseif ( ! isset($args['text'])) {
      $args['text'] = $text;
    }


    if (empty($args['text'])) {
      throw new \Exception('Form labels cannot be empty');
    }

    $text = $args['text'];
    unset($args['text']);

    if ($id = static::index($for, TRUE)) {
      $args['for'] = strtr($id, '.', '_');
    }

    return \Labourer\Web\Html::tag('label', $args, $text);
  }


  private static function csrf_token()
  {
    return \Labourer\Web\Html::tag('input', array(
      'type' => 'hidden',
      'name' => '_token',
      'value' => \Labourer\Web\Session::token(),
    ));
  }

  private static function instance()
  {
    if ( ! static::$self) {
      static::$self = new static;
    }
    return static::$self;
  }

  private static function value($from, $or = FALSE)
  {
    return \Postman\Request::value($from, $or);
  }

  private static function index($name = '', $inc = FALSE)
  {
    static $num = 0;


    if ( ! empty($name)) {
      $name = preg_replace('/\[([^\[\]]+)\]/', '.\\1', $name);
      $name = preg_replace_callback('/\[\]/', function ($match)
          use($inc, &$num) {
          return '.' . ($inc ? $num++ : $num);
        }, $name);
    }

    return $name;
  }

  private static function ujs($params, $form = FALSE)
  {
    $params = array_merge(array(
      'url'          => FALSE,
      'type'         => FALSE,
      'method'       => FALSE,
      'remote'       => FALSE,
      'params'       => FALSE,
      'confirm'      => FALSE,
      'disable_with' => FALSE,
    ), $params);


    $params['url'] && $params['data']['url'] = $params['url'];
    $params['confirm'] && $params['data']['confirm'] = $params['confirm'];
    $params['params'] && $params['data']['params'] = http_build_query($params['params']);
    $params['disable_with'] && $params['data']['disable-with'] = $params['disable_with'];

    $params['remote'] && $params['data']['remote'] = 'true';

    unset($params['disable_with'], $params['confirm'], $params['remote'], $params['url']);

    if ( ! $form) {
      $params['method'] && $params['data']['method'] = $params['method'];
      unset($params['method']);
    }

    return $params;
  }

}
