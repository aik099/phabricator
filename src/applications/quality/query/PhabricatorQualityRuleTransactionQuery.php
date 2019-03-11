<?php

final class PhabricatorQualityRuleTransactionQuery
  extends PhabricatorApplicationTransactionQuery {

  public function getTemplateApplicationTransaction() {
    return new PhabricatorQualityRuleTransaction();
  }

}
