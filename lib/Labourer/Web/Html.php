<?php

namespace Labourer\Web;

class Html
{

  private static $ents_repl = array(
                    '/(&#?[0-9a-z]{2,})([\x00-\x20])*;/i' => '\\1;\\2',
                    '/&#x([0-9a-f]+);/ei' => 'chr(hexdec("\\1"));',
                    '/(&#x?)([0-9A-F]+);/i' => '\\1\\2;',
                    '/&#(\d+);/e' => 'chr("\\1");',
                  );

  private static $unents_repl = array(
                    '/&amp;([a-z]+|(#\d+)|(#x[\da-f]+));/i' => '&\\1;',
                    '/&#x([0-9a-f]+);/ei' => 'chr(hexdec("\\1"));',
                    '/&#([0-9]+);/e' => 'chr("\\1");',
                  );

  private static $empty_tags = array(
                    'hr',
                    'br',
                    'img',
                    'base',
                    'link',
                    'meta',
                    'input',
                    'embed',
                    'param',
                    'source',
                    'track',
                    'area',
                  );



  public static function __callStatic($method, array $arguments)
  {
    array_unshift($arguments, $method);
    return call_user_func_array('static::tag', $arguments);
  }


  public static function tag($name, array $args = array(), $text = '')
  {
    $attrs = static::attrs($args);

    if (in_array($name, static::$empty_tags)) {
      return "<$name$attrs>";
    }


    if ($text instanceof \Closure) {
      ob_start() && $text();

      $text = ob_get_clean();
    }

    return "<$name$attrs>$text</$name>";
  }

  public static function attrs(array $args)
  {
    $out = array('');
    $set = array_slice(func_get_args(), 1);

    foreach ($set as $one) {
      is_array($one) && $args = array_merge($args, $one);
    }

    foreach ($args as $key => $value) {
      if (is_bool($value)) {
        if ($value) {
          $out []= $key;
        }
      } elseif (is_array($value)) {
        if ($key === 'style') {
          $props = array();

          foreach ($value as $key => $val) {
            $props []= $key . ':' . trim($val);
          }

          $out []= 'style="' . join(';', $props) . '"';
        } else {
          foreach ($value as $index => $test) {
            $val = is_scalar($test) ? $test : htmlspecialchars(json_encode($test));
            $out []= $key . '-' . $index . '="' . $val . '"';
          }
        }
      } elseif ( ! is_numeric($key)) {
        $out []= $key . '="' . htmlspecialchars((string) $value) . '"';
      }
    }

    $out = join(' ', $out);

    return $out;
  }

  public static function ents($text, $escape = FALSE)
  {
    $hash = uniqid('__ENTITY__');
    $text = preg_replace('/&([a-z0-9;_]+)=([a-z0-9_]+)/i', "{$hash}\\1=\\2", $text);

    $text = preg_replace(array_keys(static::$ents_repl), static::$ents_repl, $text);
    $text = preg_replace('/&(#?[a-z0-9]+);/i', "{$hash}\\1;", $text);
    $text = strtr($text, array(
      '&' => '&amp;',
      '\\' => '&#92;',
      $hash => '&',
    ));


    if ($escape) {
      $text = strtr($text, array(
          '<' => '&lt;',
          '>' => '&gt;',
          '"' => '&quot;',
          "'" => '&#39;',
      ));
    }

    $text = preg_replace("/[\200-\237]|\240|[\241-\377]/", '\\0', $text);
    $text = preg_replace("/{$hash}(.+?);/", '&\\1;', $text);

    return $text;
  }

  public static function unents($text)
  {
    static $set = NULL;

    if (is_null($set)) {
      $set = get_html_translation_table(HTML_ENTITIES);
      $set = array_flip($set);

      $set['&apos;'] = "'";
    }

    $text = preg_replace(array_keys(static::$unents_repl), static::$unents_repl, $text);
    $text = strtr($text, $set);

    return html_entity_decode($text);
  }

  public static function cdata($text, $comment = FALSE)
  {
    if ($comment) {
      return "/*<![CDATA[*/\n$text\n/*]]]>*/";
    }
    return "<![CDATA[$text]]]>";
  }

  public static function data($text, $mime = 'text/plain', $chunk = FALSE)
  {
    $text = base64_encode($text);

    if ($chunk) {
      $text = chunk_split($text);
    }

    return "data:$mime;base64,$text";
  }

  public static function script($url, $inline = FALSE)
  {
    $attrs['type'] = 'text/javascript';

    if ( ! $inline) {
      $attrs['src'] = $url;
    }

    return static::tag('script', $attrs, $inline ? static::cdata($url, TRUE) : '');
  }

  public static function style($url)
  {
    return static::tag('style', array('type' => 'text/css'), static::cdata($url, TRUE));
  }

  public static function link($rel, $href, array $args = array())
  {
    return static::tag('link', array_merge($args, compact('rel', 'href')));
  }

  public static function meta($name, $content, $http = FALSE)
  {
    $attrs = compact('content');

    if (is_array($name)) {
      $attrs = array_merge($attrs, $name);
    } else {
      $attrs[$http ? 'http-equiv' : 'name'] = $name;
    }

    return static::tag('meta', $attrs);
  }

  public static function block($text, array $args = array(), $wrap = '<p>%s</p>', $tag = 'blockquote')
  {
    if (is_scalar($text)) {
      return static::tag($tag, $args, sprintf($wrap, $text));
    } elseif (is_array($text)) {
      $test   = array_values($text);
      $length = sizeof($test);
      $out    = array();
      $cite   = FALSE;


      for ($i = 0; $i < $length; $i += 1) {
        $next = isset($test[$i + 1]) ? $test[$i + 1]: NULL;

        if (is_array($test[$i])) {
          $out []= static::block($test[$i], $args, $wrap, $tag);
        } elseif (is_array($next)) {
          $inner = static::block($next, $args, $wrap, $tag);
          $out []= static::block(sprintf($wrap, $test[$i]) . $inner, $args, '%s', $tag);

          $cite = TRUE;
          $i   += 1;
        }

        if (is_string($test[$i])) {
          $out []= static::tag($tag, $args, sprintf($wrap, $test[$i]));

          if ($cite) {
            $cite = FALSE;
          }
        }
      }

      return join("\n", $out);
    }
  }

