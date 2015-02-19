<?php

abstract class PhabricatorMetricAuditActionAwareEngine extends PhabricatorMetricEngine {

  protected $seriesNames = array();

  protected $breakdownKeys = array();

  protected $auditStatusColumn = 'auditAction';

  protected $groupColumn = 'auditAction';

  public function getChartParams(array $report_params) {
    $this->reportParams = $report_params;

    $raw_metrics = $this->getRawMetrics();

    if (!$raw_metrics) {
      return array();
    }

    $grouped_data = $this->getGroupedData($raw_metrics);
    $series = $this->getSeries($grouped_data);

    $chart_data = array(
      'type' => $this->getChartType(),
      'data_type' => 'normal',
      'title' => $this->getReportName(),
      'categories' => $this->getChartCategories(array_keys($grouped_data)),
      'x_axis_title' => 'Time',
      'y_axis_title' => '',
      'y_axis_suffix' => '',
      'y_axis_plot_lines' => array(),
      'stacking' => '',
      'plot_options' => array(),
      'series' => array_values($series),
    );

    return $chart_data;
  }

  abstract protected function getGroupedData(array $raw_metrics);

  protected function getSeries(array $grouped_data) {
    $series = array();
    $resolved_breakdown_keys = $this->resolveBreakdownKeys($this->breakdownKeys);
    $unknown_label = pht('Unknown');

    // $group_name - time frame (e.g. 1 day)
    // $breakdown_groups - users/repositories
    foreach ($grouped_data as $group_name => $breakdown_groups) {
      // $breakdown_key - user/repository PHID
      foreach ($this->breakdownKeys as $breakdown_key) {
        foreach ($this->seriesNames as $series_name) {
          $series_key = $series_name.'|'.$breakdown_key;

          if (!isset($series[$series_key])) {
            if ($breakdown_key != self::NO_BREAKDOWN_KEY) {
              $resolved_breakdown_key = idx($resolved_breakdown_keys, $breakdown_key, $unknown_label);
            } else {
              $resolved_breakdown_key = $breakdown_key;
            }

            $series[$series_key] = array(
              'name' => $this->getChartSeriesName($series_name, $resolved_breakdown_key),
              'data' => array(),
              'stack' => $breakdown_key,
            );
          }

          if (isset($breakdown_groups[$breakdown_key][$series_name])) {
            $series[$series_key]['data'][] = $breakdown_groups[$breakdown_key][$series_name];
          } else {
            $series[$series_key]['data'][] = 0;
          }
        }
      }
    }

    uksort($series, array($this, 'sortSeries'));

    return $series;
  }


  protected function getBreakdownColumn() {
    $map = array(
      MetricReportParam::BREAKDOWN_NONE => '',
      MetricReportParam::BREAKDOWN_USERS => 'commitAuthorPHID',
      MetricReportParam::BREAKDOWN_REPOSITORIES => 'repositoryPHID',
    );

    return $map[$this->getReportParam('breakdown')];
  }

  protected function resolveBreakdownKeys(array $breakdown_keys) {
    switch ($this->getReportParam('breakdown')) {
      case MetricReportParam::BREAKDOWN_USERS:
        $user = new PhabricatorUser();
        $user_conn_r = $user->establishConnection('r');

        $sql = 'SELECT userName, phid
                FROM %T
                WHERE phid IN (%Ls)';
        $users = queryfx_all($user_conn_r, $sql, $user->getTableName(), $breakdown_keys);

        return ipull($users, 'userName', 'phid');
    		break;

      case MetricReportParam::BREAKDOWN_REPOSITORIES:
        $repository = new PhabricatorRepository();
        $repository_conn_r = $repository->establishConnection('r');

        $sql = 'SELECT callsign, phid
                FROM %T
                WHERE phid IN (%Ls)';
        $repositories = queryfx_all($repository_conn_r, $sql, $repository->getTableName(), $breakdown_keys);

        return ipull($repositories, 'callsign', 'phid');
        break;
    }

    return array_combine($breakdown_keys, $breakdown_keys);
  }

  public function sortSeries($series_a, $series_b) {
    list ($a_audit_action, $a_breakdown_key) = explode('|', $series_a, 2);
    list ($b_audit_action, $b_breakdown_key) = explode('|', $series_b, 2);

    if ($a_breakdown_key == $b_breakdown_key) {
      return strcmp($a_audit_action, $b_audit_action);
    }

    return strcmp($a_breakdown_key, $b_breakdown_key);
  }

  protected function getRawMetrics() {
    $commits = $this->getCommits();
    $audit_transactions = $this->getAuditTransactions($commits);

    return $this->applyFilters($audit_transactions);
  }

