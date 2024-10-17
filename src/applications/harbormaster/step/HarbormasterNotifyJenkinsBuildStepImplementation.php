<?php

final class HarbormasterNotifyJenkinsBuildStepImplementation
  extends HarbormasterBuildStepImplementation {

  private $build;
  private $buildTarget;
  private $drequest;

  public function getName() {
    return pht('Notify Jenkins');
  }

  public function getGenericDescription() {
    return pht('Notify Jenkins about a new commit.');
  }

  public function getBuildStepGroupKey() {
    return HarbormasterExternalBuildStepGroup::GROUPKEY;
  }

  public function execute(
    HarbormasterBuild $build,
    HarbormasterBuildTarget $build_target) {

    $this->build = $build;
    $this->buildTarget = $build_target;

    $variables = $build_target->getVariables();

    $this->drequest = DiffusionRequest::newFromDictionary(array(
      'user' => PhabricatorUser::getOmnipotentUser(),
      'callsign' => $variables['repository.callsign'],
      'commit' => $variables['buildable.commit'],
    ));

    $this->notifyJenkins();
  }

  protected function notifyJenkins() {
    $repository = $this->drequest->getRepository();

    switch ($repository->getVersionControlSystem()) {
      case PhabricatorRepositoryType::REPOSITORY_TYPE_GIT:
        $this->notifyGit();
        break;
      case PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL:
        $this->notifyMercurial();
        break;
      case PhabricatorRepositoryType::REPOSITORY_TYPE_SVN:
        $this->notifySVN();
        break;
      default:
        throw new ConduitException('ERR-UNKNOWN-VCS-TYPE');
        break;
    }
  }

  protected function notifyGit() {
    // TODO: https://wiki.jenkins-ci.org/display/JENKINS/Git+Plugin
    throw new ConduitException('ERR-UNSUPPORTED-VCS');
  }

  protected function notifySVN() {
    $uri = rtrim(PhabricatorEnv::getEnvConfig('jenkins.base-uri'), '/');
    $uri .= '/subversion/%s/notifyCommit?rev=%s';

    $repository_uuid = PhabricatorEnv::getEnvConfig('jenkins.repository-uuid');
    $user_id = PhabricatorEnv::getEnvConfig('jenkins.user-id');
    $api_token = PhabricatorEnv::getEnvConfig('jenkins.api-token');

    if (empty($repository_uuid)) {
      throw new ConduitException('ERR-UNKNOWN-REPOSITORY');
    }

    $uri = vsprintf($uri, array(
      $repository_uuid,
      $this->drequest->getCommit(),
    ));

    $future = id(new HTTPSFuture($uri, $this->getSvnLookOutput()))
      ->setMethod('POST')
      ->addHeader('Content-Type', 'text/plain;charset=UTF-8')
      ->setTimeout(30)
      ->setHTTPBasicAuthCredentials($user_id, new PhutilOpaqueEnvelope($api_token));

    $this->resolveFutures(
      $this->build,
      $this->buildTarget,
      array($future));

    list($status, $body, $headers) = $future->resolve();

    $header_lines = array();
    foreach ($headers as $header) {
      list($head, $tail) = $header;
      $header_lines[] = "{$head}: {$tail}";
    }
    $header_lines = implode("\n", $header_lines);

    $this->buildTarget
      ->newLog($uri, 'http.head')
      ->append($header_lines);

    $this->buildTarget
        ->newLog($uri, 'http.body')
        ->append($body);

    if ($status->getStatusCode() != 200) {
      throw new HarbormasterBuildFailureException();
    }
  }

  private function getSvnLookOutput() {
    $path_change_query = DiffusionPathChangeQuery::newFromDiffusionRequest(
      $this->drequest);
    $path_changes = $path_change_query->loadChanges();

    $svnlook_output = '';

    foreach ($path_changes as $change) {
      $path = $change->getPath();

      if ($change->getFileType() == DifferentialChangeType::FILE_DIRECTORY) {
        $path .= '/';
      }

      $change_letter = $this->getSvnLookChangeLetter($change->getChangeType());
      $svnlook_output .= $change_letter.'  '.$path."\n";
    }

    return $svnlook_output;
  }

  private function getSvnLookChangeLetter($change_type) {
    switch ($change_type) {
      case DifferentialChangeType::TYPE_ADD:
      case DifferentialChangeType::TYPE_MOVE_HERE:
      case DifferentialChangeType::TYPE_COPY_HERE:
        return 'A ';

      case DifferentialChangeType::TYPE_DELETE:
      case DifferentialChangeType::TYPE_MOVE_AWAY:
        return 'D ';

      default:
        return 'U ';
    }
  }

  protected function notifyMercurial() {
    // TODO: https://wiki.jenkins-ci.org/display/JENKINS/Mercurial+Plugin
    throw new ConduitException('ERR-UNSUPPORTED-VCS');
  }

}