  public static function table($head, array $body, $foot = array(), array $args = array(), \Closure $filter = NULL)
  {
    $thead =
    $tbody =
    $tfoot = '';

    if ( ! empty($head)) {
      $head = ! is_string($head) ? (array) $head : explode('|', $head);

      foreach ($head as $col) {
        $thead .= static::tag('th', array(), $col);
      }
      $thead = static::tag('thead', array(), static::tag('tr', array(), $thead));
    }

    if ( ! empty($foot)) {
      $foot  = ! is_string($foot) ? (array) $foot : explode('|', $foot);
      $attrs = array(
        'colspan' => sizeof($foot) > 1 ? 99 : FALSE,
      );

      foreach ($foot as $col) {
        $tfoot .= static::tag('th', $attrs, $col);
      }
      $tfoot = static::tag('tfoot', array(), static::tag('tr', array(), $tfoot));
    }


    foreach ((array) $body as $cols => $rows) {
      if ( ! is_array($rows)) {
        $tbody .= static::tag('tr', array(), static::tag('td', array('colspan' => 99), $rows));
        continue;
      }


      $row = '';

      foreach ($rows as $cell) {
        if ($filter) {
          $cell = $filter($cell);
        }

        if (is_array($cell)) {
          $cell = static::table('', $cell);
        }
        $row .= static::tag('td', array(), $cell);
      }
      $tbody .= static::tag('tr', array(), $row);
    }

    return static::tag('table', $args, $thead . $tbody . $tfoot);
  }

  public static function cloud(array $from = array(), array $args = array(), $href = '?q=%s', $min = 12, $max = 30, $unit = 'px')
  {
    $min_count = min(array_values($set));
    $max_count = max(array_values($set));

    $set    = array();
    $spread = $max_count - $min_count;

    ! $spread && $spread = 1;

    foreach ($from as $tag => $count) {
      $size  = floor($min + ($count - $min_count) * ($max - $min) / $spread);
      $set []= static::a(sprintf($href, $tag), $tag, array(
        'style' => "font-size:$size$unit",
      ));
    }

    return static::ul($set, $args);
  }

  public static function navlist(array $set, array $args = array(), $default = URI, $class = 'here')
  {
    $out = array();

    foreach ($set as $key => $val) {
      $attrs = array();

      if ($default === $key) {
        $attrs['class'] = $class;
      }

      $out []= static::a($key, $val, $attrs);
    }
    return static::ul($out, $args);
  }

  public static function dl(array $set, array $args = array(), $filter = FALSE)
  {
    return static::ul($set, $args, $filter, 0, 0);
  }

  public static function ol(array $set, array $args = array(), $filter = FALSE)
  {
    return static::ul($set, $args, $filter, 0);
  }

  public static function ul(array $set, array $args = array(), \Closure $filter = NULL)
  {
    $ol = func_num_args() == 4;
    $dl = func_num_args() == 5;

    $tag   = 'ul';
    $el    = 'li';
    $out   = '';

    if ($dl) {
      $tag = 'dl';
      $el  = 'dd';
    } elseif ($ol) {
      $tag = 'ol';
    }



    foreach ($set as $item => $value) {
      $test = $filter ? $filter($item, $value) : array($item, $value);

      if ( ! isset($test[1])) {
        continue;
      } elseif ($dl) {
        $out .= static::tag('dt', array(), $test[0]);
      }

      if (is_array($test[1])) {
        $item = ! is_numeric($test[0]) ? $test[0] : '';
        $tmp  = array($test[1], $args, $filter);

        if (is_callable($filter)) {
          $item = $filter(-1, $item);
          $item = array_pop($item);
        }

        if ($ol) {
          $tmp []= '';
        }

        $tmp[1] = array();

        $inner = call_user_func_array('static::ul', $tmp);
        $out  .= static::tag($el, array(), $item . $inner);
        continue;
      }
      $out .= static::tag($el, array(), $test[1]);
    }
    return static::tag($tag, $args, $out);
  }

  public static function a($href, $text = '', $title = array())
  {
    if (is_array($href)) {
      $href = http_build_query($href, NULL, '&amp;');
      $href = ! empty($href) ? "?$href" : '';
    }


    $attrs = array();

    if ( ! empty($href)) {
      $attrs['href'] = $href;
    }

    if (is_array($title)) {
      $attrs = array_merge($title, $attrs);
    } elseif ( ! empty($title)) {
      if (empty($text)) {
        $text = $title;
      }
      $attrs['title'] = $title;
    }

    return static::tag('a', $attrs, $text ?: $href);
  }

  public static function anchor($name, $text = '', array $args = array())
  {
    $attrs = array();

    $attrs['id'] = preg_replace('/[^\w-]/', '', $name);

    return static::tag('a', array_merge($args, $attrs), $text);
  }

  public static function img($url, $alt = '')
  {
    $default = basename($url);

    if (is_array($alt)) {
      $attrs = $alt;
      $alt   = '';

      $default = isset($attrs['alt']) ? $attrs['alt'] : $default;
    } elseif ( ! empty($alt)) {
      $default = $alt;
    }

    $attrs['alt']   =
    $attrs['title'] = $default;
    $attrs['src']   = $url;

    return static::tag('img', $attrs);
  }

}
