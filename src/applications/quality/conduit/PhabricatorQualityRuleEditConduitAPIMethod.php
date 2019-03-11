<?php

final class PhabricatorQualityRuleEditConduitAPIMethod
  extends PhabricatorEditEngineAPIMethod {

  public function getAPIMethodName() {
    return 'quality.rule.edit';
  }

  public function newEditEngine() {
    return new PhabricatorQualityRuleEditEngine();
  }

  public function getMethodSummary() {
    return pht(
      'Apply transactions to create a new Quality Rules or edit an existing one.');
  }
}
