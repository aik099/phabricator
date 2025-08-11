<?php

$conn_w = id(new DifferentialDiff())->establishConnection('w');

echo pht('Updating differential diff branches...')."\n";

$diffs = queryfx_all(
  $conn_w,
  'SELECT branch, id FROM %T WHERE sourceControlSystem = "svn" ORDER BY id ASC',
  'differential_diff');
foreach ($diffs as $diff) {
  $id = $diff['id'];
  echo pht('Migrating differential diff %d...', $id)."\n";
  if (!$diff['branch']) {
    continue;
  }

  $parts = explode('/', $diff['branch']);

  if (end($parts) === 'trunk') {
    // Return "trunk" as-is.
    $branch = 'trunk';
  } else {
    // Return "branches/branch-name", "tags/tag-name", etc.
    $part_count = count($parts);

    $branch = $parts[$part_count - 2].'/'.$parts[$part_count - 1];
  }

  queryfx(
    $conn_w,
    'UPDATE %T SET branch = %s WHERE id = %d',
    'differential_diff',
    $branch,
    $id);
}

echo pht('Done.')."\n";

