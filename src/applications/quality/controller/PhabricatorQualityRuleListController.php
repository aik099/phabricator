<?php

final class PhabricatorQualityRuleListController
  extends PhabricatorQualityController {

  public function shouldAllowPublic() {
    return true;
  }

  public function handleRequest(AphrontRequest $request) {
    $engine = new PhabricatorQualityRuleSearchEngine();
    $controller = id(new PhabricatorApplicationSearchController())
      ->setQueryKey($request->getURIData('queryKey'))
      ->setSearchEngine($engine)
      ->setNavigation($this->buildSideNav());
    return $this->delegateToController($controller);
  }

  public function buildSideNav() {
    $user = $this->getRequest()->getUser();

    $nav = new AphrontSideNavFilterView();
    $nav->setBaseURI(new PhutilURI($this->getApplicationURI()));

    id(new PhabricatorQualityRuleSearchEngine())
      ->setViewer($user)
      ->addNavigationItems($nav->getMenu());

    $nav->selectFilter(null);

    return $nav;
  }

}
