<?php

final class PhabricatorRepositoryCommitHeraldWorker
  extends PhabricatorRepositoryCommitParserWorker {

  protected function getImportStepFlag() {
    return PhabricatorRepositoryCommit::IMPORTED_HERALD;
  }

  public function getRequiredLeaseTime() {
    // Herald rules may take a long time to process.
    return phutil_units('4 hours in seconds');
  }

  protected function parseCommit(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit) {
    $viewer = PhabricatorUser::getOmnipotentUser();

    if ($this->shouldSkipImportStep()) {
      // This worker has no followup tasks, so we can just bail out
      // right away without queueing anything.
      return;
    }

    // Reload the commit to pull commit data and audit requests.
    $commit = id(new DiffusionCommitQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withIDs(array($commit->getID()))
      ->needCommitData(true)
      ->needAuditRequests(true)
      ->executeOne();
    $data = $commit->getCommitData();

    if (!$data) {
      throw new PhabricatorWorkerPermanentFailureException(
        pht(
          'Unable to load commit data. The data for this task is invalid '.
          'or no longer exists.'));
    }

    $commit->attachRepository($repository);

    $content_source = $this->newContentSource();

    $committer_phid = $data->getCommitDetail('committerPHID');
    $author_phid = $data->getCommitDetail('authorPHID');
    $acting_as_phid = nonempty(
      $committer_phid,
      $author_phid,
      id(new PhabricatorDiffusionApplication())->getPHID());

    $editor = id(new PhabricatorAuditEditor())
      ->setActor($viewer)
      ->setActingAsPHID($acting_as_phid)
      ->setContinueOnMissingFields(true)
      ->setContinueOnNoEffect(true)
      ->setContentSource($content_source);

    $xactions = array();
    $xactions[] = id(new PhabricatorAuditTransaction())
      ->setTransactionType(PhabricatorAuditTransaction::TYPE_COMMIT)
      ->setDateCreated($commit->getEpoch())
      ->setNewValue(array(
        'description'   => $data->getCommitMessage(),
        'summary'       => $data->getSummary(),
        'authorName'    => $data->getAuthorName(),
        'authorPHID'    => $commit->getAuthorPHID(),
        'committerName' => $data->getCommitDetail('committer'),
        'committerPHID' => $data->getCommitDetail('committerPHID'),
      ));

    try {
      $raw_patch = $this->loadRawPatchText($repository, $commit);
    } catch (Exception $ex) {
      $raw_patch = pht('Unable to generate patch: %s', $ex->getMessage());
    }
    $editor->setRawPatch($raw_patch);

    $result = $editor->applyTransactions($commit, $xactions);

    $this->detectJIRAIssues($repository, $commit, $data->getCommitMessage());

    return $result;
  }

  private function loadRawPatchText(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit) {
    $viewer = PhabricatorUser::getOmnipotentUser();

    $identifier = $commit->getCommitIdentifier();

    $drequest = DiffusionRequest::newFromDictionary(
      array(
        'user' => $viewer,
        'repository' => $repository,
      ));

    $time_key = 'metamta.diffusion.time-limit';
    $byte_key = 'metamta.diffusion.byte-limit';
    $time_limit = PhabricatorEnv::getEnvConfig($time_key);
    $byte_limit = PhabricatorEnv::getEnvConfig($byte_key);

    $diff_info = DiffusionQuery::callConduitWithDiffusionRequest(
      $viewer,
      $drequest,
      'diffusion.rawdiffquery',
      array(
        'commit' => $identifier,
        'linesOfContext' => 3,
        'timeout' => $time_limit,
        'byteLimit' => $byte_limit,
      ));

    if ($diff_info['tooSlow']) {
      throw new Exception(
        pht(
          'Patch generation took longer than configured limit ("%s") of '.
          '%s second(s).',
          $time_key,
          new PhutilNumber($time_limit)));
    }

    if ($diff_info['tooHuge']) {
      $pretty_limit = phutil_format_bytes($byte_limit);
      throw new Exception(
        pht(
          'Patch size exceeds configured byte size limit ("%s") of %s.',
          $byte_key,
          $pretty_limit));
    }

    $file_phid = $diff_info['filePHID'];
    $file = id(new PhabricatorFileQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($file_phid))
      ->executeOne();
    if (!$file) {
      throw new Exception(
        pht(
          'Failed to load file ("%s") returned by "%s".',
          $file_phid,
          'diffusion.rawdiffquery'));
    }

    return $file->loadFileData();
  }

  private function detectJIRAIssues(
    PhabricatorRepository $repository,
    PhabricatorRepositoryCommit $commit,
    $message) {
    $author_phid = $commit->getAuthorPHID();
    $jira_issues_field = new PhabricatorCommitJIRAIssuesField();

    // Commit author must be valid user with JIRA access permissions.
    if (!$author_phid || !$jira_issues_field->isFieldEnabled()) {
      return;
    }

    $parser = new DifferentialCommitMessageParser();
    $jira_issues = $parser->extractJiraIssues($message);

    if (!$jira_issues) {
      return;
    }

    $viewer = id(new PhabricatorPeopleQuery())
      ->setViewer(PhabricatorUser::getOmnipotentUser())
      ->withPHIDs(array($author_phid))
      ->needUserSettings(true)
      ->executeOne();

    try {
      DiffusionQuery::callConduitWithDiffusionRequest(
        $viewer,
        DiffusionRequest::newFromDictionary(
          array(
            'repository' => $repository,
            'user' => $viewer,
          )),
        'diffusion.commit.edit',
        array(
          'objectIdentifier' => $commit->getPHID(),
          'transactions' => array(
            array(
              'type' => $jira_issues_field->getModernFieldKey(),
              'value' => explode(', ', $jira_issues),
            )
          ),
        ));
    } catch (PhabricatorApplicationTransactionValidationException $ex) {
      // When non-existing JIRA task is used or user can't view it.
      $problematic_jira_issues = explode(
        ', ',
        $parser->extractJiraIssues($ex->getMessage())
      );

      if ($problematic_jira_issues) {
        /*
         * 1. Remove mentions of problematic Jira issues.
         * 2. Reparse patched $message.
         */
        $patched_message = str_replace($problematic_jira_issues, '', $message);
        $this->detectJIRAIssues($repository, $commit, $patched_message);
      }
    }
  }

}
