<?php

final class PhabricatorQualityRuleFerretEngine
  extends PhabricatorFerretEngine {

  public function getApplicationName() {
    return 'quality';
  }

  public function getScopeName() {
    return 'rule';
  }

  public function newSearchEngine() {
    return new PhabricatorQualityRuleSearchEngine();
  }

}