  protected function getCommits() {
    $audit_request = new PhabricatorRepositoryAuditRequest();
    $audit_request_conn_r = $audit_request->establishConnection('r');

    $repository = new PhabricatorRepository();
    $repository_conn_r = $repository->establishConnection('r');

    $period_start = $this->getReportParam('period_start');
    $period_end = $this->getReportParam('period_end');

    $joins = array(
      qsprintf(
        $audit_request_conn_r,
        'JOIN %T ar ON ar.commitPHID = c.phid',
        $audit_request->getTableName()
      ),
      qsprintf(
        $repository_conn_r,
        'JOIN %T r ON r.id = c.repositoryID',
        $repository->getTableName()
      ),
    );

    $commit = new PhabricatorRepositoryCommit();
    $commit_conn_r = $commit->establishConnection('r');

    $where_clause = array(
      qsprintf(
        $commit_conn_r,
        'c.epoch BETWEEN %d AND %d',
        $period_start,
        $period_end),
    );

    $sql = 'SELECT c.phid AS commitPHID,
              r.phid AS repositoryPHID,
              c.epoch AS commitEpoch,
              c.authorPHID AS commitAuthorPHID,
              ar.auditStatus AS auditRequestStatus
            FROM %T c
            %LJ
            WHERE ('.implode(') AND (', $where_clause).')';
    $result = queryfx_all($commit_conn_r, $sql, $commit->getTableName(), $joins);

    return ipull($result, null, 'commitPHID');
  }

  protected function getAuditTransactions(array $commits) {
    if (!$commits) {
      return array();
    }

    $audit_transaction = new PhabricatorAuditTransaction();
    $audit_transaction_conn_r = $audit_transaction->establishConnection('r');

    $commit_audits = array();
    $commit_phids = array_keys($commits);

    $transactionValueMapping = array(
      DiffusionCommitConcernTransaction::TRANSACTIONTYPE => PhabricatorAuditActionConstants::CONCERN,
      DiffusionCommitAcceptTransaction::TRANSACTIONTYPE => PhabricatorAuditActionConstants::ACCEPT,
    );

    $transactionTypes = array(
      // Old style.
      PhabricatorAuditActionConstants::ACTION,

      // New style.
      DiffusionCommitConcernTransaction::TRANSACTIONTYPE,
      DiffusionCommitAcceptTransaction::TRANSACTIONTYPE,
    );

    foreach (array_chunk($commit_phids, 100) as $commit_phids_chunk) {
      $where_clause = array(
        qsprintf($audit_transaction_conn_r, 'audit.transactionType IN (%Ls)', $transactionTypes),
        qsprintf(
          $audit_transaction_conn_r,
          'audit.objectPHID IN (%Ls)',
          $commit_phids_chunk),
      );

      $sql = 'SELECT  objectPHID AS commitPHID,
                      dateCreated AS auditEpoch,
                      newValue AS auditAction,
                      audit.transactionType
              FROM %T audit
              WHERE ('.implode(') AND (', $where_clause).')';

      $audit_transactions = queryfx_all(
        $audit_transaction_conn_r,
        $sql,
        $audit_transaction->getTableName());

      foreach ($audit_transactions as $audit_transaction_data) {
        $audit_action = json_decode($audit_transaction_data[$this->auditStatusColumn]);

        // Map action from old to new transaction type.
        $transactionType = $audit_transaction_data['transactionType'];

        if (isset($transactionValueMapping[$transactionType])) {
          if ($audit_action === false) {
            continue;
          }

          $audit_action = $transactionValueMapping[$transactionType];
        }

        if ($audit_action == PhabricatorAuditActionConstants::RESIGN) {
          continue;
        }

        $commit_phid = $audit_transaction_data['commitPHID'];
        $audit_transaction_data[$this->auditStatusColumn] = $audit_action;

        if (!isset($commit_audits[$commit_phid])) {
          $commit_audits[$commit_phid] = array();
        }

        unset($audit_transaction_data['transactionType']);
        $audit_transaction_data['qualityRulePHID'] = null;
        $commit_audits[$commit_phid][] = $audit_transaction_data;
      }
    }

    return $this->fuseCommitsWithAudits($commits, $commit_audits);
  }

  protected function fuseCommitsWithAudits(array $commits, array $commit_audits) {
    $ret = array();

    foreach ($commit_audits as $commit_phid => $audits) {
      $commit_data = $commits[$commit_phid];

      $ret[] = $commit_data;
    }

    return $ret;
  }

  protected function applyFilters(array $audit_transactions) {
    if (!$audit_transactions) {
      return array();
    }

    $ret = array();

    $users = $this->getReportParam('users');
    $exclude_users = array_key_exists(
      'exclude_selected_users',
      $this->getReportParam('users_checkboxes')
    );

    $repositories = $this->getReportParam('repositories');
    $exclude_repositories = array_key_exists(
      'exclude_selected_repositories',
      $this->getReportParam('repositories_checkboxes')
    );

    foreach ($audit_transactions as $index => $audit_transaction_data) {
      if ($users) {
        $user_match = in_array(
          $audit_transaction_data['commitAuthorPHID'],
          $users
        );
      } else {
        $user_match = true;
      }

      if ($exclude_users) {
        $user_match = !$user_match;
      }

      if ($repositories) {
        $repository_match = in_array(
          $audit_transaction_data['repositoryPHID'],
          $repositories
        );
      } else {
        $repository_match = true;
      }

      if ($exclude_repositories) {
        $repository_match = !$repository_match;
      }

      if (!$user_match || !$repository_match) {
        continue;
      }

      $ret[] = $audit_transaction_data;
    }

    return $ret;
  }

  protected function getChartSeriesName($series_name, $breakdown_key) {
    if ($breakdown_key != self::NO_BREAKDOWN_KEY) {
      return $series_name.' ('.$breakdown_key.')';
    }

    return $series_name;
  }
}
