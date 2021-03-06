<?php

/**
 * Resolves references (like short commit names, branch names, tag names, etc.)
 * into canonical, stable commit identifiers. This query works for all
 * repository types.
 */
final class DiffusionLowLevelResolveRefsQuery
  extends DiffusionLowLevelQuery {

  private $refs;
  private $types;

  public function withRefs(array $refs) {
    $this->refs = $refs;
    return $this;
  }

  public function withTypes(array $types) {
    $this->types = $types;
    return $this;
  }

  protected function executeQuery() {
    if (!$this->refs) {
      return array();
    }

    $repository = $this->getRepository();
    if (!$repository->hasLocalWorkingCopy()) {
      return array();
    }

    switch ($this->getRepository()->getVersionControlSystem()) {
      case PhabricatorRepositoryType::REPOSITORY_TYPE_GIT:
        $result = $this->resolveGitRefs();
        break;
      case PhabricatorRepositoryType::REPOSITORY_TYPE_MERCURIAL:
        $result = $this->resolveMercurialRefs();
        break;
      case PhabricatorRepositoryType::REPOSITORY_TYPE_SVN:
        $result = $this->resolveSubversionRefs();
        break;
      default:
        throw new Exception(pht('Unsupported repository type!'));
    }

    if ($this->types !== null) {
      $result = $this->filterRefsByType($result, $this->types);
    }

    return $result;
  }

  private function resolveGitRefs() {
    $repository = $this->getRepository();

    $unresolved = array_fuse($this->refs);
    $results = array();

    // First, resolve branches and tags.
    $ref_map = id(new DiffusionLowLevelGitRefQuery())
      ->setRepository($repository)
      ->withRefTypes(
        array(
          PhabricatorRepositoryRefCursor::TYPE_BRANCH,
          PhabricatorRepositoryRefCursor::TYPE_TAG,
        ))
      ->execute();
    $ref_map = mgroup($ref_map, 'getShortName');

    $tag_prefix = 'refs/tags/';
    foreach ($unresolved as $ref) {
      if (empty($ref_map[$ref])) {
        continue;
      }

      foreach ($ref_map[$ref] as $result) {
        $fields = $result->getRawFields();
        $objectname = idx($fields, 'refname');
        if (!strncmp($objectname, $tag_prefix, strlen($tag_prefix))) {
          $type = 'tag';
        } else {
          $type = 'branch';
        }

        $info = array(
          'type' => $type,
          'identifier' => $result->getCommitIdentifier(),
        );

        if ($type == 'tag') {
          $alternate = idx($fields, 'objectname');
          if ($alternate) {
            $info['alternate'] = $alternate;
          }
        }

        $results[$ref][] = $info;
      }

      unset($unresolved[$ref]);
    }

    // If we resolved everything, we're done.
    if (!$unresolved) {
      return $results;
    }

    // Try to resolve anything else. This stuff either doesn't exist or is
    // some ref like "HEAD^^^".
    $future = $repository->getLocalCommandFuture('cat-file --batch-check');
    $future->write(implode("\n", $unresolved));
    list($stdout) = $future->resolvex();

    $lines = explode("\n", rtrim($stdout, "\n"));
    if (count($lines) !== count($unresolved)) {
      throw new Exception(
        pht(
          'Unexpected line count from `%s`!',
          'git cat-file'));
    }

    $hits = array();
    $tags = array();

    $lines = array_combine($unresolved, $lines);
    foreach ($lines as $ref => $line) {
      $parts = explode(' ', $line);
      if (count($parts) < 2) {
        throw new Exception(
          pht(
            'Failed to parse `%s` output: %s',
            'git cat-file',
            $line));
      }
      list($identifier, $type) = $parts;

      if ($type == 'missing') {
        // This is either an ambiguous reference which resolves to several
        // objects, or an invalid reference. For now, always treat it as
        // invalid. It would be nice to resolve all possibilities for
        // ambiguous references at some point, although the strategy for doing
        // so isn't clear to me.
        continue;
      }

      switch ($type) {
        case 'commit':
          break;
        case 'tag':
          $tags[] = $identifier;
          break;
        default:
          throw new Exception(
            pht(
              'Unexpected object type from `%s`: %s',
              'git cat-file',
              $line));
      }

      $hits[] = array(
        'ref' => $ref,
        'type' => $type,
        'identifier' => $identifier,
      );
    }

    $tag_map = array();
    if ($tags) {
      // If some of the refs were tags, just load every tag in order to figure
      // out which commits they map to. This might be somewhat inefficient in
      // repositories with a huge number of tags.
      $tag_refs = id(new DiffusionLowLevelGitRefQuery())
        ->setRepository($repository)
        ->withRefTypes(
          array(
            PhabricatorRepositoryRefCursor::TYPE_TAG,
          ))
        ->executeQuery();
      foreach ($tag_refs as $tag_ref) {
        $tag_map[$tag_ref->getShortName()] = $tag_ref->getCommitIdentifier();
      }
    }

    $results = array();
    foreach ($hits as $hit) {
      $type = $hit['type'];
      $ref = $hit['ref'];

      $alternate = null;
      if ($type == 'tag') {
        $alternate = $identifier;
        $identifier = idx($tag_map, $ref);
        if (!$identifier) {
          throw new Exception(
            pht(
              "Failed to look up tag '%s'!",
              $ref));
        }
      }

      $result = array(
        'type' => $type,
        'identifier' => $identifier,
      );

      if ($alternate !== null) {
        $result['alternate'] = $alternate;
      }

      $results[$ref][] = $result;
    }

    return $results;
  }

  private function resolveMercurialRefs() {
    $repository = $this->getRepository();

    // First, pull all of the branch heads in the repository. Doing this in
    // bulk is much faster than querying each individual head if we're
    // checking even a small number of refs.
    $branches = id(new DiffusionLowLevelMercurialBranchesQuery())
      ->setRepository($repository)
      ->executeQuery();

    $branches = mgroup($branches, 'getShortName');

    $results = array();
    $unresolved = $this->refs;
    foreach ($unresolved as $key => $ref) {
      if (empty($branches[$ref])) {
        continue;
      }

      foreach ($branches[$ref] as $branch) {
        $fields = $branch->getRawFields();

        $results[$ref][] = array(
          'type' => 'branch',
          'identifier' => $branch->getCommitIdentifier(),
          'closed' => idx($fields, 'closed', false),
        );
      }

      unset($unresolved[$key]);
    }

    if (!$unresolved) {
      return $results;
    }

    // If some of the refs look like hashes, try to bulk resolve them. This
    // workflow happens via RefEngine and bulk resolution is dramatically
    // faster than individual resolution. See PHI158.

    $hashlike = array();
    foreach ($unresolved as $key => $ref) {
      if (preg_match('/^[a-f0-9]{40}\z/', $ref)) {
        $hashlike[$key] = $ref;
      }
    }

    if (count($hashlike) > 1) {
      $hashlike_map = array();

      $hashlike_groups = array_chunk($hashlike, 64, true);
      foreach ($hashlike_groups as $hashlike_group) {
        $hashlike_arg = array();
        foreach ($hashlike_group as $hashlike_ref) {
          $hashlike_arg[] = hgsprintf('%s', $hashlike_ref);
        }
        $hashlike_arg = '('.implode(' or ', $hashlike_arg).')';

        list($err, $refs) = $repository->execLocalCommand(
          'log --template=%s --rev %s',
          '{node}\n',
          $hashlike_arg);
        if ($err) {
          // NOTE: If any ref fails to resolve, Mercurial will exit with an
          // error. We just give up on the whole group and resolve it
          // individually below. In theory, we could split it into subgroups
          // but the pathway where this bulk resolution matters rarely tries
          // to resolve missing refs (see PHI158).
          continue;
        }

        $refs = phutil_split_lines($refs, false);

        foreach ($refs as $ref) {
          $hashlike_map[$ref] = true;
        }
      }

      foreach ($unresolved as $key => $ref) {
        if (!isset($hashlike_map[$ref])) {
          continue;
        }

        $results[$ref][] = array(
          'type' => 'commit',
          'identifier' => $ref,
        );

        unset($unresolved[$key]);
      }
    }

    if (!$unresolved) {
      return $results;
    }

    // If we still have unresolved refs (which might be things like "tip"),
    // try to resolve them individually.

    $futures = array();
    foreach ($unresolved as $ref) {
      $futures[$ref] = $repository->getLocalCommandFuture(
        'log --template=%s --rev %s',
        '{node}',
        hgsprintf('%s', $ref));
    }

    foreach (new FutureIterator($futures) as $ref => $future) {
      try {
        list($stdout) = $future->resolvex();
      } catch (CommandException $ex) {
        if (preg_match('/ambiguous identifier/', $ex->getStderr())) {
          // This indicates that the ref ambiguously matched several things.
          // Eventually, it would be nice to return all of them, but it is
          // unclear how to best do that. For now, treat it as a miss instead.
          continue;
        }
        if (preg_match('/unknown revision/', $ex->getStderr())) {
          // No matches for this ref.
          continue;
        }
        throw $ex;
      }

      // It doesn't look like we can figure out the type (commit/branch/rev)
      // from this output very easily. For now, just call everything a commit.
      $type = 'commit';

      $results[$ref][] = array(
        'type' => $type,
        'identifier' => trim($stdout),
      );
    }

    return $results;
  }

  private function resolveSubversionRefs() {
    $repository = $this->getRepository();

    $max_commit = id(new PhabricatorRepositoryCommit())
      ->loadOneWhere(
        'repositoryID = %d ORDER BY epoch DESC, id DESC LIMIT 1',
        $repository->getID());
    if (!$max_commit) {
      // This repository is empty or hasn't parsed yet, so none of the refs are
      // going to resolve.
      return array();
    }

    $max_commit_id = (int)$max_commit->getCommitIdentifier();

    if ($repository->supportsBranches()) {
      $branches = id(new DiffusionLowLevelSVNRefQuery())
        ->setRepository($repository)
        ->execute();

      $tags = id(new DiffusionLowLevelSVNRefQuery())
        ->setRepository($repository)
        ->withIsTag(true)
        ->execute();
    }

    $results = array();
    foreach ($this->refs as $ref) {
      if ($ref == 'HEAD') {
        // Resolve "HEAD" to mean "the most recent commit".
        $results[$ref][] = array(
          'type' => 'commit',
          'identifier' => $max_commit_id,
        );
        continue;
      }

      if (!preg_match('/^\d+$/', $ref)) {
        if (!$repository->supportsBranches()) {
          // This ref is non-numeric, so it doesn't resolve to anything.
          continue;
        }

        if (idx($branches, $ref)) {
          $results[$ref][] = array(
            'type' => 'branch',
            'identifier' => (int)$branches[$ref]->getCommitIdentifier(),);
        } else if (idx($tags, $ref)) {
          $results[$ref][] = array(
            'type' => 'tag',
            'identifier' => (int)$tags[$ref]->getCommitIdentifier(),);
        }
      } else {
        // Resolve other commits if we can deduce their existence.

        // TODO: When we import only part of a repository, we won't necessarily
        // have all of the smaller commits. Should we fail to resolve them here
        // for repositories with a subpath? It might let us simplify other things
        // elsewhere.
        if ((int)$ref <= $max_commit_id) {
          $results[$ref][] = array(
            'type' => 'commit',
            'identifier' => (int)$ref,
          );
        }
      }
    }

    return $results;
  }

}
