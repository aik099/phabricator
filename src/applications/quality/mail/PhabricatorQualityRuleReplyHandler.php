<?php

final class PhabricatorQualityRuleReplyHandler
  extends PhabricatorApplicationTransactionReplyHandler {

  public function validateMailReceiver($mail_receiver) {
    if (!($mail_receiver instanceof PhabricatorQualityRule)) {
      throw new Exception(
        pht(
          'Mail receiver is not a %s!',
          'PhabricatorQualityRule'));
    }
  }

  public function getObjectPrefix() {
    return 'QR';
  }

}
