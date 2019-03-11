<?php

final class PhabricatorQualityApplication extends PhabricatorApplication {

  public function getName() {
    return pht('Quality');
  }

  public function getShortDescription() {
    return pht('Quality Rules');
  }

  public function getFlavorText() {
    return pht('Create quality rules.');
  }

  public function getBaseURI() {
    return '/quality/';
  }

  public function getIcon() {
    return 'fa-ambulance';
  }

  public function isPrototype() {
    return true;
  }

  public function getApplicationGroup() {
    return self::GROUP_UTILITIES;
  }

  public function getRemarkupRules() {
    return array(
      new PhabricatorQualityRemarkupRule(),
    );
  }

  public function getRoutes() {
    return array(
      '/QR(?P<id>[1-9]\d*)/?' => 'PhabricatorQualityRuleViewController',
      '/quality/' => array(
        '(?:query/(?P<queryKey>[^/]+)/)?'
          => 'PhabricatorQualityRuleListController',
        'rule/' => array(
          $this->getEditRoutePattern('edit/') => 'PhabricatorQualityRuleEditController',
          'archive/(?P<id>[1-9]\d*)/' => 'PhabricatorQualityRuleArchiveController',
        ),
      ),
    );
  }

  protected function getCustomCapabilities() {
    return array(
      PhabricatorQualityRuleCreateCapability::CAPABILITY => array(
        'default' => PhabricatorPolicies::POLICY_ADMIN,
      ),
    );
  }

}
