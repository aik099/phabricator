<?php

final class PhabricatorRepositoryCustomFieldNumericIndex
  extends PhabricatorCustomFieldNumericIndexStorage {

  public function getApplicationName() {
    return 'repository';
  }

}
