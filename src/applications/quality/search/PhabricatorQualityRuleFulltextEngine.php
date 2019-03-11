<?php

final class PhabricatorQualityRuleFulltextEngine
  extends PhabricatorFulltextEngine {

  protected function buildAbstractDocument(
    PhabricatorSearchAbstractDocument $document,
    $object) {

    $rule = $object;

    $document->setDocumentTitle($rule->getName());

    $document->addField(
      PhabricatorSearchDocumentFieldType::FIELD_BODY,
      $rule->getDescription());

    $document->addRelationship(
      PhabricatorSearchRelationship::RELATIONSHIP_AUTHOR,
      $rule->getAuthorPHID(),
      PhabricatorPeopleUserPHIDType::TYPECONST,
      $rule->getDateCreated());

    $document->addRelationship(
      $rule->isArchived()
        ? PhabricatorSearchRelationship::RELATIONSHIP_CLOSED
        : PhabricatorSearchRelationship::RELATIONSHIP_OPEN,
      $rule->getPHID(),
      PhabricatorQualityRulePHIDType::TYPECONST,
      PhabricatorTime::getNow());
  }

}
