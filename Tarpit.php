<?php
class Tarpit {
  const FILEPATH = "~/tars/tarpit.json";
  private static $filename;
  private static $tarkeys = array(
    'hd' => 'int>0',
    'isd' => 'int>0',
    'tar' => 'int>0',
    'desc' => 'str>',
    'url' => 'str>',
    'branch' => 'str>',
    'reported' => 'dat',
    'reporter' => 'str>',
    'assigned' => 'dat=now',
    'assignee' => 'str>',
    'status' => 'enu<new,active,waiting,blocked,reassigned,ready,deployed,disregarded',
    'resolved' => 'dat=now',
    'comment' => 'arrstr>',
  );
  private static $indexkeys = array('hd', 'tar', 'isd');
  private static $flippedIndexkeys;
  private static $staticblob;

  private $blob;

  function __construct() {
    self::initStore();
    $this->blob = & self::$staticblob;
  }

  function save() {
    file_put_contents(self::$filename, json_encode(self::$staticblob));
  }

  function addTar($data, $flush = TRUE) {
    $data = $this->checkAllData($data);
    if (!isset($data['isd'])) {
      throw new Exception("New item has no ISD number");
    }
    $this->checkTarRefererenceForCollisions($data);
    $refno = count($this->blob['alltars']);
    $this->updateIndices($data, $refno);
    $this->blob['alltars'][$refno] = $data;
    if ($flush) {
      $this->save();
    }
    return $refno;
  }

  function editTar($refno, $data, $removals, $flush = TRUE) {
    $data = $this->checkAllData($data);
    $refno = $this->findTar($refno);
    $this->checkTarRefererenceForCollisions($data, $refno);
    $item = $this->blob['alltars'][$refno];
    $origrefs = array_intersect_key($item, self::$flippedIndexkeys);
    if (isset($data['comment'])) {
      if (isset($item['comment'])) {
        $item['comment'] = array_merge($item['comment'], $data['comment']);
        unset($data['comment']);
      }
    }
    $item = array_merge($item, $data);
    foreach ($removals as $key) {
      if (preg_match('/^comment_*(\d*)/', $key, $matches)) {
        $index = $matches[1];
        if (ctype_digit($index)) {
          array_splice($item['comment'], $index, 1);
        }
      }
      else {
        unset($item[$key]);
      }
    }
    $refs = array_intersect_key($item, self::$flippedIndexkeys);
    $removedRefs = array_diff_key($origrefs, $refs);
    $remainingRefs = array_diff_key($refs, $removedRefs);
    if (!$remainingRefs) {
      $rr = self::conjuctiveList($removedRefs, 'and');
      throw new Exception("Removal of $rr would remove all keys to document");
    }
    foreach ($removedRefs as $key => $val) {
      unset($this->blob['tarindex'][$key][$val]);
    }
    $this->updateIndices($item, $refno);
    $this->blob['alltars'][$refno] = $item;
    if ($flush) {
      $this->save();
    }
  }

  function fetchTar($ref) {
    $refno = $this->findTar($ref);
    $this->checkTarRefererenceForCollisions($ref, $refno);
    return $this->blob['alltars'][$refno];
  }

  function removeTar($refno) {
    $refno = $this->findTar($refno);
    array_splice($this->blob['alltars'], $refno, 1);
    foreach ($this->blob['tarindex'] as $key => $list) {
      $rmval = NULL;
      foreach ($list as $val => $rn) {
        if ($rn == $refno) {
          $rmval = $val;
        }
        elseif ($rn > $refno) {
          --$this->blob['tarindex'][$key][$val];
        }
      }
      if ($rmval) {
        unset($this->blob['tarindex'][$key][$rmval]);
      }
    }
    $this->save();
  }

  function checkTarRefererenceForCollisions($ref, $refno = NULL) {
    $refs = self::refToMap($ref);
    foreach ($refs as $key => $value) {
      if (isset($this->blob['tarindex'][$key][$value])) {
        if (isset($refno)) {
          if ($refno != $this->blob['tarindex'][$key][$value]) {
            throw new Exception("Reference $key-$value exists and collides with another record");
          }
        }
        else {
          throw new Exception("Reference $key-$value already exists");
        }
      }
    }
  }

  function findTar($ref, $check = TRUE) {
    $refs = self::refToMap($ref);
    $refno = NULL;
    foreach ($refs as $key => $value) {
      if (isset($this->blob['tarindex'][$key][$value])) {
        $refno = $this->blob['tarindex'][$key][$value];
        break;
      }
    }
    if (!isset($refno) || !isset($this->blob['alltars'][$refno])) {
      $group = array();
      foreach ($refs as $k2 => $v2) {
        $group[] = "$k2-$v2";
      }
      $group = self::conjunctiveList($group, 'or');
      if (!isset($refno)) {
        throw new Exception("Cannot find $group");
      }
      throw new Exception("Internal error: cannot map $refno (from $key-$value) to a record");
    }
    return $refno;
  }

