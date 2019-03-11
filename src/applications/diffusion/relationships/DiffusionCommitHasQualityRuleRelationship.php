<?php

final class DiffusionCommitHasQualityRuleRelationship
  extends DiffusionCommitRelationship {

  const RELATIONSHIPKEY = 'commit.has-quality-rule';

  public function getEdgeConstant() {
    return DiffusionCommitHasQualityRuleEdgeType::EDGECONST;
  }

  protected function getActionName() {
    return pht('Edit Quality Rules');
  }

  protected function getActionIcon() {
    return 'fa-ambulance';
  }

  public function canRelateObjects($src, $dst) {
    return ($dst instanceof PhabricatorQualityRule);
  }

  public function getDialogTitleText() {
    return pht('Edit Related Quality Rules');
  }

  public function getDialogHeaderText() {
    return pht('Current Quality Rules');
  }

  public function getDialogButtonText() {
    return pht('Save Related Quality Rules');
  }

  protected function newRelationshipSource() {
    return new PhabricatorQualityRuleRelationshipSource();
  }

}
