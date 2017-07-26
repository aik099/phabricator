<?php

abstract class PhabricatorCommitStoredCustomField
  extends PhabricatorCommitCustomField {

  private $value;

  public function setValue($value) {
    $this->value = $value;
    return $this;
  }

  public function getValue() {
    return $this->value;
  }

  public function shouldUseStorage() {
    return true;
  }

  public function getValueForStorage() {
    return $this->value;
  }

  public function setValueFromStorage($value) {
    $this->value = $value;
    return $this;
  }

  public function setValueFromApplicationTransactions($value) {
    $this->setValue($value);
    return $this;
  }

  public function getConduitDictionaryValue() {
    return $this->getValue();
  }

}
