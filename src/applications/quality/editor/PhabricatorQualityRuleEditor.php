<?php

final class PhabricatorQualityRuleEditor
  extends PhabricatorApplicationTransactionEditor {

  public function getEditorApplicationClass() {
    return 'PhabricatorQualityApplication';
  }

  public function getEditorObjectsDescription() {
    return pht('Quality Rule');
  }

  public function getCreateObjectTitle($author, $object) {
    return pht('%s created this Quality Rule.', $author);
  }

  public function getCreateObjectTitleForFeed($author, $object) {
    return pht('%s created %s.', $author, $object);
  }

  protected function supportsSearch() {
    return true;
  }

  public function getTransactionTypes() {
    $types = parent::getTransactionTypes();
    $types[] = PhabricatorTransactions::TYPE_COMMENT;
    $types[] = PhabricatorTransactions::TYPE_VIEW_POLICY;
    $types[] = PhabricatorTransactions::TYPE_EDIT_POLICY;

    return $types;
  }

  protected function shouldSendMail(
    PhabricatorLiskDAO $object,
    array $xactions) {

    // Avoid sending emails that only talk about adding a relationship.
    $types = array_unique(mpull($xactions, 'getTransactionType'));
    if ($types === array(PhabricatorTransactions::TYPE_EDGE)) {
      return false;
    }
    return true;
  }

  public function getMailTagsMap() {
    return array(
      PhabricatorQualityRuleTransaction::MAILTAG_DETAILS =>
        pht(
          "A Quality Rule's details change."),
    );
  }

  protected function shouldPublishFeedStory(
    PhabricatorLiskDAO $object,
    array $xactions) {
    return true;
  }

  protected function getMailSubjectPrefix() {
    return pht('[Quality Rule]');
  }

  protected function getMailTo(PhabricatorLiskDAO $object) {
    $phids = array();
    $phids[] = $this->getActingAsPHID();

    return $phids;
  }

  protected function buildMailTemplate(PhabricatorLiskDAO $object) {
    $id = $object->getID();
    $name = $object->getName();

    return id(new PhabricatorMetaMTAMail())
      ->setSubject("QR{$id}: {$name}");
  }

  protected function buildMailBody(
    PhabricatorLiskDAO $object,
    array $xactions) {

    $description = $object->getDescription();
    $body = parent::buildMailBody($object, $xactions);

    if (strlen($description)) {
      $body->addRemarkupSection(
        pht('QUALITY RULE DESCRIPTION'),
        $object->getDescription());
    }

    $body->addLinkSection(
      pht('QUALITY RULE DETAIL'),
      PhabricatorEnv::getProductionURI('/QR'.$object->getID()));


    return $body;
  }

  protected function buildReplyHandler(PhabricatorLiskDAO $object) {
    return id(new PhabricatorQualityRuleReplyHandler())
      ->setMailReceiver($object);
  }

}
