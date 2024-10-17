<?php

final class PhabricatorHarbormasterConfigOptions
  extends PhabricatorApplicationConfigOptions {

  public function getName() {
    return pht('Harbormaster');
  }

  public function getDescription() {
    return pht('Configure Harbormaster build engine.');
  }

  public function getOptions() {
    return array(
      $this->newOption('jenkins.base-uri', 'string', null)
        ->setDescription(pht('URI where Jenkins is installed.'))
        ->addExample('http://jenkins.example.com/', pht('Valid Setting')),
      $this->newOption('jenkins.user-id', 'string', null)
        ->setDescription(pht('Username for accessing Jenkins.')),
      $this->newOption('jenkins.api-token', 'string', null)
        ->setMasked(true)
        ->setDescription(pht('API token for accessing Jenkins.')),
      $this->newOption('jenkins.repository-uuid', 'string', null)
          ->setDescription(pht('Repository UUID to notify about new commits.')),
    );
  }

}
