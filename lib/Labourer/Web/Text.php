<?php

namespace Labourer\Web;

class Text
{

  private static $url_expr = '/(?:^|\b)(?:[a-z]{3,7}:\/\/|\w+@|www\.)[-\.\w]+(?::\d{1,5})?[\/\w?:;+=#!%.-]+(?:\b|$)/i';

  private static $salt_chars = '@ABCD,EFGH.IJKL-MNOP=QRST~UVWX$YZab/cdef*ghij;klmn:opqr_stuv(wxyz)0123!4567|89{}';

  private static $high_chars = '$-_.+!*\'(),\d\pL';

  private static $under_repl = array(
                    '/(^|\W)([A-Z])/e' => '"\\1_".strtolower("\\2");',
                    '/[A-Z](?=\w)/' => '_\\0',
                  );

  private static $camel_repl = array(
                    '/[^a-z0-9]|\s+/i' => ' ',
                    '/\s([a-z])/ie' => '$glue.ucfirst("\\1");',
                  );

  private static $plain_repl = array();



  public static function urlify($text)
  {
    $hash = uniqid('__ENTITY__');
    $text = str_replace('&amp;', $hash, $text);
    $text = preg_replace_callback(static::$url_expr, function ($matches) {
        $href = $matches[0];

        ! strpos($href, '://') && $href = "http://$href";

        $output = "<a href=\"$href\">$matches[0]</a>";

        return $output;
      }, $text);

    $text = str_replace($hash, '&amp;', $text);

    return $text;
  }

  public static function encode($text)
  {
    $out    = '';
    $length = strlen($text);


    for ($i = 0; $i < $length; $i += 1) {
      $rand = mt_rand(0, 100);
      $char = substr($text, $i, 1);


      if ($ran < 45) {
        $out .= '&#x' . dechex(ord($char)) . ';';
      } elseif ($ran > 90 && ! preg_match('/[@:.]/', $char)) {
        $out .= $char;
      } else {
        $out .= '&#' . ord($char) . ';';
      }
    }

    return $out;
  }

  public static function even($text, $repl = '%s', $char = '|', $odd = FALSE)
  {
    $str = explode($char, $text);

    foreach ($str as $key => $val) {
      if (($key % 2) <> $odd) {
        $str[$key] =  sprintf($repl, $val);
      } else {
        $str[$key] = $val;
      }
    }

    return join($char, $str);
  }

  public static function alt(array $set = array())
  {
    static $index = 0;

    if (func_get_args() == 0) {
      $index = 0;
      return FALSE;
    }


    $num  = func_num_args();
    $args = is_array($set) ? $set : func_get_args();

    $num  = $index % sizeof($args);
    $out  = isset($args[$num]) ? $args[$num] : '';

    $index += 1;

    return $out;
  }

  public static function delim($text, $max = 3, $char = '|')
  {
    $meta = str_repeat('.', $max);
    $expr = "/(.)(?=($meta)+(?!.))/";

    return preg_replace($expr, "\\1$char", $text);
  }

  public static function left($text, $max = 0)
  {
    return $max > 0 ? substr($text, 0, $max) : substr($text, $max * -1);
  }

  public static function right($text, $max = 0)
  {
    return $max > 0 ? substr($text, -$max) : substr($text, 0, $max);
  }

  public static function words($text, $left = 100, $right = 0, $char = '&hellip;')
  {
    preg_match_all('/\s*(\S+\s+)\s*/', $text, $match);

    if (($left + $right) >= str_word_count($text)) {
      return $text;
    }

    $length = sizeof($match[1]);

    $left   = trim(join(' ', array_slice($match[1], 0, $left)));
    $right  = trim(join(' ', array_slice($match[1], $length - $right)));

    return $left . $char . $right;
  }

  public static function freq($text)
  {
    $set  = array();
    $text = preg_replace('/[^\d\pL]|\s+/', ' ', $text);

    foreach (array_filter(explode(' ', $text)) as $word) {
      if (array_key_exists($word, $set)) {
        $set[$word] += 1;
      } else {
        $set[$word] = 0;
      }
    }

    return $set;
  }

  public static function short($text, $left = 33, $right = 0, $glue = '&hellip;')
  {
    $prefix =
    $suffix = '';

    $hash   = uniqid('__SEPARATOR__');
    $max    = substr($glue, 0, 1) === '&' ? 1 : strlen($glue);

    if (preg_match('/&#?[a-zA-Z0-9];/', $text)) {
      $text = \Labourer\Web\Html::unents($text);
    }

    if ((strlen($text) + $max) > ($left + $right)) {
      $prefix = trim(substr($text, 0, $left - $max));
      $prefix = preg_replace('/^#?\w*;/', '', $prefix);

      if ($right > 0) {
        $suffix = trim(substr($text, - $right));
      }

      $suffix = preg_replace('/(&|&amp;)#?\w*$/u', '', $suffix);
      $text   = $prefix . $hash . $suffix;
    }


    $text = str_replace($hash, $glue, $text);

    return $text;
  }

