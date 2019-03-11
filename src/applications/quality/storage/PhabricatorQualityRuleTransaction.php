<?php

final class PhabricatorQualityRuleTransaction
  extends PhabricatorModularTransaction {

  const MAILTAG_DETAILS = 'quality-rule-details';

  public function getApplicationName() {
    return 'quality';
  }

  public function getApplicationTransactionType() {
    return PhabricatorQualityRulePHIDType::TYPECONST;
  }

  public function getApplicationTransactionCommentObject() {
    return new PhabricatorQualityRuleTransactionComment();
  }

  public function getBaseTransactionClass() {
    return 'PhabricatorQualityRuleTransactionType';
  }

  public function getRequiredHandlePHIDs() {
    $phids = parent::getRequiredHandlePHIDs();

    switch ($this->getTransactionType()) {
      case PhabricatorQualityRuleNameTransaction::TRANSACTIONTYPE:
      case PhabricatorQualityRuleDescriptionTransaction::TRANSACTIONTYPE:
        $phids[] = $this->getObjectPHID();
        break;
    }

    return $phids;
  }

  public function getMailTags() {
    $tags = array();
    switch ($this->getTransactionType()) {
      case PhabricatorQualityRuleNameTransaction::TRANSACTIONTYPE:
      case PhabricatorQualityRuleDescriptionTransaction::TRANSACTIONTYPE:
        $tags[] = self::MAILTAG_DETAILS;
        break;
    }
    return $tags;
  }

}
