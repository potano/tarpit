#!/usr/bin/env php
<?php

require_once "Tarpit.php";

$action = NULL;
$options = array();
$positionals = array();

try {
  $args = $argv;
  $myself = array_shift($args);
  while ($args) {
    $arg = array_shift($args);
    if ($arg{0} == '-') {
      $switch = explode('=', $arg, 2);
      $parm = count($switch) > 1 ? $parts[1] : NULL;
      $switch = $switch[0];
      $bareSwitch = preg_replace('/^-*/', '', $switch);
      $valTo = NULL;
      $required = TRUE;
      $asArray = FALSE;
      switch ($bareSwitch) {
        case 'help':
          help_message();
          exit(0);
        case 'hd':
        case 'tar':
        case 'isd':
        case 'desc':
        case 'url':
        case 'branch':
        case 'reporter':
        case 'assignee':
        case 'status':
          $valTo = $bareSwitch;
          break;
        case 'reported':
        case 'assigned':
        case 'resolved':
          $valTo = $bareSwitch;
          $required = FALSE;
          break;
        case 'comment':
        case 'remove':
          $valTo = $bareSwitch;
          $asArray = TRUE;
          break;
        default:
          fatal("Unknown switch $switch");
      }
      if (isset($valTo)) {
        if (!isset($parm)) {
          $canShift = $args && $args[0]{0} != '-';
          if ($required && !$canShift) {
            fatal("$switch switch requires a parameter");
          }
          if ($canShift) {
            $parm = array_shift($args);
          }
        }
        if ($asArray) {
          if (isset($options[$valTo])) {
            $options[$valTo][] = $parm;
          }
          else {
            $options[$valTo] = array($parm);
          }
        }
        else {
          $options[$valTo] = $parm;
        }
      }
    }
    elseif (!$action) {
      $action = $arg;
    }
    else {
      $positionals[] = $arg;
    }
  }

  $tp = new Tarpit();

  if (!isset($action)) {
    fatal("First parameter must specify an action");
  }

  $refkeys = array_flip(explode(' ', 'hd tar isd'));
  $refs = array_intersect_key($options, $refkeys);
  $data = array_diff_key($options, $refkeys);

  switch ($action) {
    case 'add':
      if ($positionals) {
        foreach ($positionals as $item) {
          if (!preg_match('/^(hd|isd|tar)\D*(\d*)/', strtolower($item), $matches)) {
            fatal("Unrecognized parameter $item");
          }
          $options[$matches[1]] = $matches[2];
        }
      }
      $refno = $tp->addTar($options);
      echo "Added; refno = $refno\n";
      showFullTar($tp->fetchTar($options));
      break;
    case 'edit':
    case 'update':
      if (!$positionals) {
        fatal("Missing reference parameter for item to edit");
      }
      $refno = $positionals[0];
      $removals = array();
      if (isset($options['remove'])) {
        foreach ($options['remove'] as $item) {
          $removals = array_merge($removals, array_flip(explode(',', $item)));
        }
      }
      $removals = array_flip($removals);
      if ('edit' == $action) {
        echo "Now in database:\n";
        showFullTar($tp->fetchTar($refno));
        echo "\nWould update to\n";
        $tp->editTar($refno, $options, $removals, FALSE);
        showFullTar($tp->fetchTar($refno));
        if (userApproves("\nMake the proposed edit?")) {
          $tp->save();
        }
      }
      else {
        $tp->editTar($refno, $options, $removals);
      }
      break;
    case 'remove':
    case 'hardremove':
      if (!$positionals) {
        fatal("Missing reference parameter for item to remove");
      }
      $refno = $positionals[0];
      if ('remove' == $action) {
        showFullTar($tp->fetchTar($refno));
        if (!userApproves("\nRemove this record?")) {
          break;
        }
      }
      $tp->removeTar($refno);
      break;
    case 'show':
      if (!$positionals) {
        fatal("Need parameter to indicate which item to show");
      }
        $tar = $tp->fetchTar($positionals[0]);
        showFullTar($tar);
      }
      break;
    case 'list':
      if (!$positionals) {
        fatal("Need search-query argument");
      }
      $query = $positionals[0];
      try {
        $tars = $tp->fetchTars($query, $options);
      }
      catch (Exception $e) {
         list($col, $message) = $e->getMessage();
         $message .= "\n    $query\n    " . str_repeat(' ', $col), "^\n";
         fatal($message);
      }
      if (!$tars) {
         echo "No tars found\n";
      }
      foreach ($tars as $tarX => $tar) {
         if ($tarX) {
            echo "\n";
         }
         showFullTar($tar);
      }
      break;
    case 'dump':
      if (!$positionals) {
        fatal("Missing indicator for kind of object to dump");
      }
      $kind = $positionals[0];
      $separator = '-----------------';
      switch ($kind) {
        case 'tars':
          echo "$separator\n";
          $tars = $tp->getAllTars($options);
          foreach ($tars as $tar) {
            dumpFullTar($tar);
            echo "$separator\n";
          }
          break;
        default:
          fatal("Unknown dump-type indicator $kind");
          break;
      }
      break;
    case 'import':
      if (!$positionals) {
        fatal("Missing indicator for what kind of object to import");
      }
      $kind = array_shift($positionals);
      if ($positionals) {
        $filename = $positionals[0];
        $fh = fopen($filename, 'r');
        if (!$fh) {
          fatal("Cannot open $filename for reading");
        }
      }
      else {
        $fh = STDIN;
      }
      importTars($tp, $fh);
      break;
    default:
      fatal("Unrecognized action code $action");
  }
}
catch (Exception $e) {
  fatal($e->getMessage());
}


