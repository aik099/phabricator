<?php

final class DifferentialConfiguredCustomField
  extends DifferentialCustomField
  implements PhabricatorStandardCustomFieldInterface {

  public function getStandardCustomFieldNamespace() {
    return 'differential';
  }

  public function createFields($object) {
    $config = PhabricatorEnv::getEnvConfig(
      'differential.custom-field-definitions');

    return PhabricatorStandardCustomField::buildStandardFields(
      $this,
      $config);
  }

  public function newStorageObject() {
    return new DifferentialCustomFieldStorage();
  }

  protected function newStringIndexStorage() {
    return new DifferentialCustomFieldStringIndex();
  }

  protected function newNumericIndexStorage() {
    return new DifferentialCustomFieldNumericIndex();
  }

  public function shouldAppearInDiffPropertyView() {
    return false;
  }

  public function renderDiffPropertyViewLabel(DifferentialDiff $diff) {
    return $this->getFieldName();
  }

  public function renderDiffPropertyViewValue(DifferentialDiff $diff) {
    throw new PhabricatorCustomFieldImplementationIncompleteException($this);
  }

  public function getWarningsForDetailView() {
    return array();
  }

  public function getProTips() {
    return array();
  }

}
