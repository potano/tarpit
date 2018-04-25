<?php
require_once 'Parser.php';

$tp = new Tarpit();
$p = new Parser($tp);

$tests = <<<_END_
a < 3
a <= 3
a = 3
a == 3
a != 3
a <> 3
a > 3
a >= 3
3 < a
3 <= a
3 = a
3 == a
3 != a
3 <> a
3 >= a
3 > a
0 < a < 4
0 < a <= 4
0 <= a <= 4
0 <= a < 4
4 > a > 0
4 >= a > 0
4 >= a >= 0
4 > a >= 0
3 < a < 2
3 < a <= 2
3 < a <= 3
a < a
3 < 3
3 < a a 3
3 < a < a
a is null
a is not null
a in (first)
a in ()
a not in (first)
a in (first,"second")
a in (null)
a in (first,"second",null)
a == "unclosed
a not in (first,null)
a like 'abc%'
a like 'abc%def'
a like '%abc%def%'
a not like 'abc%'
hd-1234
_END_;

foreach (explode("\n", $tests) as $test) {
   try {
      echo "$test\n";
      print_r($p->parse($test));
      echo "\n";
   }
   catch (Exception $e) {
      list($col, $msg) = explode('|', $e->getMessage());
      echo "$msg\n";
      echo "    $test\n";
      echo "    ", str_repeat(' ', $col), "^\n";
   }
}


class Tarpit {
}

