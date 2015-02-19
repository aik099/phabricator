<?php

final class MetricChartView extends AphrontView {

  private $chartData;

  public function setChartData($chart_data) {
    $this->chartData = $chart_data;
  }

  public function render() {

    if (!$this->chartData) {
      return id(new PHUIInfoView())
        ->setSeverity(PHUIInfoView::SEVERITY_NODATA)
        ->appendChild(pht('No data.'));
    }

    Javelin::initBehavior('metric-report', $this->chartData);

    $chart_container = phutil_tag(
      'div',
      array(
        'id' => 'report-container',
        'style' => 'width: 100%; height: 400px;'
      )
    );

    $box = id(new PHUIBoxView())
      ->setBorder(true)
      ->addMargin(PHUI::MARGIN_LARGE_LEFT)
      ->addMargin(PHUI::MARGIN_LARGE_RIGHT)
      ->addMargin(PHUI::MARGIN_LARGE_TOP)
      ->appendChild($chart_container);

    return $box;
  }
}
