<?php
require_once __DIR__.'/config.php';
function install_schema(): void {
  $sql=file_get_contents(__DIR__.'/../database.sql');
  db()->exec($sql);
}
