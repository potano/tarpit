<?php
class Tarpit {
  const FILEPATH = "~/tars/tarpit.json";
  private static $filename;
  private static $ci = array(
    'hd' => 'tar search|type:int|min:1|desc:HD-number of issue',
    'isd' => 'tar search|type:int|min:1|desc:ISD-number of issue',
    'tar' => 'tar search|type:int|min:1|desc:TAR-number of issue',
    'desc' => 'tar search|type:str|min: |desc:Issue description',
    'url' => 'tar search|type:str|min: |desc:URL of the ticket (generally the HD ticket)',
    'branch' => 'tar search|type:str|min: |desc:Git branch in which fix was deployed',
    'reported' => 'tar search|type:dat|default:now|desc:Date when item was reported',
    'reporter' => 'tar search|type:str|min: |desc:Name of the one who made the report',
    'assigned' => 'tar search|type:dat|default:now|desc:Date the item was assigned',
    'assignee' => 'tar search|type:str|min: |desc:Name of the one to whom the ticket is assigned',
    'status' => 'tar search|type:enum|init:getStatusInfo|desc:Ticket status',
    'resolved' => 'tar search|type:dat|default:now|desc:Date when item was resolved',
    'comment' => 'tar search|type:arrstr|min: |desc:Comment (may occur multiple times per ticket)',
    'deployed' => 'tarpseudo|type:dat|default:now|init:getDeployedInfo',
  );

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

  function fetchTars($query, $options) {
    require_once 'Parser.php';
    $parser = new Parser($this);
    $tree = $parser->parse($query);
    $workset = $this->blob['alltars'];
    $curtar = 0;
    while ($curtar < count($workset)) {
      $tar = $workset[$curtar];
      if ($this->evalQuery($tree, $tar)) {
      if ($keep) {
        ++$curtar;
      }
      else {
        array_splice($workset, $curtar, 1);
      }
    }
    return $workset;
  }

  function isColumnSortable($name) {
    $info = self::columnInfo($name);
    return $info['sortable'];
  }

  function isTarColumn($name) {
    if (!isset(self::$columnInfo[$name])) {
      return FALSE;
    }
    $info = self::columnInfo($name);
    return isset($info['tar']);
  }

  function checkColumnValue($name, $value) {
    return $this->checkAllData(array('name' => $value));
  }



  private function evalQuery($tree, $tar) {
    $op = $tree[0];
    if ('!' != $op && 'log_or' != $op && 'log_and' != $op) {
      $sym = $tree[1];
      if ('isnull' == $op) {
        return !isset($tar[$sym]);
      }
      if (!isset($tar[$sym])) {
        return FALSE;
      }
      $val = $tar[$sym];
    }
    switch ($op) {
      case '!':
        return ! $this->evalQuery($tree[1], $tar);
      case '><':
        list(,, $lowLE, $sym, $low, $highLE, $high) = $tree;
        return ($lowLE ? $low <= $val : $low < $val) && ($highLE ? $val <= $high : $val < $high);
      case '<':
        return $val < $tree[2];
      case '<=':
        return $val <= $tree[2];
      case '=':
        return $val == $tree[2];
      case '<>':
        return $val != $tree[2];
      case '>=':
        return $val >= $tree[2];
      case '>':
        return $val > $tree[2];
      case 'isin':
        for ($i = 2; $i < count($tree); ++$i) {
          if ($val == $tree[$i]) {
            return TRUE;
          }
        }
        return FALSE;
      case 'like':
        if (is_array($val)) {
          $val = implode('', $val);
        }
        list(,, $anchorL, $anchorH, $segs) = $tree;
        $len = strlen($anchorL);
        if ($len) {
          if (substr($val, 0, $len) != $anchorL) {
            return FALSE;
          }
          $val = substr($val, $len);
        }
        $len = strlen($anchorR);
        if ($len) {
          if (substr($val, -$len) != $anchorR) {
            return FALSE;
          }
          $val = substr($val, 0, -$len);
        }
        foreach ($segs as $seg) {
          $len = strlen($seg);
          if (!$len) {
            continue;
          }
          $p = strpos($seg, $val);
          if ($p === FALSE) {
            return FALSE;
          }
          $val = substr($val, $p + $len);
        }
        return TRUE;
        break;
      case 'log_and':
        for ($i = 1; $i < count($tree); ++$i) {
          if (!$this->evalQuery($tree[$i], $tar)) {
            return FALSE;
          }
        }
        return TRUE;
        break;
      case 'log_or':
        for ($i = 1; $i < count($tree); ++$i) {
          if ($this->evalQuery($tree[$i], $tar)) {
            return TRUE;
          }
        }
        return FALSE;
        break;
      default:
        throw new Exception("Unknown opcode $op");
    }
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
    foreach (array_keys(self::$ci) as $key) {
      if (!array_key_exists($key, $data)) {
        continue;
      }
      $info = self::columnInfo($key);
      $value = $data[$key];
      $arr = is_array($info['isArray']);
      $min = isset($info['min']) ? $info['min'] : NULL;
      $max = isset($info['max']) ? $info['max'] : NULL;
      $default = isset($info['default']) ? $info['default'] : NULL;
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

  private function columnInfo($name) {
    if (!isset(self::$ci[$name])) {
      throw new Exception("Unknown column name $name");
    }
    if (is_array(self::$ci[$name])) {
      return self::$ci[$name];
    }
    $parts = explode('|', self::$ci[$name]);
    $info = array_merge(array('sortable' => TRUE),
      array_fill_keys(explode(' ', array_shift($parts)), TRUE));
    foreach ($parts as $item) {
      list($k, $v) = explode(':', $item, 2);
      $info[$k] = $v;
    }
    if (isset($info['init'])) {
      $fn = $info['init'];
      $info = array_merge($info, self::$fn());
    }
    $type = $info['type'];
    if (substr($type, 0, 3) == 'arr') {
      $info['isArray'] = TRUE;
      $info['type'] = $type = substr($type, 3);
    }
    if ('emum' == $type) {
      $info['sortable'] = FALSE;
    }
    $this->ci[$name] = $info;
    return $info;
  }

  private static getStatusInfo() {
    $keys = explode(',', 'new,active,waiting,blocked,reassigned,ready,deployed,disregarded');
    return array('sortable' => FALSE, 'max' => $keys);
  }
}

