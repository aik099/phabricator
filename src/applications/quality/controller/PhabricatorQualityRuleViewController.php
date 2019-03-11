<?php

final class PhabricatorQualityRuleViewController
  extends PhabricatorQualityController {

  public function shouldAllowPublic() {
    return true;
  }

  public function handleRequest(AphrontRequest $request) {
    $viewer = $request->getViewer();
    $id = $request->getURIData('id');

    $timeline = null;

    $rule = id(new PhabricatorQualityRuleQuery())
      ->setViewer($viewer)
      ->withIDs(array($id))
      ->executeOne();
    if (!$rule) {
      return new Aphront404Response();
    }

    $title = $rule->getMonogram();
    $page_title = $title.' '.$rule->getName();
    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb($title);
    $crumbs->setBorder(true);

    $timeline = $this->buildTransactionTimeline(
      $rule,
      new PhabricatorQualityRuleTransactionQuery());
    $timeline->setQuoteRef($rule->getMonogram());

    $header = $this->buildHeaderView($rule);
    $curtain = $this->buildCurtain($rule);
    $details = $this->buildPropertySectionView($rule);

    $add_comment_form = $this->buildCommentForm($rule, $timeline);

    $view = id(new PHUITwoColumnView())
      ->setHeader($header)
      ->setCurtain($curtain)
      ->setMainColumn(array(
        $details,
        $timeline,
        $add_comment_form,
      ));

    return $this->newPage()
      ->setTitle($page_title)
      ->setCrumbs($crumbs)
      ->setPageObjectPHIDs(array($rule->getPHID()))
      ->appendChild(
        array(
          $view,
      ));
  }

  private function buildCommentForm(PhabricatorQualityRule $rule, $timeline) {
    $viewer = $this->getViewer();
    $box = id(new PhabricatorQualityRuleEditEngine())
      ->setViewer($viewer)
      ->buildEditEngineCommentView($rule)
      ->setTransactionTimeline($timeline);

    return $box;
  }

  private function buildHeaderView(PhabricatorQualityRule $rule) {
    $viewer = $this->getViewer();

    if ($rule->isArchived()) {
      $header_icon = 'fa-ban';
      $header_name = pht('Archived');
      $header_color = 'dark';
    } else {
      $header_icon = 'fa-check';
      $header_name = pht('Active');
      $header_color = 'bluegrey';
    }

    $header = id(new PHUIHeaderView())
      ->setUser($viewer)
      ->setHeader($rule->getName())
      ->setStatus($header_icon, $header_color, $header_name)
      ->setPolicyObject($rule)
      ->setHeaderIcon('fa-ambulance');

    return $header;
  }

  private function buildCurtain(PhabricatorQualityRule $rule) {
    $viewer = $this->getViewer();
    $id = $rule->getID();

    $curtain = $this->newCurtainView($rule);

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $rule,
      PhabricatorPolicyCapability::CAN_EDIT);

    $edit_uri = $this->getApplicationURI("rule/edit/{$id}/");
    $archive_uri = $this->getApplicationURI("rule/archive/{$id}/");

    $curtain
      ->addAction(
        id(new PhabricatorActionView())
          ->setName(pht('Edit Quality Rule'))
          ->setIcon('fa-pencil')
          ->setHref($edit_uri)
          ->setDisabled(!$can_edit)
          ->setWorkflow(!$can_edit));

    if ($rule->isArchived()) {
      $curtain->addAction(
        id(new PhabricatorActionView())
          ->setName(pht('Activate Quality Rule'))
          ->setIcon('fa-check')
          ->setDisabled(!$can_edit)
          ->setWorkflow($can_edit)
          ->setHref($archive_uri));
    } else {
      $curtain->addAction(
        id(new PhabricatorActionView())
          ->setName(pht('Archive Quality Rule'))
          ->setIcon('fa-ban')
          ->setDisabled(!$can_edit)
          ->setWorkflow($can_edit)
          ->setHref($archive_uri));
    }

    $relationship_list = PhabricatorObjectRelationshipList::newForObject(
      $viewer,
      $rule);

    $relationship_submenu = $relationship_list->newActionMenu();
    if ($relationship_submenu) {
      $curtain->addAction($relationship_submenu);
    }

    return $curtain;
  }

  private function buildPropertySectionView(PhabricatorQualityRule $rule) {
    $viewer = $this->getViewer();

    $properties = id(new PHUIPropertyListView())
      ->setUser($viewer);

    $description = $rule->getDescription();
    if (strlen($description)) {
      $description = new PHUIRemarkupView($viewer, $description);
      $properties->addSectionHeader(pht('Description'));
      $properties->addTextContent($description);
    }

    return id(new PHUIObjectBoxView())
      ->setHeaderText(pht('Details'))
      ->setBackground(PHUIObjectBoxView::BLUE_PROPERTY)
      ->appendChild($properties);
  }

}
