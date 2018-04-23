<?php
require_once 'Parser.php';

try {
  if ($argc < 2) {
    throw new Exception("Need a parameter");
  }
  $test = $argv[1];
  $p = new Parser(0);
  print_r($p->parse($test));
}
catch (Exception $e) {
   list($col, $msg) = explode('|', $e->getMessage());
   echo "$msg\n";
   echo "    $test\n";
   echo "    ", str_repeat(' ', $col), "^\n";
}