  function getRangeConstraints($data) {
    $out = array();
    foreach (self::$tarkeys as $key => $type) {
      if (!array_key_exists($key, $data)) {
        continue;
      }
      $spec = $data[$key];
      $constraints = array_fill_keys(explode(' ', '= <> > >= < <='), array());
      $nullable = FALSE;
      while (strlen($spec)) {
        if (!preg_match('/^(=|>=|<=|<>|>|<)([^=<>]*)/', $spec, $matches)) {
          break;
        }
        list($match, $op, $val) = $matches;
        if ('=' == $op && 'null' == $val) {
          $nullable = TRUE;
        }
        else {
          $spec = substr($spec, strlen($match));
          $val = $this->checkAllData(array($key => $val));
          $constraints[$op][] = $val;
        }
      }
      if (strlen($spec)) {
        foreach (explode(',', $spec) as $val) {
          if ('null' == $val) {
            $nullable = TRUE;
          }
          else {
            $val = $this->checkAllData(array($key => $val));
            $constraints['='][] = $val;
          }
        }
      }
      $comp = array();
      foreach (array_keys($constraints) as $op) {
        sort($constraints[$op]);
      }
      foreach ($constraints['>'] as $val) {
        $comp[] = array($val, NULL, NULL, NULL);
      }
      foreach ($constraints['>='] as $val) {
        $slotX = -1;
        foreach ($comp as $cX => $slot) {
          if ($val < $slot[0]) {
            $slotX = $cX;
            break;
          }
        }
        $new = array(NULL, $val, NULL, NULL);
        if ($slotX >= 0) {
          array_splice($comp, $slotX, 0, $new);
        }
        else {
          $comp[] = $new;
        }
      }
      foreach ($constraints['<='] as $val) {
        $found = FALSE;
        foreach ($comp as $cX => $slot) {
          if ((isset($slot[0]) && $val < $slot[0]) || (isset($slot[1]) && $val < $slot[0])) {
            $found = TRUE;
            if ($cX) {
              $comp[$cX - 1][2] = $val;
            }
            break;
          }
        }
        if (!$found) {
          if ($comp) {
            $comp[count($comp)-1][2] = $val;
          }
          else {
            $comp[] = array(NULL, NULL, $val, NULL);
          }
        }
      }
      foreach ($constraints['<'] as $val) {
        $found = FALSE;
        foreach ($comp as $cX => $slot) {
          if ((isset($slot[0]) && $val < $slot[0]) || (isset($slot[1]) && $val < $slot[0])) {
            $found = TRUE;
            if ($cX) {
              --$cX;
              if (isset($comp[$cX][2]) && $val > $comp[$cX][2]) {
                $comp[$cX][2] = NULL;
              }
              $comp[$cX][3] = $val;
            }
            break;
          }
        }
        if (!$found) {
          if ($comp) {
            $cX = count($comp) - 1;
            if (isset($comp[$cX][2]) && $val > $comp[$cX][2]) {
              $comp[$cX][2] = NULL;
            }
          }
          else {
            $comp[] = array(NULL, NULL, NULL, $val);
          }
        }
      }
      $out[$key] = array($nullable, $constraints['='], $constraints['<>'], $comp);;
    }
    return $out;
  }

  function fetchTars($ranges) {
    $workset = $this->blob['alltars'];
    $curtar = 0;
    while ($curtar < count($workset)) {
      $tar = $workset[$curtar];
      $keep = TRUE;
      foreach ($ranges as $key => $constraints) {
        $try = 0;
        list($nullable, $eq, $ne, $comps) = $constraints;
        if (!isset($curtar[$key])) {
          if (!$nullable) {
            $keep = FALSE;
            break;
          }
          continue;
        }
        $value = $curtar[$key];
        if ($eq) {
          foreach ($eq as $val) {
            if ($val == $value) {
              $try = 1;
              break;
            }
          }
        }
        if ($ne) {
          foreach ($ne as $val) {
            if ($val == $value) {
              $try = -1;
              break;
            }
          }
        }
        if ($try) {
          if ($try < 0) {
            $keep = FALSE;
            break;
          }
          continue;
        }
        foreach ($comps as $comp) {
          list($lt, $le, $ge, $gt) = $comp;
          if (isset($lt) && $value <= $lt) {
            continue;
          }
          if (isset($le) && $value < $le) {
            continue;
          }
          if (isset($ge) && $value > $ge) {
            continue;
          }
          if (isset($gt) && $value >= $gt) {
            continue;
          }
          $try = 1;
          break;
        }
        if (!$try) {
          $keep = FALSE;
          break;
        }
      }
      if ($keep) {
        ++$curtar;
      }
      else {
        array_splice($workset, $curtar, 1);
      }
    }
    return $workset;
  }


  function getAllTars($options) {
    return $this->blob['alltars'];
  }

