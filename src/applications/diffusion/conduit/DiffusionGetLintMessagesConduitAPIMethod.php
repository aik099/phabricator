<?php

final class DiffusionGetLintMessagesConduitAPIMethod
  extends DiffusionConduitAPIMethod {

  const SOURCE_BRANCH = 'branch';
  const SOURCE_COMMIT = 'commit';

  public function getAPIMethodName() {
    return 'diffusion.getlintmessages';
  }

  public function getMethodStatus() {
    return self::METHOD_STATUS_UNSTABLE;
  }

  public function getMethodSummary() {
    return pht('Get lint messages of a branch or a commit.');
  }

  public function getMethodDescription() {
    return pht(
      'Get lint messages for the given `files` (repository paths with a '.
      'leading slash).'.
      "\n\n".
      'Sources (`source` parameter):'.
      "\n\n".
      '  - `%s` (default): latest saved lint run of a `branch` (empty '.
      'means the default branch), covering whole files. `commit` is '.
      'ignored.'.
      "\n".
      '  - `%s`: Checkstyle/PMD warnings Jenkins recorded for the given '.
      '`commit`, limited to lines that commit changed. `branch` is ignored.'.
      "\n\n".
      'Each message has `path`, `line`, `code`, `severity` (`error`, '.
      '`warning` or `advice`), `name` (rule) and `description`. Commit '.
      'codes look like `PHPCS.E.<rule>`, `PHPCS.W.<rule>` or '.
      '`PHPMD.<rule>`, where a PHPCS `<rule>` is only the last two parts of '.
      'the sniff name; `code` and `name` are null for commits recorded '.
      'before rule names were stored.',
      self::SOURCE_BRANCH,
      self::SOURCE_COMMIT);
  }

  protected function defineParamTypes() {
    return array(
      'repositoryPHID' => 'required phid',
      'branch'         => 'required string',
      'commit'         => 'optional string',
      'files'          => 'required list<string>',
      'source'         => 'optional string',
    );
  }

  protected function defineReturnType() {
    return 'list<dict>';
  }

  protected function execute(ConduitAPIRequest $request) {
    $viewer = $request->getUser();

    $repository_phid = $request->getValue('repositoryPHID');
    $repository = id(new PhabricatorRepositoryQuery())
      ->setViewer($viewer)
      ->withPHIDs(array($repository_phid))
      ->executeOne();

    if (!$repository) {
      throw new Exception(
        pht('No repository exists with PHID "%s".', $repository_phid));
    }

    $source = $request->getValue('source', self::SOURCE_BRANCH);

    switch ($source) {
      case self::SOURCE_BRANCH:
        return $this->loadBranchLintMessages($request, $repository);
      case self::SOURCE_COMMIT:
        return $this->loadCommitLintMessages($request, $repository);
    }

    throw new Exception(
      pht(
        'Unknown source "%s". Valid sources are: %s.',
        $source,
        implode(', ', array(self::SOURCE_BRANCH, self::SOURCE_COMMIT))));
  }

  private function loadBranchLintMessages(
    ConduitAPIRequest $request,
    PhabricatorRepository $repository) {

    $branch_name = $request->getValue('branch');
    if ($branch_name == '') {
      $repository = id(new PhabricatorRepositoryQuery())
        ->setViewer($request->getUser())
        ->withIDs(array($repository->getID()))
        ->executeOne();
      $branch_name = $repository->getDefaultArcanistBranch();
    }

    $branch = id(new PhabricatorRepositoryBranch())->loadOneWhere(
      'repositoryID = %d AND name = %s',
      $repository->getID(),
      $branch_name);
    if (!$branch || !$branch->getLintCommit()) {
      return array();
    }

    $lint_messages = queryfx_all(
      $branch->establishConnection('r'),
      'SELECT path, line, code, severity, name, description
        FROM %T WHERE branchID = %d AND path IN (%Ls)',
      PhabricatorRepository::TABLE_LINTMESSAGE,
      $branch->getID(),
      $request->getValue('files'));

    // TODO: Compare commit identifiers of individual files like in
    // DiffusionBrowseFileController::loadLintMessages().

    return $this->normalizeLintMessages($lint_messages);
  }

  private function loadCommitLintMessages(
    ConduitAPIRequest $request,
    PhabricatorRepository $repository) {

    $identifier = $request->getValue('commit');
    if ($identifier === null || $identifier === '') {
      throw new Exception(
        pht(
          'Parameter "commit" is required for source "%s".',
          self::SOURCE_COMMIT));
    }

    $commit = id(new DiffusionCommitQuery())
      ->setViewer($request->getUser())
      ->withRepository($repository)
      ->withIdentifiers(array($identifier))
      ->executeOne();
    if (!$commit) {
      throw new Exception(
        pht(
          'No commit "%s" exists in repository "%s".',
          $identifier,
          $repository->getDisplayName()));
    }

    $tools = array(
      'checkstyle:warnings' => 'checkstyle',
      'pmd:warnings' => 'pmd',
    );

    $properties = id(new PhabricatorRepositoryCommitProperty())->loadAllWhere(
      'commitID = %d AND name IN (%Ls)',
      $commit->getID(),
      array_keys($tools));

    $files = array_fuse($request->getValue('files'));

    $lint_messages = array();
    foreach ($properties as $property) {
      $tool = $tools[$property->getName()];

      foreach ($property->getData() as $file => $warnings) {
        $path = '/'.$file;
        if (!isset($files[$path])) {
          continue;
        }

        foreach ($warnings as $warning) {
          $severity = $this->mapSeverity(idx($warning, 'priority'));
          $rule = idx($warning, 'rule');

          $lint_messages[] = array(
            'path' => $path,
            'line' => idx($warning, 'line'),
            'code' => $this->buildCode($tool, $severity, $rule),
            'severity' => $severity,
            'name' => $rule,
            'description' => idx($warning, 'message'),
          );
        }
      }
    }

    return $this->normalizeLintMessages($lint_messages);
  }

  private function normalizeLintMessages(array $lint_messages) {
    $result = array();

    foreach ($lint_messages as $lint_message) {
      $result[] = array(
        'path' => (string)$lint_message['path'],
        'line' => (int)$lint_message['line'],
        'code' => nonempty((string)$lint_message['code'], null),
        'severity' => (string)$lint_message['severity'],
        'name' => nonempty((string)$lint_message['name'], null),
        'description' => (string)$lint_message['description'],
      );
    }

    return $result;
  }

  private function mapSeverity($priority) {
    switch (strtoupper($priority)) {
      case 'ERROR':
      case 'HIGH':
        return ArcanistLintSeverity::SEVERITY_ERROR;
      case 'LOW':
        return ArcanistLintSeverity::SEVERITY_ADVICE;
      default:
        return ArcanistLintSeverity::SEVERITY_WARNING;
    }
  }

  // Same "PHPCS.<E|W>.<source>" format as ArcanistPhpcsLinter.
  private function buildCode($tool, $severity, $rule) {
    if (!strlen($rule)) {
      return null;
    }

    if ($tool == 'pmd') {
      return 'PHPMD.'.$rule;
    }

    if ($severity == ArcanistLintSeverity::SEVERITY_ERROR) {
      return 'PHPCS.E.'.$rule;
    }

    return 'PHPCS.W.'.$rule;
  }

}
