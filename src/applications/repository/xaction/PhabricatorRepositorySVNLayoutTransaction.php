<?php

final class PhabricatorRepositorySVNLayoutTransaction
  extends PhabricatorRepositoryTransactionType {

  const TRANSACTIONTYPE = 'repo:svn-layout';

  public function generateOldValue($object) {
    return $object->getDetail('svn-layout');
  }

  public function applyInternalEffects($object, $value) {
    $object->setSubversionLayout($value);
  }

  public function getTitle() {
    $old = $this->getOldValue();
    $new = $this->getNewValue();

    $layout_names = array(
      PhabricatorRepository::LAYOUT_NONE => pht('None'),
      PhabricatorRepository::LAYOUT_STANDARD => pht('Standard'),
      PhabricatorRepository::LAYOUT_CUSTOM => pht('Custom'),
    );

    if (!strlen($new)) {
      return pht(
        '%s restored default value for repository layout.',
        $this->renderAuthor(),
        $this->renderValue(idx($layout_names, $old, 'Unknown')));
    } else if (!strlen($old)) {
      return pht(
        '%s set the repository layout to "%s".',
        $this->renderAuthor(),
        $this->renderValue(idx($layout_names, $new, 'Unknown')));
    } else {
      return pht(
        '%s changed the repository layout from "%s" to "%s".',
        $this->renderAuthor(),
        $this->renderValue(idx($layout_names, $old, 'Unknown')),
        $this->renderValue(idx($layout_names, $new, 'Unknown')));
    }
  }

}