  static function getAvailableTarColumns() {
    $scalar = $array = array();
    $index = self::$indexkeys;
    $indexers = self::$flippedIndexkeys;
    foreach (self::$tarkeys as $key => $type) {
      if (!isset($indexers[$key])) {
        if (substr($type, 0, 3) == 'arr') {
          $array[] = $key;
        }
        else {
          $scalar[] = $key;
        }
      }
    }
    return compact('index', 'scalar', 'array');
  }

  static function getAvailableStatusCodes() {
    $item = self::$tarkeys['status'];
    return explode(',', preg_replace('/^.*?<([^<>=]*).*$/', '\1', $item));
  }


  private function updateIndices($data, $refno) {
    foreach (self::$indexkeys as $key) {
      if (isset($data[$key])) {
        $this->blob['tarindex'][$key][$data[$key]] = $refno;
      }
    }
  }

  private static function refToMap($ref) {
    if (is_array($ref)) {
      return array_intersect_key($ref, self::$flippedIndexkeys);
    }
    if (!preg_match('/^(hd|isd|tar)\s*(?:-\s*)?(\d+)/', strtolower($ref), $matches)) {
      throw new Exception("Cannot recognize $ref as a lookup key");
    }
    return array($matches[1] => $matches[2]);
  }

  private function checkAllData($data) {
    $out = array();
    foreach (self::$tarkeys as $key => $type) {
      if (!array_key_exists($key, $data)) {
        continue;
      }
      $value = $data[$key];
      preg_match('/^(arr)?([[:alpha:]]{3})(>([^<>=]*))?(<([^<>=]*))?(=([^<>=]*))?()$/',
        $type, $matches);
      list(, $arr, $type, $havemn, $min, $havemx, $max, $havedef, $default) = $matches;
      $min = $havemn ? $min : NULL;
      $max = $havemx ? $max : NULL;
      $default = $havedef ? $default : NULL;
      if (is_array($value)) {
        $outv = array();
        foreach ($value as $v) {
          $outv[] = $this->testval($v, $key, $type, $min, $max, $default);
        }
        $value = $outv;
      }
      else {
        $value = $this->testval($value, $key, $type, $min, $max, $default);
      }
      $out[$key] = $value;
    }
    return $out;
  }

  private function testval($value, $key, $type, $minval, $maxval, $default) {
    if (!isset($value)) {
      if (!isset($default)) {
        throw new Exception("Need an explicit value for $key");
      }
      $value = $default;
    }
    else {
      switch ($type) {
        case 'int':
          if (!ctype_digit($value)) {
            throw new Exception("$value is not an integer");
          }
          break;
        case 'dat':
          $ts = strtotime($value);
          if (!$ts) {
            throw new Exception("Invalid date: $value");
          }
          $value = date('Y-m-d H:i:s', $ts);
          break;
        case 'enu':
          $range = explode(',', $maxval);
          $maxval = NULL;
          if (!in_array($value, $range)) {
            throw new Exception("Unknown $key value '$value'\n(must be one of " .
              self::conjunctiveList($range, 'or') . ")");
          }
          break;
      }
      if (isset($minval) && $value < $minval) {
        throw new Exception("'$value' is below the minimum for $key");
      }
      if (isset($maxval) && $value > $maxval) {
        throw new Exception("'$value' is above the maximum for $key");
      }
    }
    if ('dat' == $type) {
      return strtoupper(date('Y-m-d', strtotime($value)));
    }
    return $value;
  }

  private static function conjunctiveList($arr, $conj) {
    switch (count($arr)) {
      case 0:
        return '';
      case 1:
        return $arr[0];
      default:
        $arr = explode(' ', implode(', ', $arr));
        //fall through
      case 2:
        array_splice($arr, count($arr) - 1, 0, $conj);

    }
    return implode(' ', $arr);
  }

  private static function initStore() {
    if (self::$staticblob) {
      return;
    }
    self::$flippedIndexkeys = array_flip(self::$indexkeys);
    if (!isset(self::$filename)) {
      $filename = self::FILEPATH;
      if ($filename{0} == '~') {
        $home = $_SERVER['HOME'];
        if ($filename{1} == '/') {
          $filename = $home . substr($filename, 1);
        }
        else {
          $filename = dirname($home) . preg_replace('!^[^/]*!', '', $filename);
        }
      }
      self::$filename = $filename;
    }
    $filename = self::$filename;
    if (!strlen($filename)) {
      throw new Exception("Cannot determine name of database file");
    }
    if (!is_dir(dirname($filename))) {
      throw new Exception("Cannot open serialization file; directory does not exist");
    }
    if (!is_file($filename)) {
      self::$staticblob = array(
        'alltars' => array(),
        'tarindex' => array_fill_keys(self::$indexkeys, array()),
      );
    }
    else {
      $blob = @ file_get_contents($filename);
      if (!$blob) {
        throw new Exception("Could not read serialization file $filename");
      }
      self::$staticblob = @ json_decode($blob, TRUE);
      if (!self::$staticblob) {
        throw new Exception("Serialization file $filename does not contain valid JSON");
      }
    }
  }
}

