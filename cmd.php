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
        case 'assignee':
          $valTo = $bareSwitch;
          break;
        case 'assigned':
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
      $refno = $tp->addTar($options);
      echo "Added; refno = $refno\n";
      break;
    case 'edit':
      if (!$positionals) {
        fatal("Need reference for item to edit");
      }
      $refno = $positionals[0];
      $removals = array();
      if (isset($options['remove'])) {
        foreach ($options['remove'] as $item) {
          $removals = array_merge($removals, array_flip(explode(',', $item)));
        }
      }
      $tp->editTar($refno, $options, array_flip($removals));
      break;
    case 'show':
      if (!$positionals) {
        fatal("Need a reference for item to show");
      }
      $tar = $tp->fetchTar($positionals[0]);
      showFullTar($tar);
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


function showFullTar($tar) {
  $pad = '';
  $desc = isset($tar['desc']) ? $tar['desc'] : '';
  foreach (explode(' ', 'hd tar isd') as $key) {
    if (isset($tar[$key])) {
      echo $pad, strtoupper($key), '-', $tar[$key], " $desc\n";
      $pad = '  ';
      $desc = '';
    }
  }
  if (isset($tar['assigned'])) {
     $assignee = isset($tar['assignee']) ? " to {$tar['assignee']}" : '';
     echo "  Assigned {$tar['assigned']}$assignee\n";
  }
  if (isset($tar['comment']))  {
    foreach ($tar['comment'] as $item) {
      echo "  $item\n";
    }
  }
}

