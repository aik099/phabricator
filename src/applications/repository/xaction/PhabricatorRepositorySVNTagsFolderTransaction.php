<?php

final class PhabricatorRepositorySVNTagsFolderTransaction
  extends PhabricatorRepositoryTransactionType {

  const TRANSACTIONTYPE = 'repo:svn-tags-folder';

  public function generateOldValue($object) {
    return $object->getDetail('svn-tags-folder');
  }

  public function applyInternalEffects($object, $value) {
    $object->setDetail('svn-tags-folder', $value);
  }

  public function getTitle() {
    $old = $this->getOldValue();
    $new = $this->getNewValue();

    if (!strlen($new)) {
      return pht(
        '%s restored default value for repository tags folder.',
        $this->renderAuthor());
    } else if (!strlen($old)) {
      return pht(
        '%s set the repository tags folder to "%s".',
        $this->renderAuthor(),
        $this->renderNewValue());
    } else {
      return pht(
        '%s changed the repository tags folder from "%s" to "%s".',
        $this->renderAuthor(),
        $this->renderOldValue(),
        $this->renderNewValue());
    }
  }

}
