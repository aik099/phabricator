<?php

// Rehashes "Co-authored with AI" storage rows from the old config-driven
// key to the new hardcoded key. Safe on either install: only matching rows
// are touched.

$old_raw_keys = array(
  'rpi.co-authored-with-ai',
  'in-portal.co-authored-with-ai',
);

$migrations = array(
  array(
    'table' => new PhabricatorRepositoryCustomFieldStorage(),
    'namespace' => 'diffusion',
    'new_key' => 'diffusion:co-authored-with-ai',
  ),
  array(
    'table' => new DifferentialCustomFieldStorage(),
    'namespace' => 'differential',
    'new_key' => 'differential:co-authored-with-ai',
  ),
);

foreach ($migrations as $migration) {
  $table = $migration['table'];
  $conn_w = $table->establishConnection('w');
  $new_index = PhabricatorHash::digestForIndex($migration['new_key']);

  foreach ($old_raw_keys as $old_raw_key) {
    $old_key = 'std:'.$migration['namespace'].':'.$old_raw_key;
    $old_index = PhabricatorHash::digestForIndex($old_key);

    queryfx(
      $conn_w,
      'UPDATE %T SET fieldIndex = %s WHERE fieldIndex = %s',
      $table->getTableName(),
      $new_index,
      $old_index);

    echo tsprintf(
      "%s: %s -> %s (%d row(s))\n",
      $table->getTableName(),
      $old_key,
      $migration['new_key'],
      $conn_w->getAffectedRows());
  }
}

// herald_action.action stores "<field key>.<value>" as plain text; rewrite
// just the key prefix, preserving the ".<value>" suffix.

$action_table = new HeraldActionRecord();
$conn_w = $action_table->establishConnection('w');

$herald_migrations = array(
  array('namespace' => 'diffusion', 'new_key' => 'diffusion:co-authored-with-ai'),
  array('namespace' => 'differential', 'new_key' => 'differential:co-authored-with-ai'),
);

foreach ($herald_migrations as $migration) {
  foreach ($old_raw_keys as $old_raw_key) {
    $old_key = 'std:'.$migration['namespace'].':'.$old_raw_key;

    queryfx(
      $conn_w,
      'UPDATE %T SET action = REPLACE(action, %s, %s)
        WHERE action LIKE %>',
      $action_table->getTableName(),
      $old_key,
      $migration['new_key'],
      $old_key);

    echo tsprintf(
      "%s: %s -> %s (%d row(s))\n",
      $action_table->getTableName(),
      $old_key,
      $migration['new_key'],
      $conn_w->getAffectedRows());
  }
}
