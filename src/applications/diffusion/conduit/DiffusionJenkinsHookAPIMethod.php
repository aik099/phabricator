<?php

final class DiffusionJenkinsHookAPIMethod
  extends DiffusionConduitAPIMethod {

  public function getAPIMethodName() {
    return 'diffusion.jenkinshook';
  }

  public function getMethodDescription() {
    return 'Notify about finished Jenkins build.';
  }

  public function defineParamTypes() {
    return array(
      'callsign'    => 'required string',
      'jobName'     => 'required string',
      'buildNumber' => 'required int',
    );
  }

  public function defineReturnType() {
    return 'array';
  }

  public function defineErrorTypes() {
    return array(
      'ERR_BAD_COMMIT' => pht('No commit found with that identifier'),
      'ERR_MISSING_JOB' => pht('Job is required.'),
      'ERR_BAD_JOB' => pht('Job not found.'),
      'ERR_MISSING_BUILD' => pht('Build is required.'),
      'ERR_BAD_BUILD' => pht('Build not found.'),
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $job_name = $request->getValue('jobName');
    if (!$job_name) {
      throw new ConduitException('ERR_MISSING_JOB');
    }

    $build_number = $request->getValue('buildNumber');
    if (!$build_number) {
      throw new ConduitException('ERR_MISSING_BUILD');
    }

    // Get Job Info.
    $job_info = JenkinsAPIRequest::create()
      ->addJob($job_name)
      ->addBuild($build_number)
      ->query();

    $commit_identifier = $this->guessRevision($job_info);

    $drequest = DiffusionRequest::newFromDictionary(array(
      'user' => $request->getUser(),
      'callsign' => $request->getValue('callsign'),
      'commit' => $commit_identifier,
    ));

    $commit = $drequest->loadCommit();
    if (!$commit) {
      throw new ConduitException('ERR_BAD_COMMIT');
    }

    $commit_url = PhabricatorEnv::getEnvConfig('phabricator.base-uri').
      $drequest->generateURI(array('action' => 'commit', 'stable' => true));

    $property = $this->getCommitProperty($commit, 'build-recorded');

    if ($property) {
      // Don't record same build twice.
      return array(
        'commitUri' => $commit_url,
        'actionTaken' => false,
      );
    }

    $commit_paths = $this->getCommitFiles($drequest);

    $api_request = new JenkinsAPIRequest();
    $api_request
      ->addJob($job_name)
      ->addBuild($build_number)
      ->setSuffix('submitDescription')
      ->setExpects('')
      ->setParams(array(
        'description' =>
          '<a href="'.$commit_url.'" target="_blank">'.$commit_url.'</a>',
      ))
      ->query();

    // 1. record checkstyle warnings
    $checkstyle_warnings =
      id(new JenkinsWarnings($job_name, $build_number, 'checkstyle/new'))
      ->get($commit_paths);
    $this->setCommitProperty(
      $commit, 'checkstyle:warnings', $checkstyle_warnings);
    $checkstyle_warning_count =
      $this->computeWarningCount($checkstyle_warnings);

    // 2. record pmd warnings
    $pmd_warnings =
      id(new JenkinsWarnings($job_name, $build_number, 'pmd/new'))
      ->get($commit_paths);
    $this->setCommitProperty($commit, 'pmd:warnings', $pmd_warnings);
    $pmd_warning_count = $this->computeWarningCount($pmd_warnings);

    // 3. record build information
    $message = "Build **#{$job_info->number}** finished ".
      "with **{$job_info->result}** status: {$job_info->url}\n";

    if ($checkstyle_warning_count || $pmd_warning_count) {
      $message .= "\n";

      if ($checkstyle_warning_count) {
        $message .= "\nCheckstyle Warnings: **".$checkstyle_warning_count.'**';
      }

      if ($pmd_warning_count) {
        $message .= "\nPMD Warnings: **".$pmd_warning_count.'**';
      }
    }

    $this->addComment(
      $commit, $request, $message,
      count($checkstyle_warnings) + count($pmd_warnings) == 0);

    // 4. mark as processed
    $this->setCommitProperty($commit, 'build-recorded', true);

    return array(
      'commitUri' => $commit_url,
      'checkstyleWarningCount' => $checkstyle_warning_count,
      'pmdWarningCount' => $pmd_warning_count,
      'actionTaken' => true,
    );
  }

  private function guessRevision($job_info) {
    if ($this->verifyChangesetProperty($job_info->changeSet, 'items')) {
      // Build triggered by commit > take it's revision.
      return $job_info->changeSet->items[0]->revision;
    }

    if ($this->verifyChangesetProperty($job_info->changeSet, 'revisions')) {
      // Build triggered manually > get "trunk/stable tag" revision.
      foreach ($job_info->changeSet->revisions as $revision) {
        if (preg_match('/(trunk|tags\/stable)$/', $revision->module)) {
          return $revision->revision;
        }
      }
    }

    $message = 'Unable to determine revision from build "%s" information';
    throw new Exception(sprintf($message, $job_info->fullDisplayName));
  }

  private function verifyChangesetProperty(stdClass $changeset, $property) {
    if (!property_exists($changeset, $property) || !$changeset->$property) {
      return false;
    }

    return is_array($changeset->$property) && count($changeset->$property) > 0;
  }

  private function getCommitFiles(DiffusionRequest $drequest) {
    $diff_info = DiffusionQuery::callConduitWithDiffusionRequest(
      $drequest->getUser(),
      $drequest,
      'diffusion.rawdiffquery',
      array(
        'path' => $drequest->getRepository()->isSVN() ? '/' : '.',
        'commit' => $drequest->getCommit(),
      ));

    $file_phid = $diff_info['filePHID'];
    $diff_file = id(new PhabricatorFileQuery())
     ->setViewer($drequest->getUser())
     ->withPHIDs(array($file_phid))
     ->executeOne();

    if (!$diff_file) {
     throw new Exception(
       pht(
         'Failed to load file ("%s") returned by "%s".',
         $file_phid,
         'diffusion.rawdiffquery'));
    }

    $raw_diff = $diff_file->loadFileData();

    /** @var ArcanistDiffChange[] $changes */
    $parser = new ArcanistDiffParser();
    $changes = $parser->parseDiff($raw_diff);

    $ret = array();
    foreach ($changes as $change) {
      if ($change->getFileType() !== ArcanistDiffChangeType::FILE_TEXT) {
        continue;
      }

      $lines = $change->getChangedLines('new');

      if ($lines) {
        $ret[$change->getCurrentPath()] = $lines;
      }
    }

    return $ret;
  }

  private function setCommitProperty(
    PhabricatorRepositoryCommit $commit, $name, $value) {
    $property = $this->getCommitProperty($commit, $name);

    if (!$value) {
      if ($property) {
        $property->delete();
      }

      return null;
    }

    if (!$property) {
      $property = new PhabricatorRepositoryCommitProperty();
      $property->setCommitID($commit->getID());
      $property->setName($name);
    }

    $property->setData($value);
    $property->save();

    return $property;
  }

  private function getCommitProperty(
    PhabricatorRepositoryCommit $commit, $name) {
    $property = id(new PhabricatorRepositoryCommitProperty())->loadOneWhere(
      'commitID = %d AND name = %s',
      $commit->getID(),
      $name);

    return $property;
  }

  private function computeWarningCount(array $warnings) {
    $count = 0;

    foreach ($warnings as $file => $file_warnings) {
      $count += count($file_warnings);
    }

    return $count;
  }

  private function addComment(
    PhabricatorRepositoryCommit $commit,
    ConduitAPIRequest $request,
    $message,
    $silent = false) {

    $conduit_call = new ConduitCall(
      'diffusion.commit.edit',
      array(
        'transactions' => array(
        	array(
            'type' => 'comment',
            'value' => $message,
            'silent' => $silent,
          ),
        ),
        'objectIdentifier' => $commit->getPHID(),
      ));

    $conduit_call
      ->setUser($request->getUser())
      ->execute();
  }
}
