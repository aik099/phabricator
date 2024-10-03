<?php

/**
 * Publishes feed stories into JIRA, using the "JIRA Issues" field to identify
 * linked issues.
 */
final class DoorkeeperJIRAFeedWorker extends DoorkeeperFeedWorker {

  private $provider;


/* -(  Publishing Stories  )------------------------------------------------- */


  /**
   * This worker is enabled when a JIRA authentication provider is active.
   */
  public function isEnabled() {
    return (bool)PhabricatorJIRAAuthProvider::getJIRAProvider();
  }


  /**
   * Publishes stories into JIRA using the JIRA API.
   */
  protected function publishFeedStory() {
    $story = $this->getFeedStory();
    $viewer = $this->getViewer();
    $provider = $this->getProvider();
    $object = $this->getStoryObject();
    $publisher = $this->getPublisher();

    $jira_issue_phids = PhabricatorEdgeQuery::loadDestinationPHIDs(
      $object->getPHID(),
      PhabricatorJiraIssueHasObjectEdgeType::EDGECONST);
    if (!$jira_issue_phids) {
      $this->log(
        "%s\n",
        pht('Story is about an object with no linked JIRA issues.'));
      return;
    }

    $xobjs = id(new DoorkeeperExternalObjectQuery())
      ->setViewer($viewer)
      ->withPHIDs($jira_issue_phids)
      ->execute();

    if (!$xobjs) {
      $this->log(
        "%s\n",
        pht('Story object has no corresponding external JIRA objects.'));
      return;
    }

    $try_users = $this->findUsersToPossess();
    if (!$try_users) {
      $this->log(
        "%s\n",
        pht('No users to act on linked JIRA objects.'));
      return;
    }

    $story_text = $this->renderStoryText();

    $xobjs = mgroup($xobjs, 'getApplicationDomain');
    foreach ($xobjs as $domain => $xobj_list) {
      $accounts = id(new PhabricatorExternalAccountQuery())
        ->setViewer($viewer)
        ->withUserPHIDs($try_users)
        ->withAccountTypes(array($provider->getProviderType()))
        ->withAccountDomains(array($domain))
        ->requireCapabilities(
          array(
            PhabricatorPolicyCapability::CAN_VIEW,
            PhabricatorPolicyCapability::CAN_EDIT,
          ))
        ->execute();
      // Reorder accounts in the original order.
      // TODO: This needs to be adjusted if/when we allow you to link multiple
      // accounts.
      $accounts = mpull($accounts, null, 'getUserPHID');
      $accounts = array_select_keys($accounts, $try_users);

      foreach ($xobj_list as $xobj) {
        foreach ($accounts as $account) {
          $this->postLink($account, $xobj);
          $this->addComment($account, $xobj, $story_text);
          $this->transitionIssue($accounts, $account, $xobj);
          break;
        }
      }
    }
  }

  private function postLink(
    PhabricatorExternalAccount $account,
    DoorkeeperExternalObject $xobj) {

    if (!$this->shouldPostLink()) {
      return;
    }

    $object = $this->getStoryObject();
    $publisher = $this->getPublisher();
    $base_uri = PhabricatorEnv::getEnvConfig('phabricator.base-uri');

    preg_match(
      '/^(.*?): (.*)$/',
      $publisher->getObjectTitle($object),
      $regs);

    $icon_uri = celerity_get_resource_uri('rsrc/favicons/favicon-16x16.png');

    $post_data = array(
      'globalId' => 'appId=ph_'.crc32($base_uri).'&phid='.$object->getPHID(),
      'application' => array(
        'type' => 'com.phacility.phabricator',
        'name' => 'Phabricator',
      ),
      'relationship' => 'implemented in',
      'object' => array(
        'url' => $publisher->getObjectURI($object),
        'title' => $regs[1], // Object identifier (e.g. D3).
        'summary' => $regs[2], // Object title.
        'icon' => array(
          'url16x16' => $icon_uri,
          'title' => 'Phabricator',
        ),
        'status' => array(
          'resolved' => $publisher->isObjectClosed($object),
        ),
      ),
    );

    try {
      $this->getProvider()->newJIRAFuture(
        $account,
        'rest/api/2/issue/'.$xobj->getObjectID().'/remotelink',
        'POST',
        $post_data)->resolveJSON();
    } catch (HTTPFutureResponseStatus $ex) {
      phlog($ex);
      $this->log(
        "Failed to create remote link on '%s' JIRA issue.\n",
        $xobj->getObjectID());
    }
  }

  private function addComment(
    PhabricatorExternalAccount $account,
    DoorkeeperExternalObject $xobj,
    $story_text) {

    if (!$this->shouldPostComment()) {
      return;
    }

    try {
      $this->getProvider()->newJIRAFuture(
        $account,
        'rest/api/2/issue/'.$xobj->getObjectID().'/comment',
        'POST',
        array(
          'body' => $story_text,
        ))->resolveJSON();
    } catch (HTTPFutureResponseStatus $ex) {
      phlog($ex);
      $this->log(
        "Failed to add comment to '%s' JIRA issue.\n",
        $xobj->getObjectID());
    }
  }

