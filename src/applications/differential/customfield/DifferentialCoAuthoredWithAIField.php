<?php

final class DifferentialCoAuthoredWithAIField
  extends DifferentialStoredCustomField {

  public function getFieldKey() {
    return 'differential:co-authored-with-ai';
  }

  public function getFieldName() {
    return pht('Co-authored with AI');
  }

  public function getFieldDescription() {
    return pht('Marks this revision as co-authored with an AI tool.');
  }

  public function getValueForStorage() {
    $value = $this->getValue();
    if ($value !== null) {
      return (int)$value;
    }

    return null;
  }

  public function setValueFromStorage($value) {
    if (strlen($value)) {
      $value = (bool)$value;
    } else {
      $value = null;
    }
    return $this->setValue($value);
  }

  public function shouldAppearInPropertyView() {
    return true;
  }

  public function renderPropertyViewLabel() {
    return $this->getFieldName();
  }

  public function renderPropertyViewValue(array $handles) {
    if ($this->getValue()) {
      return pht('Yes');
    }
    return null;
  }

  public function shouldAppearInEditView() {
    return true;
  }

  public function shouldAppearInApplicationTransactions() {
    return true;
  }

  public function getOldValueForApplicationTransactions() {
    return (bool)$this->getValue();
  }

  public function getNewValueForApplicationTransactions() {
    return (bool)$this->getValue();
  }

  public function readValueFromRequest(AphrontRequest $request) {
    $this->setValue((bool)$request->getBool($this->getFieldKey()));
  }

  public function renderEditControl(array $handles) {
    return id(new AphrontFormCheckboxControl())
      ->setLabel($this->getFieldName())
      ->setCaption($this->getFieldDescription())
      ->addCheckbox(
        $this->getFieldKey(),
        1,
        null,
        (bool)$this->getValue());
  }

  public function getApplicationTransactionTitle(
    PhabricatorApplicationTransaction $xaction) {
    $author_phid = $xaction->getAuthorPHID();
    $new = $xaction->getNewValue();

    if ($new) {
      return pht(
        '%s checked %s.',
        $xaction->renderHandleLink($author_phid),
        $this->getFieldName());
    }

    return pht(
      '%s unchecked %s.',
      $xaction->renderHandleLink($author_phid),
      $this->getFieldName());
  }

  public function shouldAppearInConduitDictionary() {
    return true;
  }

  public function shouldAppearInConduitTransactions() {
    return true;
  }

  protected function newConduitEditParameterType() {
    return new ConduitBoolParameterType();
  }

}
