<?php

final class PhabricatorQualityRuleSearchConduitAPIMethod
  extends PhabricatorSearchEngineAPIMethod {

  public function getAPIMethodName() {
    return 'quality.rule.search';
  }

  public function newSearchEngine() {
    return new PhabricatorQualityRuleSearchEngine();
  }

  public function getMethodSummary() {
    return pht('Read information about Quality Rules.');
  }

}
