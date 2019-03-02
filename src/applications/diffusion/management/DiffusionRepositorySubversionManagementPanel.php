<?php

final class DiffusionRepositorySubversionManagementPanel
  extends DiffusionRepositoryManagementPanel {

  const PANELKEY = 'subversion';

  public function getManagementPanelLabel() {
    return pht('Subversion');
  }

  public function getManagementPanelOrder() {
    return 1000;
  }

  public function shouldEnableForRepository(
    PhabricatorRepository $repository) {
    return $repository->isSVN();
  }

  public function getManagementPanelIcon() {
    $repository = $this->getRepository();

    $has_any =
      $repository->getDetail('svn-subpath') ||
      $repository->getDetail('svn-layout') != PhabricatorRepository::LAYOUT_NONE ||
      $repository->getDetail('svn-trunk-folder') ||
      $repository->getDetail('svn-branches-folder') ||
      $repository->getDetail('svn-tags-folder');

    if ($has_any) {
      return 'fa-folder';
    } else {
      return 'fa-folder grey';
    }
  }

  protected function getEditEngineFieldKeys() {
    return array(
      'importOnly', 'layout', 'trunk_folder', 'branches_folder', 'tags_folder',
    );
  }

  public function buildManagementPanelCurtain() {
    $repository = $this->getRepository();
    $viewer = $this->getViewer();
    $action_list = $this->newActionList();

    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $viewer,
      $repository,
      PhabricatorPolicyCapability::CAN_EDIT);

    $subversion_uri = $this->getEditPageURI();

    $action_list->addAction(
      id(new PhabricatorActionView())
        ->setIcon('fa-pencil')
        ->setName(pht('Edit Properties'))
        ->setHref($subversion_uri)
        ->setDisabled(!$can_edit)
        ->setWorkflow(!$can_edit));

    return $this->newCurtainView($action_list)
      ->setActionList($action_list);
  }

  public function buildManagementPanelContent() {
    $repository = $this->getRepository();
    $viewer = $this->getViewer();

    $view = id(new PHUIPropertyListView())
      ->setViewer($viewer);

    $default_branch = nonempty(
      $repository->getDetail('svn-subpath'),
      phutil_tag('em', array(), pht('Import Entire Repository')));
    $view->addProperty(pht('Import Only'), $default_branch);

    $layout_folders = array(
      $repository->getSubversionTrunkFolder(),
      $repository->getSubversionBranchesFolder(),
      $repository->getSubversionTagsFolder(),);

    switch ($repository->getSubversionLayout()) {
      case PhabricatorRepository::LAYOUT_NONE:
        $svn_layout = pht('None');
        break;

      case PhabricatorRepository::LAYOUT_STANDARD:
        $svn_layout = 'Standard ('.implode(', ', $layout_folders).')';
        break;

      case PhabricatorRepository::LAYOUT_CUSTOM:
        $svn_layout = 'Custom ('.implode(', ', $layout_folders).')';
        break;

      default:
        throw new Exception('Unknown repository layout: '.
          $repository->getSubversionLayout());
    }

    $svn_layout = phutil_tag('em', array(), $svn_layout);
    $view->addProperty(pht('Layout'), $svn_layout);

    return $this->newBox(pht('Subversion'), $view);
  }

}
