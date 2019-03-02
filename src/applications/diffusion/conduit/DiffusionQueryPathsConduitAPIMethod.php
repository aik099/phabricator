<?php

final class DiffusionQueryPathsConduitAPIMethod
  extends DiffusionQueryConduitAPIMethod {

  public function getAPIMethodName() {
    return 'diffusion.querypaths';
  }

  public function getMethodDescription() {
    return pht('Filename search on a repository.');
  }

  protected function defineReturnType() {
    return 'list<string>';
  }

  protected function defineCustomParamTypes() {
    return array(
      'path' => 'required string',
      'commit' => 'required string',
      'pattern' => 'optional string',
      'limit' => 'optional int',
      'offset' => 'optional int',
    );
  }

  protected function defineCustomErrorTypes() {
    return array(
      'ERR-UNKNOWN-FOLDER-LAYOUT' => 'Unknown SVN repository folder layout',
    );
  }

  protected function getResult(ConduitAPIRequest $request) {
    $results = parent::getResult($request);
    $offset = $request->getValue('offset');
    return array_slice($results, $offset);
  }

  protected function getGitResult(ConduitAPIRequest $request) {
    $drequest = $this->getDiffusionRequest();
    $path = $drequest->getPath();
    $commit = $request->getValue('commit');
    $repository = $drequest->getRepository();

    // Recent versions of Git don't work if you pass the empty string, and
    // require "." to list everything.
    if (!strlen($path)) {
      $path = '.';
    }

    $future = $repository->getLocalCommandFuture(
      'ls-tree --name-only -r -z %s -- %s',
      $commit,
      $path);

    $lines = id(new LinesOfALargeExecFuture($future))->setDelimiter("\0");
    return $this->filterResults($lines, $request);
  }

  protected function getSVNResult(ConduitAPIRequest $request) {
    $drequest = $this->getDiffusionRequest();
    $path = $drequest->getPath();

    $raw_lines = queryfx_all(
      id(new PhabricatorRepository())->establishConnection('r'),
      'SELECT path FROM %T WHERE path LIKE %s',
      PhabricatorRepository::TABLE_PATH,
      '/'.$path.$this->getSVNRefName($request).'/%');

    $lines = array();
    $sub_path_start = strlen('/'.$path);
    foreach ($raw_lines as $raw_line) {
      $lines[] = substr($raw_line['path'], $sub_path_start);
    }

    return $this->filterResults($lines, $request);
  }

  private function getSVNRefName(ConduitAPIRequest $request) {
    $drequest = $this->getDiffusionRequest();

    $branches = DiffusionQuery::callConduitWithDiffusionRequest(
      $request->getUser(),
      $drequest,
      'diffusion.branchquery',
      array(
        'contains' => $drequest->getCommit(),
      ));

    if ($branches) {
      return idx(head($branches), 'shortName');
    }

    $tags = DiffusionQuery::callConduitWithDiffusionRequest(
      $request->getUser(),
      $drequest,
      'diffusion.tagsquery',
      array(
        'commit' => $drequest->getCommit(),
      ));

    if ($tags) {
      return idx(head($tags), 'name');
    }

    return $this->guessSVNRefName();
  }

  private function guessSVNRefName() {
    $drequest = $this->getDiffusionRequest();
    $path = $drequest->getPath();

    $path_change_query = DiffusionPathChangeQuery::newFromDiffusionRequest(
      $drequest);

    $first_changed_path = null;
    foreach ($path_change_query->loadChanges() as $change) {
      $first_changed_path = preg_replace(
        '#^'.$path.'#', '', $change->getPath());
      break;
    }

    $match_expressions = array(
      '#^trunk(/|$)#',
      '#^branches/([^/]+)#',
      '#^(tags|releases)/([^/]+)#',
    );

    $regs = null;
    foreach ($match_expressions as $match_expression) {
      if (preg_match($match_expression, $first_changed_path, $regs)) {
        return rtrim($regs[0], '/');
      }
    }

    throw new ConduitException('ERR-UNKNOWN-FOLDER-LAYOUT');
  }

  protected function getMercurialResult(ConduitAPIRequest $request) {
    $drequest = $this->getDiffusionRequest();
    $repository = $drequest->getRepository();
    $path = $request->getValue('path');
    $commit = $request->getValue('commit');

    $entire_manifest = id(new DiffusionLowLevelMercurialPathsQuery())
      ->setRepository($repository)
      ->withCommit($commit)
      ->withPath($path)
      ->execute();

    $match_against = trim($path, '/');
    $match_len = strlen($match_against);

    $lines = array();
    foreach ($entire_manifest as $path) {
      if (strlen($path) && !strncmp($path, $match_against, $match_len)) {
        $lines[] = $path;
      }
    }

    return $this->filterResults($lines, $request);
  }

  protected function filterResults($lines, ConduitAPIRequest $request) {
    $pattern = $request->getValue('pattern');
    $limit = (int)$request->getValue('limit');
    $offset = (int)$request->getValue('offset');

    if (strlen($pattern)) {
      // Add delimiters to the regex pattern.
      $pattern = '('.$pattern.')';
    }

    $results = array();
    $count = 0;
    foreach ($lines as $line) {
      if (strlen($pattern) && !preg_match($pattern, $line)) {
        continue;
      }

      $results[] = $line;
      $count++;

      if ($limit && ($count >= ($offset + $limit))) {
        break;
      }
    }

    return $results;
  }

}
