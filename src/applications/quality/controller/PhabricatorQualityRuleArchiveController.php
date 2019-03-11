<?php

final class PhabricatorQualityRuleArchiveController
  extends PhabricatorQualityController {

  public function handleRequest(AphrontRequest $request) {
    $viewer = $request->getViewer();
    $id = $request->getURIData('id');

    $rule = id(new PhabricatorQualityRuleQuery())
      ->setViewer($viewer)
      ->withIDs(array($id))
      ->requireCapabilities(
        array(
          PhabricatorPolicyCapability::CAN_VIEW,
          PhabricatorPolicyCapability::CAN_EDIT,
        ))
      ->executeOne();
    if (!$rule) {
      return new Aphront404Response();
    }

    $view_uri = $rule->getURI();

    if ($request->isFormPost()) {
      if ($rule->isArchived()) {
        $new_status = PhabricatorQualityRule::STATUS_ACTIVE;
      } else {
        $new_status = PhabricatorQualityRule::STATUS_ARCHIVED;
      }

      $xactions = array();

      $xactions[] = id(new PhabricatorQualityRuleTransaction())
        ->setTransactionType(PhabricatorQualityRuleStatusTransaction::TRANSACTIONTYPE)
        ->setNewValue($new_status);

      id(new PhabricatorQualityRuleEditor())
        ->setActor($viewer)
        ->setContentSourceFromRequest($request)
        ->setContinueOnNoEffect(true)
        ->setContinueOnMissingFields(true)
        ->applyTransactions($rule, $xactions);

      return id(new AphrontRedirectResponse())->setURI($view_uri);
    }

    if ($rule->isArchived()) {
      $title = pht('Activate Quality Rule');
      $body = pht('This quality rule will become consumable again.');
      $button = pht('Activate Quality Rule');
    } else {
      $title = pht('Archive Quality Rule');
      $body = pht('This quality rule will be marked as expired.');
      $button = pht('Archive Quality Rule');
    }

    return $this->newDialog()
      ->setTitle($title)
      ->appendChild($body)
      ->addCancelButton($view_uri)
      ->addSubmitButton($button);
  }

}
