<?php

final class DifferentialDoorkeeperRevisionFeedStoryPublisher
  extends DoorkeeperFeedStoryPublisher {

  public function canPublishStory(PhabricatorFeedStory $story, $object) {
    return ($object instanceof DifferentialRevision);
  }

  public function isStoryAboutObjectCreation($object) {
    $story = $this->getFeedStory();
    $xaction = $story->getPrimaryTransaction();

    switch ($xaction->getTransactionType()) {
      case DifferentialTransaction::TYPE_ACTION:
        return $xaction->getNewValue() == DifferentialAction::ACTION_CREATE;
    		break;

      case PhabricatorTransactions::TYPE_CUSTOMFIELD:
        $custom_field_name = $xaction->getMetadataValue('customfield:key');
        if ($custom_field_name == 'differential:title' && !strlen($xaction->getOldValue())) {
          return true;
        }
        break;
    }

    return false;
  }

  public function isStoryAboutObjectClosure($object) {
    $story = $this->getFeedStory();
    $xaction = $story->getPrimaryTransaction();

    if ($xaction->getTransactionType() == DifferentialTransaction::TYPE_ACTION) {
      $close_statuses = array(
        DifferentialAction::ACTION_CLOSE, DifferentialAction::ACTION_ABANDON);

      return in_array($xaction->getNewValue(), $close_statuses);
    }

    return false;
  }

  public function isStoryAboutObjectReview($object) {
    $status = $object->getStatus();
    $needs_review_status = ArcanistDifferentialRevisionStatus::NEEDS_REVIEW;
    if ($status != $needs_review_status) {
      return false;
    }

    $story = $this->getFeedStory();

    // When creating revision & adding reviewer,
    // then "reviewer added" transaction isn't primary.
    foreach ($story->getValue('transactionPHIDs') as $xaction_phid) {
      $xaction = $story->getObject($xaction_phid);

      switch ($xaction->getTransactionType()) {
        // Action, that results in status change to "Needs Review".
        case DifferentialRevisionReclaimTransaction::TRANSACTIONTYPE:
        case DifferentialRevisionReopenTransaction::TRANSACTIONTYPE:
        case DifferentialRevisionRequestReviewTransaction::TRANSACTIONTYPE:
        case DifferentialRevisionReviewersTransaction::TRANSACTIONTYPE:
        // Revision diff updated.
        case DifferentialTransaction::TYPE_UPDATE:
          return count($object->getReviewers()) > 0;
          break;
      }
    }

    return false;
  }

  public function isStoryAboutObjectAccept($object) {
    $story = $this->getFeedStory();
    $xaction = $story->getPrimaryTransaction();

    return $xaction->getTransactionType()
      == DifferentialRevisionAcceptTransaction::TRANSACTIONTYPE;
  }

  public function isStoryAboutObjectReject($object) {
    $story = $this->getFeedStory();
    $xaction = $story->getPrimaryTransaction();

    return $xaction->getTransactionType()
      == DifferentialRevisionRejectTransaction::TRANSACTIONTYPE;
  }

  public function willPublishStory($object) {
    return id(new DifferentialRevisionQuery())
      ->setViewer($this->getViewer())
      ->withIDs(array($object->getID()))
      ->needReviewers(true)
      ->executeOne();
  }

  public function getOwnerPHID($object) {
    return $object->getAuthorPHID();
  }

  public function getActiveUserPHIDs($object) {
    if ($object->isNeedsReview()) {
      return $object->getReviewerPHIDs();
    } else {
      return array();
    }
  }

  public function getPassiveUserPHIDs($object) {
    if ($object->isNeedsReview()) {
      return array();
    } else {
      return $object->getReviewerPHIDs();
    }
  }

  public function getCCUserPHIDs($object) {
    return PhabricatorSubscribersQuery::loadSubscribersForPHID(
      $object->getPHID());
  }

  public function getObjectTitle($object) {
    $id = $object->getID();

    $title = $object->getTitle();

    return "D{$id}: {$title}";
  }

  public function getObjectURI($object) {
    return PhabricatorEnv::getProductionURI('/D'.$object->getID());
  }

  public function getObjectDescription($object) {
    return $object->getSummary();
  }

  public function isObjectClosed($object) {
    return $object->isClosed();
  }

  public function getResponsibilityTitle($object) {
    $prefix = $this->getTitlePrefix($object);
    return pht('%s Review Request', $prefix);
  }

  private function getTitlePrefix(DifferentialRevision $revision) {
    return pht('[Differential]');
  }

}
