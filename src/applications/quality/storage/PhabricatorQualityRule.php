<?php

final class PhabricatorQualityRule extends PhabricatorQualityDAO
  implements PhabricatorPolicyInterface,
  PhabricatorProjectInterface,
  PhabricatorApplicationTransactionInterface,
  PhabricatorSubscribableInterface,
  PhabricatorTokenReceiverInterface,
  PhabricatorDestructibleInterface,
  PhabricatorMentionableInterface,
  PhabricatorFlaggableInterface,
  PhabricatorAuthorAwareInterface,
  PhabricatorSpacesInterface,
  PhabricatorConduitResultInterface,
  PhabricatorNgramsInterface,
  PhabricatorFulltextInterface,
  PhabricatorFerretInterface {

  protected $name;
  protected $description;

  protected $viewPolicy;
  protected $editPolicy;

  protected $authorPHID;
  protected $spacePHID;

  protected $mailKey;
  protected $status;

  const STATUS_ACTIVE = 'active';
  const STATUS_ARCHIVED = 'archived';

  const DEFAULT_ICON = 'fa-ambulance';

  public static function initializeNewQualityRule(PhabricatorUser $actor) {
    $app = id(new PhabricatorApplicationQuery())
      ->setViewer($actor)
      ->withClasses(array('PhabricatorQualityApplication'))
      ->executeOne();

    return id(new PhabricatorQualityRule())
      ->setAuthorPHID($actor->getPHID())
      ->setViewPolicy(PhabricatorPolicies::getMostOpenPolicy())
      ->setEditPolicy($actor->getPHID())
      ->setSpacePHID($actor->getDefaultSpacePHID())
      ->setStatus(self::STATUS_ACTIVE);
  }

  public static function getStatusNameMap() {
    return array(
      self::STATUS_ACTIVE => pht('Active'),
      self::STATUS_ARCHIVED => pht('Archived'),
    );
  }

  protected function getConfiguration() {
    return array(
      self::CONFIG_AUX_PHID => true,
      self::CONFIG_COLUMN_SCHEMA => array(
        'name' => 'text128',
        'description' => 'text',
        'status' => 'text32',
        'mailKey' => 'bytes20',
      ),
      self::CONFIG_KEY_SCHEMA => array(
        'key_instance' => array(
          'columns' => array('name'),
          'unique' => true,
        ),
        'key_author' => array(
          'columns' => array('authorPHID'),
        ),
      ),
    ) + parent::getConfiguration();
  }

  public function save() {
    if (!$this->getMailKey()) {
      $this->setMailKey(Filesystem::readRandomCharacters(20));
    }
    return parent::save();
  }

  public function generatePHID() {
    return PhabricatorPHID::generateNewPHID(
      PhabricatorQualityRulePHIDType::TYPECONST);
  }

  public function getMonogram() {
    return 'QR'.$this->getID();
  }

  public function getURI() {
    $uri = '/'.$this->getMonogram();
    return $uri;
  }

  public function isArchived() {
    return ($this->getStatus() == self::STATUS_ARCHIVED);
  }

/* -(  PhabricatorPolicyInterface  )----------------------------------------- */


  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
      PhabricatorPolicyCapability::CAN_EDIT,
    );
  }

  public function getPolicy($capability) {
    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        return $this->getViewPolicy();
      case PhabricatorPolicyCapability::CAN_EDIT:
        return $this->getEditPolicy();
    }
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    $user_phid = $this->getAuthorPHID();
    if ($user_phid) {
      $viewer_phid = $viewer->getPHID();
      if ($viewer_phid == $user_phid) {
        return true;
      }
    }

    return false;
  }

  public function describeAutomaticCapability($capability) {
    return pht('The owner of a Quality Rule can always view and edit it.');
  }

/* -(  PhabricatorApplicationTransactionInterface  )------------------------- */


  public function getApplicationTransactionEditor() {
    return new PhabricatorQualityRuleEditor();
  }

  public function getApplicationTransactionTemplate() {
    return new PhabricatorQualityRuleTransaction();
  }


/* -(  PhabricatorSubscribableInterface  )----------------------------------- */


  public function isAutomaticallySubscribed($phid) {
    return ($phid == $this->getAuthorPHID());
  }


/* -(  PhabricatorTokenReceiverInterface  )---------------------------------- */


  public function getUsersToNotifyOfTokenGiven() {
    return array($this->getAuthorPHID());
  }

/* -(  PhabricatorDestructibleInterface  )----------------------------------- */


  public function destroyObjectPermanently(
    PhabricatorDestructionEngine $engine) {

    $this->openTransaction();
    $this->delete();
    $this->saveTransaction();
  }

/* -(  PhabricatorSpacesInterface  )----------------------------------------- */


  public function getSpacePHID() {
    return $this->spacePHID;
  }

/* -(  PhabricatorConduitResultInterface  )---------------------------------- */


  public function getFieldSpecificationsForConduit() {
    return array(
      id(new PhabricatorConduitSearchFieldSpecification())
        ->setKey('name')
        ->setType('string')
        ->setDescription(pht('Quality Rule name.')),
      id(new PhabricatorConduitSearchFieldSpecification())
        ->setKey('description')
        ->setType('string')
        ->setDescription(pht('A description of the Quality Rule.')),
    );
  }

  public function getFieldValuesForConduit() {
    return array(
      'name' => $this->getName(),
      'description' => $this->getDescription(),
    );
  }

  public function getConduitSearchAttachments() {
    return array();
  }

  /* -(  PhabricatorNgramInterface  )------------------------------------------ */


  public function newNgrams() {
    return array(
      id(new PhabricatorQualityRuleNameNgrams())
        ->setValue($this->getName()),
    );
  }

  /* -(  PhabricatorAuthorAwareInterface  )----------------------------------- */


  public function getAuthor() {
    return $this->getAuthorPHID();
 }


  /* -(  PhabricatorFulltextInterface  )--------------------------------------- */

  public function newFulltextEngine() {
    return new PhabricatorQualityRuleFulltextEngine();
  }


 /* -(  PhabricatorFerretInterface  )----------------------------------------- */


  public function newFerretEngine() {
    return new PhabricatorQualityRuleFerretEngine();
  }

}
