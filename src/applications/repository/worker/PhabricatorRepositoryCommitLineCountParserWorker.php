<?php

final class PhabricatorRepositoryCommitLineCountParserWorker
  extends PhabricatorRepositoryCommitParserWorker {

  protected function getImportStepFlag() {
    return PhabricatorRepositoryCommit::IMPORTED_LINECOUNT;
  }

  protected function parseCommit(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit) {

    if (!$this->shouldSkipImportStep()) {
      $this->updateLineCounts($repository, $commit);
      $commit->writeImportStatusFlag($this->getImportStepFlag());
    }
  }

  private function updateLineCounts(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit) {
    $viewer = PhabricatorUser::getOmnipotentUser();

    list($add, $rem) = $this->loadLineCounts($viewer, $repository, $commit);

    $data = id(new PhabricatorRepositoryCommitData())->loadOneWhere(
      'commitID = %d',
      $commit->getID());
    if (!$data) {
      $data = id(new PhabricatorRepositoryCommitData())
        ->setCommitID($commit->getID());
    }

    $data->setCommitDetail(PhabricatorRepositoryCommit::DETAIL_LINES_ADDED, $add);
    $data->setCommitDetail(
      PhabricatorRepositoryCommit::DETAIL_LINES_REMOVED,
      $rem);
    $data->save();
  }

  private function loadLineCounts(
    PhabricatorUser $viewer,
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit) {

    $drequest = DiffusionRequest::newFromDictionary(
      array(
        'user' => $viewer,
        'repository' => $repository,
        'commit' => $commit->getCommitIdentifier(),
      ));

    $diff_info = DiffusionQuery::callConduitWithDiffusionRequest(
      $viewer,
      $drequest,
      'diffusion.rawdiffquery',
      array(
        'commit' => $commit->getCommitIdentifier(),
        'linesOfContext' => 0,
        'timeout' => 60,
        'byteLimit' => 8 * 1024 * 1024,
      ));

    if ($diff_info['tooSlow'] || $diff_info['tooHuge'] ||
        !$diff_info['filePHID']) {
      return array(0, 0);
    }

    $diff_file = id(new PhabricatorFileQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($diff_info['filePHID']))
      ->executeOne();
    if (!$diff_file) {
      return array(0, 0);
    }

    $raw_diff = $diff_file->loadFileData();
    if (!strlen(trim($raw_diff))) {
      // The commit has no file content changes (for example, it only added
      // a directory, or is a property-only change), so there is nothing to
      // parse.
      return array(0, 0);
    }

    $parser = new ArcanistDiffParser();
    $changes = $parser->parseDiff($raw_diff);

    $add = 0;
    $rem = 0;
    foreach ($changes as $change) {
      foreach ($change->getHunks() as $hunk) {
        $add += $hunk->getAddLines();
        $rem += $hunk->getDelLines();
      }
    }

    return array($add, $rem);
  }

}
