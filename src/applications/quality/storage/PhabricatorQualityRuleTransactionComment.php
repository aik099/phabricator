<?php

final class PhabricatorQualityRuleTransactionComment
  extends PhabricatorApplicationTransactionComment {

  public function getApplicationTransactionObject() {
    return new PhabricatorQualityRuleTransaction();
  }

}
