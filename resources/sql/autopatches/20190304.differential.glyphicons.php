<?php

$table = new DifferentialRevision();
$conn = $table->establishConnection('w');
$viewer = PhabricatorUser::getOmnipotentUser();

/** @var DifferentialRevision $revision */
foreach (new LiskMigrationIterator($table) as $revision) {
  echo tsprintf('Processing %s... ', $revision->getPHID());

  /** @var DifferentialDiff $diff */
  $diff = id(new DifferentialDiffQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($revision->getActiveDiffPHID()))
      ->executeOne();
  if (!$diff) {
    echo tsprintf('error (no diff)' . PHP_EOL);
  }

  $revision->setLineCount($diff->getLineCount());

  $row = queryfx_one(
    $conn,
    'SELECT SUM(addLines) A, SUM(delLines) D FROM %T
      WHERE diffID = %d',
    id(new DifferentialChangeset())->getTableName(),
    $diff->getID());

  if ($row) {
    $revision->setAddedLineCount((int)$row['A']);
    $revision->setRemovedLineCount((int)$row['D']);
  }

  $revision->save();

  echo tsprintf('done' . PHP_EOL);
}
