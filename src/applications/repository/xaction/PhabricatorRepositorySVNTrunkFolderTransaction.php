<?php

final class PhabricatorRepositorySVNTrunkFolderTransaction
  extends PhabricatorRepositoryTransactionType {

  const TRANSACTIONTYPE = 'repo:svn-trunk-folder';

  public function generateOldValue($object) {
    return $object->getDetail('svn-trunk-folder');
  }

  public function applyInternalEffects($object, $value) {
    $object->setDetail('svn-trunk-folder', $value);
  }

  public function getTitle() {
    $old = $this->getOldValue();
    $new = $this->getNewValue();

    if (!strlen($new)) {
      return pht(
        '%s restored default value for repository trunk folder.',
        $this->renderAuthor());
    } else if (!strlen($old)) {
      return pht(
        '%s set the repository trunk folder to "%s".',
        $this->renderAuthor(),
        $this->renderNewValue());
    } else {
      return pht(
        '%s changed the repository trunk folder from "%s" to "%s".',
        $this->renderAuthor(),
        $this->renderOldValue(),
        $this->renderNewValue());
    }
  }

}
