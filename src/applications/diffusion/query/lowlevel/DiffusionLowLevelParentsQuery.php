<?php

final class DiffusionLowLevelParentsQuery
  extends DiffusionLowLevelQuery {

  private $identifier;

  public function withIdentifier($identifier) {
    $this->identifier = $identifier;
    return $this;
  }

  protected function executeQuery() {
    if (!strlen($this->identifier)) {
      throw new PhutilInvalidStateException('withIdentifier');
    }

    $type = $this->getRepository()->getVersionControlSystem();
    switch ($type) {
      case PhabricatorRepositoryType::REPOSITORY_TYPE_GIT:
        $result = $this->loadGitParents();
        break;
      case PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL:
        $result = $this->loadMercurialParents();
        break;
      case PhabricatorRepositoryType::REPOSITORY_TYPE_SVN:
        $result = $this->loadSubversionParents();
        break;
      default:
        throw new Exception(pht('Unsupported repository type "%s"!', $type));
    }

    return $result;
  }

  private function loadGitParents() {
    $repository = $this->getRepository();

    list($stdout) = $repository->execxLocalCommand(
      'log -n 1 --format=%s %s',
      '%P',
      $this->identifier);

    return preg_split('/\s+/', trim($stdout));
  }

  private function loadMercurialParents() {
    $repository = $this->getRepository();

    list($stdout) = $repository->execxLocalCommand(
      'log --debug --limit 1 --template={parents} --rev %s',
      $this->identifier);

    $stdout = DiffusionMercurialCommandEngine::filterMercurialDebugOutput(
      $stdout);

    $hashes = preg_split('/\s+/', trim($stdout));
    foreach ($hashes as $key => $value) {
      // Mercurial parents look like "23:ad9f769d6f786fad9f76d9a" -- we want
      // to strip out the local rev part.
      list($local, $global) = explode(':', $value);
      $hashes[$key] = $global;

      // With --debug we get 40-character hashes but also get the "000000..."
      // hash for missing parents; ignore it.
      if (preg_match('/^0+$/', $global)) {
        unset($hashes[$key]);
      }
    }

    return $hashes;
  }

  private function loadSubversionParents() {
    $parent_commit = $this->getRepository()->getSubversionParentCommit(
      $this->identifier);

    if ($parent_commit) {
      $merged_commits = $this->getRepository()->getSubversionMergedCommits(
        $this->identifier);

      /*
       * Same commit could be both parent and merged in a given commit, but
       * don't remove it, because it will break merge commit detection code.
       */
      return array_merge(array($parent_commit), $merged_commits);
    }

    return array();
  }

}
