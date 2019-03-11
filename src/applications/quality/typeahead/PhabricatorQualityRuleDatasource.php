<?php

final class PhabricatorQualityRuleDatasource
  extends PhabricatorTypeaheadDatasource {

  public function getBrowseTitle() {
    return pht('Browse Quality Rules');
  }

  public function getPlaceholderText() {
    return pht('Select a quality rule...');
  }

  public function getDatasourceApplicationClass() {
    return 'PhabricatorQualityApplication';
  }

  public function loadResults() {
    $query = id(new PhabricatorQualityRuleQuery());
    $rules = $this->executeQuery($query);
    $results = array();
    foreach ($rules as $rule) {
      $result = id(new PhabricatorTypeaheadResult())
        ->setName($rule->getMonogram().': '.$rule->getName())
        ->setPHID($rule->getPHID())
        ->addAttribute('Quality Rule');

      if ($rule->isArchived()) {
        $result->setClosed(pht('Archived'));
      }

      $results[] = $result;
    }

    return $this->filterResultsAgainstTokens($results);
  }

}
