<?php

final class PhabricatorMetricConcernRaisedCommitsEngine extends PhabricatorMetricAuditActionAggregateEngine {
  public function getReportName() {
    return 'Commits with Issues';
  }

  protected function getChartType() {
    return 'spline';
  }

  public function isDefault() {
    return true;
  }

  protected function getSeries(array $grouped_data) {
    $series = array();
    $old_series = parent::getSeries($grouped_data);

    foreach ($old_series as $series_key => $series_data) {
      if (strpos($series_key, PhabricatorAuditActionConstants::ACCEPT) === 0) {
        continue;
      }

      $series[$series_key] = $series_data;
    }

    return $series;
  }

  public function getChartParams(array $report_params) {
    $chart_data = parent::getChartParams($report_params);

    if (!$chart_data) {
      return $chart_data;
    }

    $chart_data['stacking'] = 'normal';
    $chart_data['y_axis_title'] = 'Commits (with issues only)';
    $chart_data['plot_options'] = array($this->getChartType());
    $chart_data['y_axis_plot_lines'] = array(
      array(
        'value' => 5,
        'color' => 'green',
        'dashStyle' => 'shortdash',
        'width' => 2,
        'label' => array(
          'text' => 'Desired level',
        ),
      )
    );
    $chart_data['data_type'] = 'percent';

    return $chart_data;
  }
}
