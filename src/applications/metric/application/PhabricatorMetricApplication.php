<?php

final class PhabricatorMetricApplication extends PhabricatorApplication {

  public function getShortDescription() {
    return pht('Chart and Analyze Data');
  }

  public function getName() {
    return pht('Metrics');
  }

  public function getBaseURI() {
    return '/metric/';
  }

  public function getFontIcon() {
    return 'fa-line-chart';
  }

  public function getApplicationGroup() {
    return self::GROUP_UTILITIES;
  }

  public function isPrototype() {
    return true;
  }

  public function getRoutes() {
    return array(
      '/metric/' => array(
        '' => 'PhabricatorMetricHomeController',
      ),
    );
  }

}
