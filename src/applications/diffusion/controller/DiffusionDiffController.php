<?php

final class DiffusionDiffController extends DiffusionController {

  public function shouldAllowPublic() {
    return true;
  }

  protected function getDiffusionBlobFromRequest(AphrontRequest $request) {
    return $request->getStr('ref');
  }

  public function handleRequest(AphrontRequest $request) {
    $response = $this->loadDiffusionContext();
    if ($response) {
      return $response;
    }

    $viewer = $this->getViewer();
    $drequest = $this->getDiffusionRequest();

    if (!$request->isAjax()) {

      // This request came out of the dropdown menu, either "View Standalone"
      // or "View Raw File".

      $view = $request->getStr('view');
      if ($view == 'r') {
        $uri = $drequest->generateURI(
          array(
            'action' => 'browse',
            'params' => array(
              'view' => 'raw',
            ),
          ));
      } else {
        $uri = $drequest->generateURI(
          array(
            'action'  => 'change',
          ));
      }

      return id(new AphrontRedirectResponse())->setURI($uri);
    }

    $data = $this->callConduitWithDiffusionRequest(
      'diffusion.diffquery',
      array(
        'commit' => $drequest->getCommit(),
        'path' => $drequest->getPath(),
      ));
    $drequest->updateSymbolicCommit($data['effectiveCommit']);
    $raw_changes = ArcanistDiffChange::newFromConduit($data['changes']);
    $diff = DifferentialDiff::newEphemeralFromRawChanges(
      $raw_changes);
    $changesets = $diff->getChangesets();
    $changeset = reset($changesets);

    if (!$changeset) {
      return new Aphront404Response();
    }

    $parser = new DifferentialChangesetParser();
    $parser->setUser($viewer);
    $parser->setChangeset($changeset);
    $parser->setRenderingReference($drequest->generateURI(
      array(
        'action' => 'rendering-ref',
      )));

    $parser->readParametersFromRequest($request);

    $coverage = $drequest->loadCoverage();
    if ($coverage) {
      $parser->setCoverage($coverage);
    }

    $commit = $drequest->loadCommit();

    $pquery = new DiffusionPathIDQuery(array($changeset->getFilename()));
    $ids = $pquery->loadPathIDs();
    $path_id = $ids[$changeset->getFilename()];

    $parser->setLeftSideCommentMapping($path_id, false);
    $parser->setRightSideCommentMapping($path_id, true);
    $parser->setCanMarkDone(
      ($commit->getAuthorPHID()) &&
      ($viewer->getPHID() == $commit->getAuthorPHID()));
    $parser->setObjectOwnerPHID($commit->getAuthorPHID());

    $inlines =
      array_merge(PhabricatorAuditInlineComment::loadDraftAndPublishedComments(
      $viewer,
      $commit->getPHID(),
      $path_id),
    $this->getLintMessages(
      $commit,
      $changeset->getFilename(),
      $path_id));

    if ($inlines) {
      $phids = array();
      foreach ($inlines as $inline) {
        $parser->parseInlineComment($inline);
        if ($inline->getAuthorPHID()) {
          $phids[$inline->getAuthorPHID()] = true;
        }
      }
      $phids = array_keys($phids);

      $handles = $this->loadViewerHandles($phids);
      $parser->setHandles($handles);
    }

    $engine = new PhabricatorMarkupEngine();
    $engine->setViewer($viewer);

    foreach ($inlines as $inline) {
      $engine->addObject(
        $inline,
        PhabricatorInlineCommentInterface::MARKUP_FIELD_BODY);
    }

    $engine->process();

    $parser->setMarkupEngine($engine);

    $spec = $request->getStr('range');
    list($range_s, $range_e, $mask) =
      DifferentialChangesetParser::parseRangeSpecification($spec);

    $parser->setRange($range_s, $range_e);
    $parser->setMask($mask);

    return id(new PhabricatorChangesetResponse())
      ->setRenderedChangeset($parser->renderChangeset())
      ->setUndoTemplates($parser->getRenderer()->renderUndoTemplates());
  }

  private function getLintMessages(
    PhabricatorRepositoryCommit $commit, $filename, $path_id) {
    $properties = id(new PhabricatorRepositoryCommitProperty())->loadAllWhere(
      'commitID = %d AND name IN (%Ls)',
      $commit->getID(),
      array('checkstyle:warnings', 'pmd:warnings'));

    if (!$properties) {
      return array();
    }

    $inlines = array();

    foreach ($properties as $property) {
      $warnings = $property->getData();

      if (!idx($warnings, $filename)) {
        continue;
      }

      $synthetic_author = $this->getSyntheticAuthor($property);

      foreach ($warnings[$filename] as $warning) {
        $priority = ucfirst(strtolower($warning['priority'])).' Priority';

        $inline = new PhabricatorAuditInlineComment();
        $inline->setChangesetID($path_id);
        $inline->setIsNewFile(1);
        $inline->setSyntheticAuthor($synthetic_author.' ('.$priority.')');
        $inline->setLineNumber($warning['line']);
        $inline->setLineLength(0);

        $inline->setContent('%%%'.$warning['message'].'%%%');
        $inlines[] = $inline;
      }
    }

    return $inlines;
  }

  private function getSyntheticAuthor(
    PhabricatorRepositoryCommitProperty $property) {
    if ($property->getName() == 'checkstyle:warnings') {
      return 'Lint: Checkstyle Warning';
    } else if ($property->getName() == 'pmd:warnings') {
      return 'Lint: PMD Warning';
    }

    return 'Lint: Unknown Warning Type';
  }

}
