<?php

final class PhabricatorQualityRuleNameNgrams
  extends PhabricatorSearchNgrams {

  public function getNgramKey() {
    return 'qualityrulename';
  }

  public function getColumnName() {
    return 'name';
  }

  public function getApplicationName() {
    return 'quality';
  }

}
