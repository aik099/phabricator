<?php

final class PhabricatorMetricCommitFixingRateEngine extends PhabricatorMetricAuditActionAwareEngine {

  public function getReportName() {
    return 'Commit Issue Fixing Rate';
  }

  protected function getChartType() {
    return 'spline';
  }

  public function getChartParams(array $report_params) {
    $chart_data = parent::getChartParams($report_params);

    if (!$chart_data) {
      return $chart_data;
    }

    $chart_data['stacking'] = 'normal';
    $chart_data['y_axis_title'] = 'Average fixing duration (in days)';
    $chart_data['y_axis_suffix'] = 'd';
    $chart_data['plot_options'] = array($this->getChartType());
    $chart_data['y_axis_plot_lines'] = array(
      array(
        'value' => 3,
        'color' => 'green',
        'dashStyle' => 'shortdash',
        'width' => 2,
        'label' => array(
          'text' => 'Desired level',
        ),
      )
    );
    $chart_data['data_type'] = 'average';

    return $chart_data;
  }

  protected function fuseCommitsWithAudits(array $commits, array $commit_audits) {
    $ret = array();

    foreach ($commit_audits as $commit_phid => $audits) {
      $dates = array();
      $commit_data = $commits[$commit_phid];

      foreach ($audits as $audit_data) {
        $audit_action = $audit_data[$this->auditStatusColumn];

        if (!isset($dates[$audit_action])) {
          $dates[$audit_action] = array();
        }

        $dates[$audit_action][] = $audit_data['auditEpoch'];
      }

      if (count($dates) != 2) {
        // Immediately accepted or not yet fixed.
        continue;
      }

      $concern_raised = min($dates[PhabricatorAuditActionConstants::CONCERN]);
      $accepted = max($dates[PhabricatorAuditActionConstants::ACCEPT]);

      // Bug wasn't spotted at first and commit was accepted and then concern was raised.
      if ($concern_raised > $accepted) {
        continue;
      }

      $fixing_time = ceil(($accepted - $concern_raised) / 86400);

      $commit_data['fixingTime'] = $fixing_time;

      $ret[] = $commit_data;
    }

    return $ret;
  }

  protected function getGroupedData(array $raw_metrics) {
    $grouped_data = array();
    $date_group_format = $this->getDateGroupFormat();

    $breakdown_keys = array();
    $series_name = 'Average Fixing Duration';
    $breakdown_column = $this->getBreakdownColumn();

    foreach ($raw_metrics as $raw_metric) {
      $group_key = date($date_group_format, $raw_metric['commitEpoch']);

      if (!isset($grouped_data[$group_key])) {
        $grouped_data[$group_key] = array();
      }

      if ($breakdown_column) {
        $breakdown_key = $raw_metric[$breakdown_column];
      } else {
        $breakdown_key = self::NO_BREAKDOWN_KEY;
      }

      $breakdown_keys[$breakdown_key] = true;

      if (!isset($grouped_data[$group_key][$breakdown_key])) {
        $grouped_data[$group_key][$breakdown_key][$series_name] = array();
      }

      $grouped_data[$group_key][$breakdown_key][$series_name][] = $raw_metric['fixingTime'];
    }

    // Sort because data is sorted by audit date, but displayed by commit date.
    ksort($grouped_data);

    $this->seriesNames = array($series_name);
    $this->breakdownKeys = array_keys($breakdown_keys);

    return $this->calculateAverage($grouped_data);
  }

}
