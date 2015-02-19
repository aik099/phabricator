<?php

abstract class PhabricatorMetricEngine {

  const NO_BREAKDOWN_KEY = 'default';

  protected $reportParams = array();

  private $viewer;

  final public function setViewer($viewer) {
    $this->viewer = $viewer;
    return $this;
  }

  final public function getViewer() {
    return $this->viewer;
  }

  final public static function loadAllEngines(PhabricatorUser $viewer) {
    $classes = id(new PhutilSymbolLoader())
      ->setAncestorClass(__CLASS__)
      ->setConcreteOnly(true)
      ->selectAndLoadSymbols();

    $objects = array();
    foreach ($classes as $class) {
      $engine = newv($class['name'], array());
      $engine->setViewer($viewer);

      if (!$engine->isEnabled()) {
        continue;
      }

      $objects[$class['name']] = $engine;
    }

    return $objects;
  }

  abstract public function getReportName();

  abstract protected function getChartType();

  abstract public function getChartParams(array $report_params);

  abstract protected function getRawMetrics();

  public function isDefault() {
    return false;
  }

  public function isEnabled() {
    return true;
  }

  public function isDeveloperOnly() {
    return false;
  }

  protected function getChartCategories(array $categories) {
    $renamed_categories = array();
    $granularity = $this->getReportParam('granularity');

    foreach ($categories as $category_name) {
      switch ($granularity) {
        case MetricReportParam::GRANULARITY_DAY:
          $category_name = date('j M', strtotime($category_name));
          break;

        case MetricReportParam::GRANULARITY_WEEK:
          list(, $week_number) = explode('-', $category_name, 2);
          $category_name = (int)$week_number.'w';
          break;

        case MetricReportParam::GRANULARITY_MONTH:
          list($year, $month) = explode('-', $category_name, 2);
          $category_name = date('M, y', strtotime($year.'-'.$month.'-01'));
          break;

        case MetricReportParam::GRANULARITY_YEAR:
          $category_name = date('y', strtotime($category_name.'-01-01')).'y';
          break;
      }

      $renamed_categories[] = $category_name;
    }

    return $renamed_categories;
  }

  protected function getDateGroupFormat() {
    $map = array(
      MetricReportParam::GRANULARITY_DAY => 'Y-m-d',
      MetricReportParam::GRANULARITY_WEEK => 'Y-W',
      MetricReportParam::GRANULARITY_MONTH => 'Y-m',
      MetricReportParam::GRANULARITY_YEAR => 'Y',
    );

    return $map[$this->getReportParam('granularity')];
  }

  protected function getReportParam($name) {
    return $this->reportParams[$name];
  }

  protected function calculateAverage(array $grouped_data) {
    foreach ($grouped_data as $group_name => $breakdown_groups) {
      foreach ($breakdown_groups as $breakdown_key => $breakdown_series) {
        foreach ($breakdown_series as $series_name => $series_data) {
          $average = round(array_sum($series_data) / count($series_data), 1);
          $grouped_data[$group_name][$breakdown_key][$series_name] = $average;
        }
      }
    }

    return $grouped_data;
  }

  protected function calculatePercentage(array $grouped_data) {
    foreach ($grouped_data as $group_name => $breakdown_groups) {
      foreach ($breakdown_groups as $breakdown_key => $breakdown_series) {
        $breakdown_series_total = array_sum($breakdown_series);

        foreach ($breakdown_series as $series_name => $series_data) {
          $percentage = round($series_data / $breakdown_series_total * 100, 1);
          $grouped_data[$group_name][$breakdown_key][$series_name] = $percentage;
        }
      }
    }

    return $grouped_data;
  }
}
