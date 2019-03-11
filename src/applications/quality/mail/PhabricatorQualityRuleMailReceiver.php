<?php

final class PhabricatorQualityRuleMailReceiver
  extends PhabricatorObjectMailReceiver {

  public function isEnabled() {
    return PhabricatorApplication::isClassInstalled(
      'PhabricatorQualityApplication');
  }

  protected function getObjectPattern() {
    return 'QR[1-9]\d*';
  }

  protected function loadObject($pattern, PhabricatorUser $viewer) {
    $id = (int)substr($pattern, 1);

    return id(new PhabricatorQualityRuleQuery())
      ->setViewer($viewer)
      ->withIDs(array($id))
      ->executeOne();
  }

  protected function getTransactionReplyHandler() {
    return new PhabricatorQualityRuleReplyHandler();
  }

}
