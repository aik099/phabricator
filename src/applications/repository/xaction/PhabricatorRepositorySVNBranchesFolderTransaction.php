<?php

final class PhabricatorRepositorySVNBranchesFolderTransaction
  extends PhabricatorRepositoryTransactionType {

  const TRANSACTIONTYPE = 'repo:svn-branches-folder';

  public function generateOldValue($object) {
    return $object->getDetail('svn-branches-folder');
  }

  public function applyInternalEffects($object, $value) {
    $object->setDetail('svn-branches-folder', $value);
  }

  public function getTitle() {
    $old = $this->getOldValue();
    $new = $this->getNewValue();

    if (!strlen($new)) {
      return pht(
        '%s restored default value for repository branches folder.',
        $this->renderAuthor());
    } else if (!strlen($old)) {
      return pht(
        '%s set the repository branches folder to "%s".',
        $this->renderAuthor(),
        $this->renderNewValue());
    } else {
      return pht(
        '%s changed the repository branches folder from "%s" to "%s".',
        $this->renderAuthor(),
        $this->renderOldValue(),
        $this->renderNewValue());
    }
  }

}
