<?php

final class PhabricatorQualityRemarkupRule
  extends PhabricatorObjectRemarkupRule {

  protected function getObjectNamePrefix() {
    return 'QR';
  }

  protected function loadObjects(array $ids) {
    $viewer = $this->getEngine()->getConfig('viewer');

    return id(new PhabricatorQualityRuleQuery())
      ->setViewer($viewer)
      ->withIDs($ids)
      ->execute();
  }

}