function fatal($msg) {
  fwrite(STDERR, $msg . "\n");
  exit(1);
}

function userApproves($prompt) {
  echo "$prompt (y/n) ";
  $input = strtolower(trim(fgets(STDIN)));
  return substr($input, 0, 1) == 'y';
}



function showFullTar($tar) {
  $cols = Tarpit::getAvailableTarColumns();
  $pad = '';
  $desc = isset($tar['desc']) ? $tar['desc'] : '';
  foreach ($cols['index'] as $key) {
    if (isset($tar[$key])) {
      echo $pad, strtoupper($key), '-', $tar[$key], " $desc\n";
      $pad = '  ';
      $desc = '';
    }
  }
  if (isset($tar['url'])) {
    echo "  ", $tar['url'], "\n";
  }
  foreach (array('assigned|assignee', 'reported|reporter') as $keys) {
    list($when, $who) = explode('|', $keys);
    if (isset($tar[$when])) {
      $who = isset($tar[$who]) ? " to {$tar[$who]}" : '';
      echo "  ", ucfirst($when), " {$tar[$when]}$who\n";
    }
  }
  foreach ($cols['array'] as $key) {
    if (isset($tar[$key]))  {
      foreach ($tar[$key] as $item) {
        echo "  $item\n";
      }
    }
  }
  $shown = explode(' ', 'desc url assigned assignee reported reporter');
  foreach (array_diff($cols['scalar'], $shown) as $key) {
    if (isset($tar[$key])) {
      echo "  ", ucfirst($key), ": ", $tar[$key], "\n";
    }
  }
}

function dumpFullTar($tar) {
  $cols = Tarpit::getAvailableTarColumns();
  foreach ($cols['index'] as $key) {
    if (isset($tar[$key])) {
      echo "$key: {$tar[$key]}\n";
    }
  }
  foreach ($cols['scalar'] as $key) {
    if (isset($tar[$key])) {
      echo "$key: {$tar[$key]}\n";
    }
  }
  foreach ($cols['array'] as $key) {
    if (isset($tar[$key])) {
      foreach ($tar[$key] as $cX => $item) {
        echo "{$key}_{$cX}_: $item\n";
      }
    }
  }
}

function importTars($tp, $fh) {
  $cols = Tarpit::getAvailableTarColumns();
  $scalars = implode('|', array_merge($cols['index'], $cols['scalar']));
  $scalarRE = "/^($scalars): (.*)/";
  $arrays = implode('|', $cols['array']);
  $arrayRE = "/^($arrays)_(\d+)_: (.*)/";
  $activeKey = NULL;
  $tar = array();
  $lineno = 0;
  $tarstart = NULL;
  while (!feof($fh)) {
    ++$lineno;
    $line = rtrim(fgets($fh));
    if (substr($line, 0, 10) == '----------') {
      if ($tar) {
        try {
          $tp->addTar($tar, FALSE);
        }
        catch (Exception $e) {
          throw new Exception($e->getMessage() .
            " in tar starting at line $tarstart of import file");
        }
        $tar = array();
        $activeKey = NULL;
        $tarstart = NULL;
      }
      continue;
    }
    if (preg_match($scalarRE, $line, $matches)) {
      list(, $key, $value) = $matches;
      $tar[$key] = $value;
      $activeKey = NULL;
      if (!isset($tarstart)) {
        $tarstart = $lineno;
      }
      continue;
    }
    if (preg_match($arrayRE, $line, $matches)) {
      list(, $key, $index, $value) = $matches;
      if (!isset($tar[$key])) {
        $tar[$key] = array();
      }
      $tar[$key][$index] = $value;
      $activeKey = $key;
      continue;
    }
    if ($activeKey) {
      $tar[$activeKey][$index] .= "\n" . $value;
    }
    elseif (isset($tarstart)) {
      fatal("Cannot parse line $lineno of input file");
    }
  }
  $tp->save();
}

