<?php

final class PhabricatorQualityRuleCreateCapability
  extends PhabricatorPolicyCapability {

  const CAPABILITY = 'quality.rule.create';

  public function getCapabilityName() {
    return pht('Can Create Quality Rules');
  }

  public function describeCapabilityRejection() {
    return pht('You do not have permission to create a Quality Rule.');
  }

}
