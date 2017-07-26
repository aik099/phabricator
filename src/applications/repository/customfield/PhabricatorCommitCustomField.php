<?php

abstract class PhabricatorCommitCustomField
  extends PhabricatorCustomField {

  /**
   * TODO: It would be nice to remove this, but a lot of different code is
   * bound together by it. Until everything is modernized, retaining the old
   * field keys is the only reasonable way to update things one piece
   * at a time.
   */
  public function getFieldKeyForConduit() {
    return $this->getFieldKey();
  }

  // TODO: As above.
  public function getModernFieldKey() {
    return $this->getFieldKeyForConduit();
  }

  public function newStorageObject() {
    return new PhabricatorRepositoryCustomFieldStorage();
  }

  protected function newStringIndexStorage() {
    return new PhabricatorRepositoryCustomFieldStringIndex();
  }

  protected function newNumericIndexStorage() {
    return new PhabricatorRepositoryCustomFieldNumericIndex();
  }
}
