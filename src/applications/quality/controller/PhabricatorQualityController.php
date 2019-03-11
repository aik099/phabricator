<?php

abstract class PhabricatorQualityController extends PhabricatorController {

  protected function buildApplicationCrumbs() {
    $crumbs = parent::buildApplicationCrumbs();
    id(new PhabricatorQualityRuleEditEngine())
      ->setViewer($this->getViewer())
      ->addActionToCrumbs($crumbs);

    return $crumbs;
  }
}