  private function transitionIssue(
    array $accounts,
    PhabricatorExternalAccount $account,
    DoorkeeperExternalObject $xobj) {

    $provider = $this->getProvider();
    $provider_config = $provider->getProviderConfig();
    $object = $this->getStoryObject();
    $publisher = $this->getPublisher();

    $review_transition_name = $provider_config->getProperty(PhabricatorJIRAAuthProvider::PROPERTY_JIRA_REVIEW_TRANSITION);
    $accept_transition_name = $provider_config->getProperty(PhabricatorJIRAAuthProvider::PROPERTY_JIRA_ACCEPT_TRANSITION);
    $reject_transition_name = $provider_config->getProperty(PhabricatorJIRAAuthProvider::PROPERTY_JIRA_REJECT_TRANSITION);

    if ($review_transition_name && $publisher->isStoryAboutObjectReview($object)) {
      $reviewer_account = idx($accounts, head($publisher->getActiveUserPHIDs($object)));
      $reviewer_field = $provider_config->getProperty(PhabricatorJIRAAuthProvider::PROPERTY_JIRA_REVIEWER_FIELD);
      $this->executeTransition($account, $xobj, $review_transition_name, array(
        $reviewer_field => $reviewer_account->getAccountID()
      ));
    } elseif ($accept_transition_name && $publisher->isStoryAboutObjectAccept($object)) {
      $this->executeTransition($account, $xobj, $accept_transition_name);
    } elseif ($reject_transition_name && $publisher->isStoryAboutObjectReject($object)) {
      $this->executeTransition($account, $xobj, $reject_transition_name);
    }
  }

  private function executeTransition(
    PhabricatorExternalAccount $account,
    DoorkeeperExternalObject $xobj,
    $transition_name,
    array $fields = array()) {

    $transition_id = $this->getTransitionId($account, $xobj, $transition_name);
    if ($transition_id === false) {
      $this->log('Transition "'.$transition_name.'" not found for "'.$xobj->getObjectID().'" JIRA issue');

      return;
    }

    $post_data = array(
      'transition' => array(
        'id' => $transition_id,
      ),
    );

    if ($fields) {
      $formatted_fields = array();
      foreach ($fields as $field_name => $field_value) {
        // Below code makes assumption, that every given field happens to be user selector.
        $formatted_fields[$field_name] = array(
          // https://developer.atlassian.com/cloud/jira/platform/deprecation-notice-user-privacy-api-migration-guide

          'accountId' => $field_value, // For Jira Cloud (personal data anonymized since 1 Oct 2018).
          'name' => $field_value, // For Jira Self-Hosted.
        );
      }

      $post_data['fields'] = $formatted_fields;
    }

    $provider = $this->getProvider();

    try {
      $provider->newJIRAFuture(
        $account,
        'rest/api/2/issue/'.$xobj->getObjectID().'/transitions',
        'POST',
        $post_data)->resolvex();
    }
    catch (HTTPFutureResponseStatus $ex) {
      phlog($ex);
      $this->log('Failed executing transition "'.$transition_name.'" on "'.$xobj->getObjectID().'" JIRA issue');
    }
  }

  private function getTransitionId(
    PhabricatorExternalAccount $account,
    DoorkeeperExternalObject $xobj,
    $transition_name) {

    $provider = $this->getProvider();

    try {
      $response = $provider->newJIRAFuture(
        $account,
        'rest/api/2/issue/'.$xobj->getObjectID().'/transitions',
        'GET')->resolveJSON();

      foreach ($response['transitions'] as $transition) {
        if ($transition['name'] == $transition_name) {
          return $transition['id'];
        }
      }
    } catch (HTTPFutureResponseStatus $ex) {
      phlog($ex);
      $this->log('Failed to get transitions for "'.$xobj->getObjectID().'" JIRA issue');
    }

    return false;
  }

/* -(  Internals  )---------------------------------------------------------- */


  /**
   * Get the active JIRA provider.
   *
   * @return PhabricatorJIRAAuthProvider Active JIRA auth provider.
   * @task internal
   */
  private function getProvider() {
    if (!$this->provider) {
      $provider = PhabricatorJIRAAuthProvider::getJIRAProvider();
      if (!$provider) {
        throw new PhabricatorWorkerPermanentFailureException(
          pht('No JIRA provider configured.'));
      }
      $this->provider = $provider;
    }
    return $this->provider;
  }


  /**
   * Get a list of users to act as when publishing into JIRA.
   *
   * @return list<phid> Candidate user PHIDs to act as when publishing this
   *                    story.
   * @task internal
   */
  private function findUsersToPossess() {
    $object = $this->getStoryObject();
    $publisher = $this->getPublisher();
    $data = $this->getFeedStory()->getStoryData();

    // Figure out all the users related to the object. Users go into one of
    // four buckets. For JIRA integration, we don't care about which bucket
    // a user is in, since we just want to publish an update to linked objects.

    $owner_phid = $publisher->getOwnerPHID($object);
    $active_phids = $publisher->getActiveUserPHIDs($object);
    $passive_phids = $publisher->getPassiveUserPHIDs($object);
    $follow_phids = $publisher->getCCUserPHIDs($object);

    $all_phids = array_merge(
      array($owner_phid),
      $active_phids,
      $passive_phids,
      $follow_phids);
    $all_phids = array_unique(array_filter($all_phids));

    // Even if the actor isn't a reviewer, etc., try to use their account so
    // we can post in the correct voice. If we miss, we'll try all the other
    // related users.

    $try_users = array_merge(
      array($data->getAuthorPHID()),
      $all_phids);
    $try_users = array_filter($try_users);

    return $try_users;
  }

  private function shouldPostComment() {
    return $this->getProvider()->shouldCreateJIRAComment();
  }

  private function shouldPostLink() {
    return $this->getProvider()->shouldCreateJIRALink();
  }

  private function renderStoryText() {
    $object = $this->getStoryObject();
    $publisher = $this->getPublisher();

    $text = $publisher->getStoryText($object);

    if ($this->shouldPostLink()) {
      return $text;
    } else {
      // include the link in the comment
      return $text."\n\n".$publisher->getObjectURI($object);
    }
  }

}
