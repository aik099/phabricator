<?php

final class ManiphestCreateMailReceiver extends PhabricatorMailReceiver {

  public function isEnabled() {
    $app_class = 'PhabricatorManiphestApplication';
    return PhabricatorApplication::isClassInstalled($app_class);
  }

  public function canAcceptMail(PhabricatorMetaMTAReceivedMail $mail) {
    $maniphest_app = new PhabricatorManiphestApplication();
    $application_emails = id(new PhabricatorMetaMTAApplicationEmailQuery())
      ->setViewer($this->getViewer())
      ->withApplicationPHIDs(array($maniphest_app->getPHID()))
      ->execute();

    foreach ($mail->getToAddresses() as $to_address) {
      foreach ($application_emails as $application_email) {
        $create_address = $application_email->getAddress();
        if ($this->matchAddresses($create_address, $to_address)) {
          return true;
        }
      }
    }

    return false;
  }

  public function loadSender(PhabricatorMetaMTAReceivedMail $mail) {
    try {
      // Try to load the sender normally.
      return parent::loadSender($mail);
    } catch (PhabricatorMetaMTAReceivedMailProcessingException $ex) {

      // If we failed to load the sender normally, use this special legacy
      // black magic.

      // TODO: Deprecate and remove this.

      $default_author_key = 'metamta.maniphest.default-public-author';
      $default_author = PhabricatorEnv::getEnvConfig($default_author_key);

      if (!strlen($default_author)) {
        throw $ex;
      }

      $user = id(new PhabricatorUser())->loadOneWhere(
        'username = %s',
        $default_author);

      if ($user) {
        return $user;
      }

      throw new PhabricatorMetaMTAReceivedMailProcessingException(
        MetaMTAReceivedMailStatus::STATUS_UNKNOWN_SENDER,
        pht(
          "Phabricator is misconfigured, the configuration key ".
          "'metamta.maniphest.default-public-author' is set to user ".
          "'%s' but that user does not exist.",
          $default_author));
    }
  }

  protected function processReceivedMail(
    PhabricatorMetaMTAReceivedMail $mail,
    PhabricatorUser $sender) {

    $task = ManiphestTask::initializeNewTask($sender);
    $task->setOriginalEmailSource($mail->getHeader('From'));

    $handler = PhabricatorEnv::newObjectFromConfig(
      'metamta.maniphest.reply-handler');
    $handler->setMailReceiver($task);

    $handler->setActor($sender);
    $handler->setExcludeMailRecipientPHIDs(
      $mail->loadExcludeMailRecipientPHIDs());
    $handler->processEmail($mail);

    $mail->setRelatedPHID($task->getPHID());
  }

}
