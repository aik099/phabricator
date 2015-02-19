<?php

final class PhabricatorMetricCommitAuditsEngine extends PhabricatorMetricAuditActionAggregateEngine {
  public function getReportName() {
    return 'Checked Commits';
  }

  protected function getChartType() {
    return 'column';
  }

  public function isEnabled() {
    return $this->getViewer()->getUserName() == 'alex';
  }

  public function isDeveloperOnly() {
    return true;
  }

  public function getChartParams(array $report_params) {
    $chart_data = parent::getChartParams($report_params);

    if (!$chart_data) {
      return $chart_data;
    }

    $chart_data['stacking'] = 'normal';
    $chart_data['y_axis_title'] = 'Commits';
    $chart_data['plot_options'] = array($this->getChartType());
    $chart_data['data_type'] = 'percent';

    return $chart_data;
  }
}
