<?php

// Standalone bootstrap: geen CiviCRM DB nodig voor pure unit tests van _batchreminders_startup().
// De functie werkt alleen op meegegeven data (execFn callback) en heeft geen CRM_Core_DAO nodig.

ini_set('memory_limit', '512M');

// Definieer CIVICRM_SYSTEM zodat de include-guard in batchreminders.php niet blokkeert.
if (!defined('CIVICRM_SYSTEM')) {
  define('CIVICRM_SYSTEM', 1);
}

// Stub Civi::log()/Civi::settings() zodat _batchreminders_startup() en
// batchreminders_civicrm_alterMailParams() niet crashen zonder echte CiviCRM-boot.
// $stubSettings is public zodat AlterMailParamsTest de batchsize kan instellen.
if (!class_exists('Civi')) {
  class Civi {
    public static $stubSettings = [];

    public static function log() {
      return new class {
        public function info(string $msg):    void {}
        public function debug(string $msg):   void {}
        public function warning(string $msg): void {}
        public function error(string $msg):   void {}
      };
    }

    public static function settings() {
      return new class {
        public function get(string $name) {
          return Civi::$stubSettings[$name] ?? NULL;
        }
      };
    }
  }
}

// Stub CRM_Core_DAO::singleValueQuery() — alterMailParams gebruikt 'm alleen
// voor een tellertje in de logregel bij de start van een batch, niet voor de
// abort-beslissing zelf. De teruggegeven waarde doet er dus niet toe.
if (!class_exists('CRM_Core_DAO')) {
  class CRM_Core_DAO {
    public static function singleValueQuery(string $sql) {
      return 0;
    }
  }
}
