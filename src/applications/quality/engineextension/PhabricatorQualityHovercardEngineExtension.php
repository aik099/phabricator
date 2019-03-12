<?php

final class PhabricatorQualityHovercardEngineExtension
  extends PhabricatorHovercardEngineExtension {

  const EXTENSIONKEY = 'quality';

  public function isExtensionEnabled() {
    return PhabricatorApplication::isClassInstalled(
      'PhabricatorQualityApplication');
  }

  public function getExtensionName() {
    return pht('Quality Rules');
  }

  public function canRenderObjectHovercard($object) {
    return ($object instanceof PhabricatorQualityRule);
  }

  public function willRenderHovercards(array $objects) {
    $viewer = $this->getViewer();
    $phids = mpull($objects, 'getPHID');

    $rules = id(new PhabricatorQualityRuleQuery())
      ->setViewer($viewer)
      ->withPHIDs($phids)
      ->execute();
    $rules = mpull($rules, null, 'getPHID');

    return array(
      'rules' => $rules,
    );
  }

  public function renderHovercard(
    PHUIHovercardView $hovercard,
    PhabricatorObjectHandle $handle,
    $object,
    $data) {

    $viewer = $this->getViewer();

    $rule = idx($data['rules'], $object->getPHID());
    if (!$rule) {
      return;
    }

    $hovercard->setTitle('QR'.$rule->getID());
    $hovercard->setDetail(new PHUIRemarkupView($viewer, $rule->getDescription()));

    $hovercard->addField(
      pht('Date'),
      phabricator_date($rule->getDateCreated(), $viewer));

    $status_map = PhabricatorQualityRule::getStatusNameMap();

    $hovercard->addField(
      pht('Status'),
      $status_map[$rule->getStatus()]);
  }

}
