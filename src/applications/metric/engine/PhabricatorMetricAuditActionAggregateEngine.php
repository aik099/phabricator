<?php

abstract class PhabricatorMetricAuditActionAggregateEngine extends PhabricatorMetricAuditActionAwareEngine {

  protected $actionNameMap;

  protected function fuseCommitsWithAudits(array $commits, array $commit_audits) {
    $ret = array();

    $audit_actions = array(
      PhabricatorAuditActionConstants::CONCERN,
      PhabricatorAuditActionConstants::ACCEPT,
    );

    foreach ($commit_audits as $commit_phid => $audits) {
      $commit_data = $commits[$commit_phid];

      if (count($audits) == 1) {
        // One audit action = immediate approve/resign.
        $first_audit = reset($audits);
        $commit_data[$this->auditStatusColumn] = $first_audit[$this->auditStatusColumn];
      } else {
        // Multiple audit actions = immediate concern and then approve.
        foreach ($audit_actions as $audit_action) {
          foreach ($audits as $audit_data) {
            if ($audit_data[$this->auditStatusColumn] == $audit_action) {
              $commit_data[$this->auditStatusColumn] = $audit_action;
              break 2;
            }
          }
        }
      }

      $ret[] = $commit_data;
    }

    return $ret;
  }

  protected function getGroupedData(array $raw_metrics) {
    $grouped_data = array();
    $date_group_format = $this->getDateGroupFormat();

    $series_names = array();
    $breakdown_keys = array();
    $breakdown_column = $this->getBreakdownColumn();

    foreach ($raw_metrics as $raw_metric) {
      $group_key = date($date_group_format, $raw_metric['commitEpoch']);

      if (!isset($grouped_data[$group_key])) {
        $grouped_data[$group_key] = array();
      }

      $audit_action = $raw_metric[$this->groupColumn];
      $series_names[$audit_action] = true;

      if ($breakdown_column) {
        $breakdown_key = $raw_metric[$breakdown_column];
      } else {
        $breakdown_key = self::NO_BREAKDOWN_KEY;
      }

      $breakdown_keys[$breakdown_key] = true;

      if (!isset($grouped_data[$group_key][$breakdown_key])) {
        $grouped_data[$group_key][$breakdown_key] = array();
      }

      if (!isset($grouped_data[$group_key][$breakdown_key][$audit_action])) {
        $grouped_data[$group_key][$breakdown_key][$audit_action] = 0;
      }

      $grouped_data[$group_key][$breakdown_key][$audit_action]++;
    }

    // Sort because data is sorted by audit date, but displayed by commit date.
    ksort($grouped_data);

    $this->seriesNames = array_keys($series_names);
    $this->breakdownKeys = array_keys($breakdown_keys);

    return $this->calculatePercentage($grouped_data);
  }

  protected function getChartSeriesName($series_name, $breakdown_key) {
    $series_name = $this->getActionName($series_name);

    return parent::getChartSeriesName($series_name, $breakdown_key);
  }

  protected function getActionNameMap() {
      $map = array(
        PhabricatorAuditActionConstants::COMMENT      => pht('Comment'),
        PhabricatorAuditActionConstants::CONCERN      => pht("Raise Concern \xE2\x9C\x98"),
        PhabricatorAuditActionConstants::ACCEPT       => pht("Accept Commit \xE2\x9C\x94"),
        PhabricatorAuditActionConstants::RESIGN       => pht('Resign from Audit'),
        PhabricatorAuditActionConstants::CLOSE        => pht('Close Audit'),
        PhabricatorAuditActionConstants::ADD_CCS      => pht('Add Subscribers'),
        PhabricatorAuditActionConstants::ADD_AUDITORS => pht('Add Auditors'),
      );

      return $map;
    }

    protected function getActionName($constant) {
      if (!isset($this->actionNameMap)) {
        $this->actionNameMap = $this->getActionNameMap();
      }

      return idx($this->actionNameMap, $constant, pht('Unknown'));
    }
}
