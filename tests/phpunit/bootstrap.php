<?php

// Standalone bootstrap: geen CiviCRM DB nodig voor pure unit tests van _batchreminders_startup().
// De functie werkt alleen op meegegeven data (execFn callback) en heeft geen CRM_Core_DAO nodig.

ini_set('memory_limit', '512M');

// Definieer CIVICRM_SYSTEM zodat de include-guard in batchreminders.php niet blokkeert.
if (!defined('CIVICRM_SYSTEM')) {
  define('CIVICRM_SYSTEM', 1);
}

// Stub Civi::log() zodat de log-calls in _batchreminders_startup() niet crashen.
if (!class_exists('Civi')) {
  class Civi {
    public static function log() {
      return new class {
        public function info(string $msg):  void {}
        public function debug(string $msg): void {}
        public function error(string $msg): void {}
      };
    }
  }
}
