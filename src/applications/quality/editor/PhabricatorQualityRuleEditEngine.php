<?php

final class PhabricatorQualityRuleEditEngine
  extends PhabricatorEditEngine {

  const ENGINECONST = 'quality.rule';

  public function getEngineName() {
    return pht('Quality Rules');
  }

  public function getEngineApplicationClass() {
    return 'PhabricatorQualityApplication';
  }

  public function getSummaryHeader() {
    return pht('Configure Quality Rule Forms');
  }

  public function getSummaryText() {
    return pht('Configure creation and editing forms of Quality Rules.');
  }

  public function isEngineConfigurable() {
    return false;
  }

  protected function newEditableObject() {
    return PhabricatorQualityRule::initializeNewQualityRule($this->getViewer());
  }

  protected function newObjectQuery() {
    return new PhabricatorQualityRuleQuery();
  }

  protected function getObjectCreateTitleText($object) {
    return pht('Create New Quality Rule');
  }

  protected function getObjectEditTitleText($object) {
    return pht('Edit Quality Rule: %s', $object->getName());
  }

  protected function getObjectEditShortText($object) {
    return $object->getName();
  }

  protected function getObjectCreateShortText() {
    return pht('Create Quality Rule');
  }

  protected function getObjectName() {
    return pht('Quality Rule');
  }

  protected function getObjectCreateCancelURI($object) {
    return $this->getApplication()->getApplicationURI('/');
  }

  protected function getEditorURI() {
    return $this->getApplication()->getApplicationURI('rule/edit/');
  }

  protected function getObjectViewURI($object) {
    return $object->getURI();
  }

  protected function getCreateNewObjectPolicy() {
    return $this->getApplication()->getPolicy(
      PhabricatorQualityRuleCreateCapability::CAPABILITY);
  }

  protected function buildCustomEditFields($object) {

    return array(
      id(new PhabricatorTextEditField())
        ->setKey('name')
        ->setLabel(pht('Name'))
        ->setDescription(pht('Quality rule name.'))
        ->setIsRequired(true)
        ->setConduitTypeDescription(pht('New quality rule name.'))
        ->setTransactionType(
          PhabricatorQualityRuleNameTransaction::TRANSACTIONTYPE)
        ->setValue($object->getName()),
      id(new PhabricatorRemarkupEditField())
        ->setKey('description')
        ->setLabel(pht('Description'))
        ->setDescription(pht('Quality rule long description.'))
        ->setConduitTypeDescription(pht('New quality rule description.'))
        ->setTransactionType(
          PhabricatorQualityRuleDescriptionTransaction::TRANSACTIONTYPE)
        ->setValue($object->getDescription()),
      id(new PhabricatorSelectEditField())
        ->setKey('status')
        ->setLabel(pht('Status'))
        ->setTransactionType(
          PhabricatorQualityRuleStatusTransaction::TRANSACTIONTYPE)
        ->setIsFormField(false)
        ->setOptions(PhabricatorQualityRule::getStatusNameMap())
        ->setDescription(pht('Active or archived status.'))
        ->setConduitDescription(pht('Active or archive the quality rule.'))
        ->setConduitTypeDescription(pht('New quality rule status constant.'))
        ->setValue($object->getStatus()),
    );
  }

}
