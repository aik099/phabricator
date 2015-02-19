<?php

final class PhabricatorMetricQualityRuleCommitsEngine extends PhabricatorMetricAuditActionAggregateEngine {

  public function __construct() {
    $this->groupColumn = 'qualityRulePHID';
  }

  public function getReportName() {
    return 'Commit Quality Rules Violations';
  }

  protected function getChartType() {
    return 'spline';
  }

  public function isEnabled() {
    return PhabricatorApplication::isClassInstalledForViewer(
      'PhabricatorQualityApplication',
      $this->getViewer());
  }

  protected function getRawMetrics() {
    $commits = $this->getCommits();
    $audit_transactions = $this->getAuditTransactions($commits);
    $commit_phids = ipull($audit_transactions, 'commitPHID');

    $edges = id(new PhabricatorEdgeQuery())
       ->withSourcePHIDs($commit_phids)
       ->withEdgeTypes(array(DiffusionCommitHasQualityRuleEdgeType::EDGECONST))
       ->execute();

    $audit_quality_rules = array();

    // Only leave audit transactions, that reference quality rules.
    foreach ($audit_transactions as $audit_transaction_data) {
      $commit_phid = $audit_transaction_data['commitPHID'];
      $quality_rules = array_keys($edges[$commit_phid][DiffusionCommitHasQualityRuleEdgeType::EDGECONST]);

      // When commit references multiple quality rules, then add separate record for each of them.
      foreach ($quality_rules as $quality_rule_phid) {
        $audit_transaction_data['qualityRulePHID'] = $quality_rule_phid;
        $audit_quality_rules[] = $audit_transaction_data;
      }
    }

    return $this->applyFilters($audit_quality_rules);
  }

  protected function getAuditTransactions(array $commits) {
    $ret = array();
    $audit_transactions = parent::getAuditTransactions($commits);

    // Only care about commits, that were in "Concern Raised" status.
    foreach ($audit_transactions as $audit_transaction_data) {
      if ($audit_transaction_data[$this->auditStatusColumn] == PhabricatorAuditActionConstants::ACCEPT) {
        continue;
      }

      $ret[] = $audit_transaction_data;
    }

    return $ret;
  }

  public function getChartParams(array $report_params) {
    $chart_data = parent::getChartParams($report_params);

    if (!$chart_data) {
      return $chart_data;
    }

    $chart_data['stacking'] = 'normal';
    $chart_data['y_axis_title'] = 'Commits';
    $chart_data['plot_options'] = array($this->getChartType());
    //$chart_data['data_type'] = 'percent';

    return $chart_data;
  }

  protected function calculatePercentage(array $grouped_data) {
    return $grouped_data;
  }

  protected function getActionNameMap() {
    $handles = id(new PhabricatorHandleQuery())
      ->setViewer($this->getViewer())
      ->withPHIDs($this->seriesNames)
      ->execute();

    return mpull($handles, 'getFullName', 'getPHID');
  }

  protected function applyFilters(array $audit_transactions) {
    $audit_transactions = parent::applyFilters($audit_transactions);

    if (!$audit_transactions) {
      return array();
    }

    $quality_rules = $this->getReportParam('quality_rules');

    if (!$quality_rules) {
      return $audit_transactions;
    }

    $exclude_quality_rules = array_key_exists(
      'exclude_selected_quality_rules',
      $this->getReportParam('quality_rules_checkboxes')
    );

    $ret = array();

    foreach ($audit_transactions as $index => $audit_transaction_data) {
      $quality_rule_match = in_array(
        $audit_transaction_data['qualityRulePHID'],
        $quality_rules
      );

      if ($exclude_quality_rules) {
        $quality_rule_match = !$quality_rule_match;
      }

      if (!$quality_rule_match) {
        continue;
      }

      $ret[] = $audit_transaction_data;
    }

    return $ret;
  }
}
