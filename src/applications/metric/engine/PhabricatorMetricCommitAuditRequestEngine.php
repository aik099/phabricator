<?php

final class PhabricatorMetricCommitAuditRequestEngine extends PhabricatorMetricAuditActionAggregateEngine {

  public function __construct() {
    $this->auditStatusColumn = 'auditRequestStatus';
    $this->groupColumn = 'auditRequestStatus';
  }

  public function getReportName() {
    return 'Workload (Audit Requests)';
  }

  protected function getChartType() {
    return 'column';
  }

  public function getChartParams(array $report_params) {
    $chart_data = parent::getChartParams($report_params);

    if (!$chart_data) {
      return $chart_data;
    }

    $chart_data['stacking'] = 'normal';
    $chart_data['y_axis_title'] = 'Commits';
    $chart_data['plot_options'] = array($this->getChartType());

    return $chart_data;
  }

  protected function getAuditTransactions(array $commits) {
    $filtered_commits = array();

    $ignore_statuses = array(
      PhabricatorAuditStatusConstants::AUDIT_NOT_REQUIRED,
      PhabricatorAuditStatusConstants::RESIGNED,
      PhabricatorAuditStatusConstants::NONE,
    );

    foreach ($commits as $commit_data) {
      $audit_request_status = $commit_data['auditRequestStatus'];

      if (in_array($audit_request_status, $ignore_statuses)) {
       continue;
      }

      if ($audit_request_status == PhabricatorAuditStatusConstants::AUDIT_REQUESTED) {
        $commit_data['auditRequestStatus'] = PhabricatorAuditStatusConstants::AUDIT_REQUIRED;
      }

      $filtered_commits[] = $commit_data;
    }

    return $filtered_commits;
  }

  protected function calculatePercentage(array $grouped_data) {
    return $grouped_data;
  }

  protected function getChartSeriesName($series_name, $breakdown_key) {
    $series_name = PhabricatorAuditStatusConstants::getStatusName($series_name);

    return PhabricatorMetricAuditActionAwareEngine::getChartSeriesName($series_name, $breakdown_key);
  }
}
