<?php

final class PhabricatorQualityRuleQuery
  extends PhabricatorCursorPagedPolicyAwareQuery {

  private $ids;
  private $phids;
  private $names;
  private $authorPHIDs;
  private $statuses;

  public function newResultObject() {
    return new PhabricatorQualityRule();
  }

  public function withIDs(array $ids) {
    $this->ids = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->phids = $phids;
    return $this;
  }

  public function withNames(array $names) {
    $this->names = $names;
    return $this;
  }

  public function withNameNgrams($ngrams) {
    return $this->withNgramsConstraint(
      id(new PhabricatorQualityRuleNameNgrams()),
      $ngrams);
  }

  public function withAuthorPHIDs(array $author_phids) {
    $this->authorPHIDs = $author_phids;
    return $this;
  }

  public function withStatuses(array $statuses) {
    $this->statuses = $statuses;
    return $this;
  }

  protected function getPagingValueMap($cursor, array $keys) {
    $rule = $this->loadCursorObject($cursor);
    return array(
      'id' => $rule->getID(),
    );
  }

  protected function loadPage() {
    return $this->loadStandardPage($this->newResultObject());
  }

  protected function buildWhereClauseParts(AphrontDatabaseConnection $conn) {
    $where = parent::buildWhereClauseParts($conn);

    if ($this->ids !== null) {
      $where[] = qsprintf(
        $conn,
        'rule.id IN (%Ld)',
        $this->ids);
    }

    if ($this->phids !== null) {
      $where[] = qsprintf(
        $conn,
        'rule.phid IN (%Ls)',
        $this->phids);
    }

    if ($this->authorPHIDs !== null) {
      $where[] = qsprintf(
        $conn,
        'rule.authorPHID IN (%Ls)',
        $this->authorPHIDs);
    }

    if ($this->names !== null) {
      $where[] = qsprintf(
        $conn,
        'rule.name IN (%Ls)',
        $this->names);
    }

    if ($this->statuses !== null) {
      $where[] = qsprintf(
        $conn,
        'rule.status IN (%Ls)',
        $this->statuses);
    }

    return $where;
  }

  protected function getPrimaryTableAlias() {
    return 'rule';
  }

  public function getQueryApplicationClass() {
    return 'PhabricatorQualityApplication';
  }
}
