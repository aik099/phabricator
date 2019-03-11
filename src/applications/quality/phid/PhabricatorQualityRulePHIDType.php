<?php

final class PhabricatorQualityRulePHIDType extends PhabricatorPHIDType {

  const TYPECONST = 'QRLE';

  public function getTypeName() {
    return pht('Quality Rule');
  }

  public function newObject() {
    return new PhabricatorQualityRule();
  }

  public function getPHIDTypeApplicationClass() {
    return 'PhabricatorQualityApplication';
  }

  protected function buildQueryForObjects(
    PhabricatorObjectQuery $query,
    array $phids) {

    return id(new PhabricatorQualityRuleQuery())
      ->withPHIDs($phids);
  }

  public function loadHandles(
    PhabricatorHandleQuery $query,
    array $handles,
    array $objects) {

    foreach ($handles as $phid => $handle) {
      $rule = $objects[$phid];

      $id = $rule->getID();
      $name = $rule->getName();
      $full_name = $rule->getMonogram().': '.$name;

      $handle
        ->setName($name)
        ->setFullName($full_name)
        ->setURI($rule->getURI());

      if ($rule->isArchived()) {
        $handle->setStatus(PhabricatorObjectHandle::STATUS_CLOSED);
      }
    }
  }

  public function canLoadNamedObject($name) {
    return preg_match('/^QR[1-9]\d*$/i', $name);
  }

  public function loadNamedObjects(
    PhabricatorObjectQuery $query,
    array $names) {

    $id_map = array();
    foreach ($names as $name) {
      $id = (int)substr($name, 1);
      $id_map[$id][] = $name;
    }

    $objects = id(new PhabricatorQualityRuleQuery())
      ->setViewer($query->getViewer())
      ->withIDs(array_keys($id_map))
      ->execute();

    $results = array();
    foreach ($objects as $id => $object) {
      foreach (idx($id_map, $id, array()) as $name) {
        $results[$name] = $object;
      }
    }

    return $results;
  }
}
