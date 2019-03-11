<?php

final class PhabricatorQualityRuleSearchEngine
  extends PhabricatorApplicationSearchEngine {

  public function getResultTypeDescription() {
    return pht('Quality Rules');
  }

  public function getApplicationClassName() {
    return 'PhabricatorQualityApplication';
  }

  public function newQuery() {
    return new PhabricatorQualityRuleQuery();
  }

  protected function shouldShowOrderField() {
    return true;
  }

  protected function buildCustomSearchFields() {
    return array(
      id(new PhabricatorSearchDatasourceField())
        ->setLabel(pht('Created By'))
        ->setKey('authorPHIDs')
        ->setDatasource(new PhabricatorPeopleUserFunctionDatasource()),
      id(new PhabricatorSearchTextField())
        ->setLabel(pht('Name Contains'))
        ->setKey('name')
        ->setDescription(pht('Search for Quality Rules by name substring.')),
      id(new PhabricatorSearchCheckboxesField())
        ->setKey('statuses')
        ->setLabel(pht('Status'))
        ->setDescription(
          pht('Search for archived or active quality rules.'))
        ->setOptions(
          id(new PhabricatorQualityRule())
            ->getStatusNameMap()),
    );
  }

  protected function buildQueryFromParameters(array $map) {
    $query = $this->newQuery();

    if ($map['authorPHIDs']) {
      $query->withAuthorPHIDs($map['authorPHIDs']);
    }

    if ($map['name'] !== null) {
      $query->withNameNgrams($map['name']);
    }

    if ($map['statuses']) {
      $query->withStatuses($map['statuses']);
    }

    return $query;
  }

  protected function getURI($path) {
    return '/quality/'.$path;
  }

  protected function getBuiltinQueryNames() {
    $names = array(
      'active' => pht('Active Quality Rules'),
      'all' => pht('All Quality Rules'),
    );

    if ($this->requireViewer()->isLoggedIn()) {
      $names['authored'] = pht('Authored');
    }

    return $names;
  }

  public function buildSavedQueryFromBuiltin($query_key) {
    $query = $this->newSavedQuery();
    $query->setQueryKey($query_key);
    $viewer = $this->requireViewer();

    switch ($query_key) {
      case 'active':
        return $query->setParameter(
          'statuses',
          array(
            PhabricatorQualityRule::STATUS_ACTIVE,
          ));
      case 'authored':
        return $query->setParameter('authorPHIDs', array($viewer->getPHID()));
      case 'all':
        return $query;
    }

    return parent::buildSavedQueryFromBuiltin($query_key);
  }

  protected function renderResultList(
    array $quality_rules,
    PhabricatorSavedQuery $query,
    array $handles) {

    assert_instances_of($quality_rules, 'PhabricatorQualityRule');
    $viewer = $this->requireViewer();
    $list = new PHUIObjectItemListView();
    $handles = $viewer->loadHandles(mpull($quality_rules, 'getAuthorPHID'));

    foreach ($quality_rules as $rule) {
      $name = $rule->getName();

      $item = id(new PHUIObjectItemView())
        ->setUser($viewer)
        ->setObject($rule)
        ->setObjectName('QR'.$rule->getID())
        ->setHeader($name)
        ->setHref('/QR'.$rule->getID());

      if ($rule->isArchived()) {
        $item->setDisabled(true);
      }

      $list->addItem($item);
    }

    $result = new PhabricatorApplicationSearchResultView();
    $result->setObjectList($list);
    $result->setNoDataString(pht('No Quality Rules found.'));

    return $result;
  }

  protected function getNewUserBody() {
    $create_uri = id(new PhabricatorQualityRuleEditEngine())
      ->getEditURI();

    $create_button = id(new PHUIButtonView())
      ->setTag('a')
      ->setText(pht('Create a Quality Rule'))
      ->setHref($create_uri)
      ->setColor(PHUIButtonView::GREEN);

    $icon = $this->getApplication()->getIcon();
    $app_name =  $this->getApplication()->getName();
    $view = id(new PHUIBigInfoView())
      ->setIcon($icon)
      ->setTitle(pht('Welcome to %s', $app_name))
      ->setDescription(
        pht('Create quality rules.'))
      ->addAction($create_button);

      return $view;
  }
}
