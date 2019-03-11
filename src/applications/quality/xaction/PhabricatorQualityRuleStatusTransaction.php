<?php

final class PhabricatorQualityRuleStatusTransaction
  extends PhabricatorQualityRuleTransactionType {

  const TRANSACTIONTYPE = 'quality.rule.status';

  public function generateOldValue($object) {
    return $object->getStatus();
  }

  public function applyInternalEffects($object, $value) {
    $object->setStatus($value);
  }

  private function isActivate() {
    return ($this->getNewValue() == PhabricatorQualityRule::STATUS_ACTIVE);
  }

  public function getIcon() {
    if ($this->isActivate()) {
      return 'fa-check';
    } else {
      return 'fa-ban';
    }
  }

  public function getColor() {
    if ($this->isActivate()) {
      return 'green';
    } else {
      return 'indigo';
    }
  }

  public function getTitle() {
    if ($this->isActivate()) {
      return pht(
        '%s activated this quality rule.',
        $this->renderAuthor());
    } else {
      return pht(
        '%s archived this quality rule.',
        $this->renderAuthor());
    }
  }

  public function getTitleForFeed() {
    if ($this->isActivate()) {
      return pht(
        '%s activated %s.',
        $this->renderAuthor(),
        $this->renderObject());
    } else {
      return pht(
        '%s archived %s.',
        $this->renderAuthor(),
        $this->renderObject());
    }
  }

}
