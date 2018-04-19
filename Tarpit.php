<?php
class Tarpit {
  const FILEPATH = "~/tars/tarpit.json";
  private static $filename;
  private static $tarkeys = array(
    'hd' => 'int>0',
    'isd' => 'int>0',
    'tar' => 'int>0',
    'desc' => 'str>""',
    'url' => 'str>""',
    'branch' => 'str>""',
    'assignee' => 'str>""',
    'comment' => 'str>""',
    'assigned' => 'date=now',
  );
  private static $indexkeys = array('hd', 'tar', 'isd');
  private static $staticblob;

  private $blob;

  function __construct() {
    self::initStore();
    $this->blob = & self::$staticblob;
  }

  function addTar($data) {
    $data = $this->checkAllData($data);
    if ($this->findTar($data)) {
      throw new Exception("Reference is in use");
    }
    if (!isset($data['isd'])) {
      throw new Exception("New item has no ISD number");
    }
    $refno = count($this->blob['alltars']);
    $this->updateIndices($data, $refno);
    $this->blob['alltars'] = $data;
    $this->save();
    return $refno;
  }

  function editTar($refno, $data, $removals) {
    if (!isset($this->blob['alltars'][$refno])) {
      throw new Exception("Index error: cannot locate TAR");
    }
    $data = $this->checkAllData($data);
    $item = $this->blob['alltars'][$refno];
    $origrefs = array_intersect_key($item, self::$indexkeys);
    if (isset($data['comment'])) {
      if (isset($item['comment'])) {
        $item['comment'] = array_merge($item['comment'], $data['comment']);
        unset($data['comment']);
      }
    }
    $item = array_merge($item, $data);
    foreach ($removals as $key) {
      if (substr($key, 0, 6) == 'comment') {
        $index = preg_replace('/comment[^\d]*/', '', $key);
        array_splice($blob['comment'], $index, 1);
      }
      else {
        unset($blob[$key]);
      }
    }
    $refs = array_intersect_key($item, self::$indexkeys);
    $removedRefs = array_intersect_key($origrefs, $refs);
    if (!$refs) {
      $rr = implode(', ', $removedRefs);
      if (count($removedRefs) > 1) {
        $rr = explode(' ', $rr);
        array_splice($rr, count($removedRefs) - 2, 0, 'and');
        $rr = implode(' ', $rr);
      }
      throw new Exception("Removal of $rr would remove all keys to document");
    }
    foreach ($removedRefs as $key => $val) {
      unset($this->blob['tarindex'][$key][$val]);
    }
    $this->updateIndices($item, $refno);
    $blob['alltars'][$refno] = $item;
    $this->save();
  }

  function findTar($ref) {
    if (is_array($ref)) {
      foreach (self::$indexkeys as $key) {
        if (isset($ref[$key])) {
          break;
        }
      }
      $val = $ref[$key];
    }
    else {
      if (!preg_match('/^(hd|isd|tar)\s*(-\s*)?(\d+)/', strtolower($ref), $matches)) {
        throw new Exception("Cannot recognize $ref as a lookup key");
      }
      list(, $key, $val) = $matches;
    }
    if (!isset($this->blob['tarindex'][$key][$val])) {
      throw new Exception("Cannot find $key $val");
    }
    $refno = $this->blob['tarindex'][$key][$val];
    foreach (self::$indexkeys as $key2) {
      $v2 = $ref[$key2];
      if (isset($ref[$key2]) && $this->blob['tarindex'][$key2][$v2] != $refno) {
        throw new Exception("Key mismatch! $key2 $v2 refers to different document than $key $val");
      }
    }
    return $refno;
  }


  private function updateIndices($data, $refno) {
    foreach (self::$indexkeys as $key) {
      if (isset($data[$key])) {
        $this->blob['tarindex'][$key][$data[$key]] = $refno;
      }
    }
  }

  private function checkAllData($data) {
    $out = array();
    foreach (self::$tarkeys as $key => $type) {
      if (!array_key_exists($key, $data)) {
        continue;
      }
      $value = $data[$key];
      preg_match('/^[[:alpha:]]{3}(<([^<>=]*))?(>([^<>=]*))?(=([^<>=]*))?()$/', $type, $matches);
      list(, $type, $havemn, $min, $havemx, $max, $havedef, $default) = $matches;
      $min = $havemn ? $min : NULL;
      $max = $havemx ? $max : NULL;
      $default = $havedef ? $default : NULL;
      if (is_array($value)) {
        $outv = array();
        foreach ($value as $v) {
          $outv[] = $this->testval($v, $key, $type, $minval, $maxval, $default);
        }
        $value = $outv;
      }
      else {
        $value = $this->testval($value, $key, $type, $minval, $maxval, $default);
      }
      $out[$key] = $value;
    }
    return $out;
  }

  private function testval($value, $key, $type, $minval, $maxval, $default) {
    if (!isset($value)) {
      if (isset($default)) {
        return $default;
      }
      throw new Exception("Need an explicit value for $key");
    }
    switch ($type) {
      case 'int':
        if (!ctype_digit($value)) {
          throw new Exception("$value is not an integer");
        }
        break;
    }
    if (isset($minval) && $value < $minval) {
      throw new Exception("$value is below the minimum for $key");
    }
    if (isset($maxval) && $value > $maxval) {
      throw new Exeption("$value is above the maximum for $key");
    }
  }

  private static function initStore() {
    if (self::$staticblob) {
      return;
    }
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

  private function save() {
    file_put_contents(json_encode(self::$blob));
  }

}

