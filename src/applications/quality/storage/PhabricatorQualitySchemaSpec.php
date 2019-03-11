<?php

final class PhabricatorQualitySchemaSpec
  extends PhabricatorConfigSchemaSpec {

  public function buildSchemata() {
    $this->buildEdgeSchemata(new PhabricatorQualityRule());
  }

}
