<?php
require_once 'Parser.php';

$p = new Parser(0);

$tests = <<<_END_
a = 3
3 = a
0 < a < 4
0 < a <= 4
a like 'abc%'
a not like 'abc%'
_END_;

try {
   foreach (explode("\n", $tests) as $test) {
      echo "$test\n";
      print_r($p->parse($test));
      echo "\n";
   }
}
catch (Exception $e) {
   list($col, $msg) = explode('|', $e->getMessage());
   echo "$msg\n";
   echo "    $test\n";
   echo "    ", str_repeat(' ', $col), "^\n";
}

