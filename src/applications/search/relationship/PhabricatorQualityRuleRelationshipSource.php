<?php

final class PhabricatorQualityRuleRelationshipSource
  extends PhabricatorObjectRelationshipSource {

  public function isEnabledForObject($object) {
    $viewer = $this->getViewer();

    return PhabricatorApplication::isClassInstalledForViewer(
      'PhabricatorQualityApplication',
      $viewer);
  }

  public function getResultPHIDTypes() {
    return array(
      PhabricatorQualityRulePHIDType::TYPECONST,
    );
  }

  protected function getDefaultFilter() {
    return 'open';
  }

  public function getFilters() {
    $filters = parent::getFilters();
    unset($filters['assigned']);
    return $filters;
  }

}
