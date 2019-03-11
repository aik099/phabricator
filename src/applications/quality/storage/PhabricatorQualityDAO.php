<?php

abstract class PhabricatorQualityDAO extends PhabricatorLiskDAO {

  public function getApplicationName() {
    return 'quality';
  }

}
