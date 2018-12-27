<?php
// Database-abstraction layer for tarpit

class DB {
   private static $db;

   static function init($filename) {
      $db = new SQLite3($filename);
      if (!$db) {
         throw new Exception("Could not start SQLite database at $filename");
      }
      $db->exec('PRAGMA encoding = "UTF-8"');
      $db->exec('PRAGMA foreign_keys = 1');
      self::$db = $db;
   }

   protected static function sqlerror($msg, $query = NULL) {
      if (strlen($msg) && $msg{0} != ' ') {
         $msg = ' ' . $msg;
      }
      $msg = self::$db->lastErrorMsg() . $msg;
      if (isset($query)) {
         $msg .= ' in query ' . str_replace("\n", ' ', substr($query, 0, 30));
      }
      throw new Exception($msg);
   }
}

