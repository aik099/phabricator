<?php

final class PhabricatorQualityRuleEditController
  extends PhabricatorQualityController {

  public function handleRequest(AphrontRequest $request) {
    return id(new PhabricatorQualityRuleEditEngine())
      ->setController($this)
      ->buildResponse();
  }
}