  public static function highlight($text, $find, $repl = '<strong>%s</strong>', $char = ' ')
  {

    $found = array();
    $chars = static::$high_chars;
    $word  = ! is_array($find) ? explode($char, $find) : $find;

    foreach (array_unique(array_filter($word)) as $test) {
      $test    = preg_quote(strip_tags($test), '/');
      $found []= static::plain($test, TRUE);
    }

    if ( ! empty($found)) {
      $expr  = join('|', $found);
      $regex = "/(([<][^>]*)|(?<!&|#|\w)[$chars]*{$expr}(?:$chars*|[^\W<>]*)?(?=\s|\b))/i";

      $text  = preg_replace_callback($regex, function ($match)
        use ($repl) {
          return ! isset($match[2]) ? sprintf($repl, $match[0]) : $match[0];
        }, $text);
    }

    return $text;
  }

  public static function search($text, $query = '', $chunk = '..', $length = 30)
  {
    $bad   =
    $good  = array();
    $query = strtolower(static::plain($query));

    $query = preg_replace_callback('/"([^"]+?)"/', function ($match)
      use (&$good) {
        $good []= preg_quote($match[1]);
      }, $query);


    foreach (preg_split('/\s+/', $query) as $one) {
      switch(substr($one, 0, 1)) {
        case '-';
          if(strlen($one) > 1) {
            $bad []= preg_quote(substr($one, 1), '/');
          }
        break;
        case '+';
          if(strlen($one) > 1) {
            $good []= preg_quote(substr($one, 1), '/');
          }
        break;
        default;
          $good []= preg_quote($one, '/');
        break;
      }
    }


    $good = array_filter($good);

    if (sizeof($good) > 0) {
      $regex  = '(?<!&|#|\w)\w*(?:' . static::plain(join('|', $good), TRUE) . ')(?=';
      $regex .= $bad ? '(?!' . static::plain(join('|', $bad), TRUE) . ')' : '';
      $regex .= '.*?(?=\b))';

      if (preg_match_all("/$regex/uis", $text, $match, PREG_OFFSET_CAPTURE)) {
        $out = array();

        foreach ($match[0] as $key => $val) {
          $tmp = substr($text, $val[1] - ($length / 2), $length);
          $tmp = preg_replace('/^#?\w*;|(&|&amp;)#?\w*$/', '', $chunk . trim($tmp) . $chunk);

          $out []= array(
            'excerpt' => $tmp,
            'offset' => $val[1],
            'word' => $val[0],
          );
        }

        return $out;
      }
    }
    return FALSE;
  }

  public static function underscore($text, $ucwords = FALSE, $strict = FALSE)
  {
    if ($ucwords) {
      $text = ucwords($text);
    }

    $text = preg_replace(array_keys(static::$under_repl), static::$under_repl, $text);
    $text = trim(strtr($text, ' ', '_'), '_');
    $text = strtolower($text);

    return $text;
  }

  public static function camelcase($text, $ucfirst = FALSE, $glue = '')
  {
    $text = preg_replace(array_keys(static::$camel_repl), static::$camel_repl, static::underscore($text));

    if ($ucfirst) {
      $text = ucfirst($text);
    }

    return $text;
  }

  public static function salt($length = 8)
  {
    $length = (int) $length;
    $length > 32 && $length = 32;

    $out = '';

    do {
      $index = substr(static::$salt_chars, mt_rand(0, 79), 1);

      if ( ! strstr($out, $index)) {
        $out .= $index;
      }

      $current = strlen($out);

    } while($current !== $length);

    return $out;
  }

  public static function slugify($text, $glue = '-')
  {
    $text = static::plain(\Labourer\Web\Html::unents($text));
    $text = preg_replace('/[^\w\d\pL]+/', $glue, $text);
    $text = strtolower($text);

    $char = preg_quote($glue, '/');
    $text = preg_replace("/$char+/", $glue, $text);
    $text = trim($text, $glue);

    return $text;
  }

  public static function plain($text, $special = FALSE)
  {
    if (empty(static::$plain_repl['set'])) {
      $old  = array();
      $html = get_html_translation_table(HTML_ENTITIES);

      foreach ($html as $char => $ord) {
        if (ord($char) >= 192) {
          $key = substr($ord, 1, 1);

          static::$plain_repl['set'][$char] = $key;

          if ( ! isset($old[$key])) {
            $old[$key] = (array) $key;
          }

          $old[$key] []= $char;
          $old[$key] []= $ord;
        }
      }

      foreach ($old as $key => $val) {
        static::$plain_repl['rev'][$key] = '(?:' . join('|', $val) . ')';
      }
    }


    $text = strtr($text, static::$plain_repl['set']);
    $text = $special ? strtr($text, static::$plain_repl['rev']) : $text;

    return $text;
  }

}
