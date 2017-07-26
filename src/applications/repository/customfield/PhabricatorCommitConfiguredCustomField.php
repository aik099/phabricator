<?php

final class PhabricatorCommitConfiguredCustomField
  extends PhabricatorCommitCustomField
  implements PhabricatorStandardCustomFieldInterface {

  public function getStandardCustomFieldNamespace() {
    return 'diffusion';
  }

  public function createFields($object) {
    $config = PhabricatorEnv::getEnvConfig(
      'diffusion.custom-field-definitions');
    $fields = PhabricatorStandardCustomField::buildStandardFields(
      $this,
      $config);

    return $fields;
  }

}
