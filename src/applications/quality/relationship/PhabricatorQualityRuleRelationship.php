<?php

abstract class PhabricatorQualityRuleRelationship
  extends PhabricatorObjectRelationship {

  public function isEnabledForObject($object) {
    $viewer = $this->getViewer();

    $has_app = PhabricatorApplication::isClassInstalledForViewer(
      'PhabricatorQualityApplication',
      $viewer);
    if (!$has_app) {
      return false;
    }

    return ($object instanceof PhabricatorQualityRule);
  }

}
