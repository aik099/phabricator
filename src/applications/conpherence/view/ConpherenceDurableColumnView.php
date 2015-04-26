<?php

final class ConpherenceDurableColumnView extends AphrontTagView {

  private $conpherences = array();
  private $draft;
  private $selectedConpherence;
  private $transactions;
  private $visible;
  private $initialLoad = false;
  private $policyObjects;
  private $quicksandConfig = array();

  public function setConpherences(array $conpherences) {
    assert_instances_of($conpherences, 'ConpherenceThread');
    $this->conpherences = $conpherences;
    return $this;
  }

  public function getConpherences() {
    return $this->conpherences;
  }

  public function setDraft(PhabricatorDraft $draft) {
    $this->draft = $draft;
    return $this;
  }

  public function getDraft() {
    return $this->draft;
  }

  public function setSelectedConpherence(
    ConpherenceThread $conpherence = null) {
    $this->selectedConpherence = $conpherence;
    return $this;
  }

  public function getSelectedConpherence() {
    return $this->selectedConpherence;
  }

  public function setTransactions(array $transactions) {
    assert_instances_of($transactions, 'ConpherenceTransaction');
    $this->transactions = $transactions;
    return $this;
  }

  public function getTransactions() {
    return $this->transactions;
  }

  public function setVisible($visible) {
    $this->visible = $visible;
    return $this;
  }

  public function getVisible() {
    return $this->visible;
  }

  public function setInitialLoad($bool) {
    $this->initialLoad = $bool;
    return $this;
  }

  public function getInitialLoad() {
    return $this->initialLoad;
  }

  public function setPolicyObjects(array $objects) {
    assert_instances_of($objects, 'PhabricatorPolicy');

    $this->policyObjects = $objects;
    return $this;
  }

  public function getPolicyObjects() {
    return $this->policyObjects;
  }

  public function setQuicksandConfig(array $config) {
    $this->quicksandConfig = $config;
    return $this;
  }

  public function getQuicksandConfig() {
    return $this->quicksandConfig;
  }

  protected function getTagAttributes() {
    if ($this->getVisible()) {
      $style = null;
    } else {
      $style = 'display: none;';
    }
    $classes = array('conpherence-durable-column');
    if ($this->getInitialLoad()) {
      $classes[] = 'loading';
    }

    return array(
      'id' => 'conpherence-durable-column',
      'class' => implode(' ', $classes),
      'style' => $style,
      'sigil' => 'conpherence-durable-column',
    );
  }

  protected function getTagContent() {
    $column_key = PhabricatorUserPreferences::PREFERENCE_CONPHERENCE_COLUMN;
    require_celerity_resource('font-source-sans-pro');

    Javelin::initBehavior(
      'durable-column',
      array(
        'visible' => $this->getVisible(),
        'settingsURI' => '/settings/adjust/?key='.$column_key,
        'quicksandConfig' => $this->getQuicksandConfig(),
      ));

    $policies = array();
    $conpherences = $this->getConpherences();
    foreach ($conpherences as $conpherence) {
      if (!$conpherence->getIsRoom()) {
        continue;
      }
      $policies[] = $conpherence->getViewPolicy();
    }
    $policy_objects = array();
    if ($policies) {
      $policy_objects = id(new PhabricatorPolicyQuery())
        ->setViewer($this->getUser())
        ->withPHIDs($policies)
        ->execute();
    }
    $this->setPolicyObjects($policy_objects);

    $classes = array();
    $classes[] = 'conpherence-durable-column-header';
    $classes[] = 'sprite-main-header';
    $classes[] = 'main-header-'.PhabricatorEnv::getEnvConfig('ui.header-color');

    $loading_mask = phutil_tag(
      'div',
      array(
        'class' => 'loading-mask',
      ),
      '');

    $header = phutil_tag(
      'div',
      array(
        'class' => implode(' ', $classes),
      ),
      $this->buildHeader());
    $icon_bar = phutil_tag(
      'div',
      array(
        'class' => 'conpherence-durable-column-icon-bar',
      ),
      $this->buildIconBar());

    $transactions = $this->buildTransactions();

    $content = javelin_tag(
      'div',
      array(
        'class' => 'conpherence-durable-column-main',
        'sigil' => 'conpherence-durable-column-main',
      ),
      phutil_tag(
        'div',
        array(
          'id' => 'conpherence-durable-column-content',
          'class' => 'conpherence-durable-column-frame',
        ),
        javelin_tag(
          'div',
          array(
            'class' => 'conpherence-durable-column-transactions',
            'sigil' => 'conpherence-durable-column-transactions',
          ),
          $transactions)));

    $input = $this->buildTextInput();

    $footer = phutil_tag(
      'div',
      array(
        'class' => 'conpherence-durable-column-footer',
      ),
      array(
        $this->buildSendButton(),
        phutil_tag(
          'div',
          array(
            'class' => 'conpherence-durable-column-status',
          ),
          $this->buildStatusText()),
      ));

    return array(
      $loading_mask,
      $header,
      javelin_tag(
        'div',
        array(
          'class' => 'conpherence-durable-column-body',
          'sigil' => 'conpherence-durable-column-body',
        ),
        array(
          $icon_bar,
          $content,
          $input,
          $footer,
        )),
    );
  }

  private function getPolicyIcon(
    ConpherenceThread $conpherence,
    array $policy_objects) {

    assert_instances_of($policy_objects, 'PhabricatorPolicy');

    $icon = null;
    if ($conpherence->getIsRoom()) {
      $icon = $conpherence->getPolicyIconName($policy_objects);
      $icon = id(new PHUIIconView())
        ->addClass('mmr')
        ->setIconFont($icon);
    }
    return $icon;
  }

  private function buildIconBar() {
    $icons = array();
    $selected_conpherence = $this->getSelectedConpherence();
    $conpherences = $this->getConpherences();

    foreach ($conpherences as $conpherence) {
      $classes = array('conpherence-durable-column-thread-icon');
      if ($selected_conpherence->getID() == $conpherence->getID()) {
        $classes[] = 'selected';
      }
      $data = $conpherence->getDisplayData($this->getUser());
      $icon = $this->getPolicyIcon($conpherence, $this->getPolicyObjects());
      $thread_title = phutil_tag(
        'span',
        array(),
        array(
          $icon,
          $data['js_title'],
        ));
      $image = $data['image'];
      Javelin::initBehavior('phabricator-tooltips');
      $icons[] =
        javelin_tag(
          'a',
          array(
            'href' => '/conpherence/columnview/',
            'class' => implode(' ', $classes),
            'sigil' => 'conpherence-durable-column-thread-icon has-tooltip',
            'meta' => array(
              'threadID' => $conpherence->getID(),
              'threadTitle' => hsprintf('%s', $thread_title),
              'tip' => $data['js_title'],
              'align' => 'S',
            ),
          ),
          phutil_tag(
            'span',
            array(
              'style' => 'background-image: url('.$image.')',
            ),
            ''));
    }
    $icons[] = $this->buildSearchButton();

    return $icons;
  }

  private function buildSearchButton() {
    return phutil_tag(
      'div',
      array(
        'class' => 'conpherence-durable-column-search-button',
      ),
      id(new PHUIButtonBarView())
      ->addButton(
        id(new PHUIButtonView())
        ->setTag('a')
        ->setHref('/conpherence/search/')
        ->setColor(PHUIButtonView::GREY)
        ->setIcon(
          id(new PHUIIconView())
          ->setIconFont('fa-search'))));
  }

  private function buildHeader() {
    $conpherence = $this->getSelectedConpherence();

    if (!$conpherence) {

      $header = null;
      $settings_button = null;
      $settings_menu = null;

    } else {

      $bubble_id = celerity_generate_unique_node_id();
      $dropdown_id = celerity_generate_unique_node_id();

      $settings_list = new PHUIListView();
      $header_actions = $this->getHeaderActionsConfig($conpherence);
      foreach ($header_actions as $action) {
        $settings_list->addMenuItem(
          id(new PHUIListItemView())
          ->setHref($action['href'])
          ->setName($action['name'])
          ->setIcon($action['icon'])
          ->setDisabled($action['disabled'])
          ->addSigil('conpherence-durable-column-header-action')
          ->setMetadata(array(
            'action' => $action['key'],
          )));
      }

      $settings_menu = phutil_tag(
        'div',
        array(
          'id' => $dropdown_id,
          'class' => 'phabricator-main-menu-dropdown phui-list-sidenav '.
          'conpherence-settings-dropdown',
          'sigil' => 'phabricator-notification-menu',
          'style' => 'display: none',
        ),
        $settings_list);

      Javelin::initBehavior(
        'aphlict-dropdown',
        array(
          'bubbleID' => $bubble_id,
          'dropdownID' => $dropdown_id,
          'local' => true,
          'containerDivID' => 'conpherence-durable-column',
        ));

      $item = id(new PHUIListItemView())
        ->setName(pht('Settings'))
        ->setIcon('fa-bars')
        ->addClass('core-menu-item')
        ->addSigil('conpherence-settings-menu')
        ->setID($bubble_id)
        ->setHref('#')
        ->setAural(pht('Settings'))
        ->setOrder(300);
      $settings_button = id(new PHUIListView())
        ->addMenuItem($item)
        ->addClass('phabricator-dark-menu')
        ->addClass('phabricator-application-menu');

      $data = $conpherence->getDisplayData($this->getUser());
      $header = phutil_tag(
        'span',
        array(),
        array(
          $this->getPolicyIcon($conpherence, $this->getPolicyObjects()),
          $data['title'],
        ));
    }

    return
      phutil_tag(
        'div',
        array(
          'class' => 'conpherence-durable-column-header',
        ),
        array(
          javelin_tag(
            'div',
            array(
              'sigil' => 'conpherence-durable-column-header-text',
              'class' => 'conpherence-durable-column-header-text',
            ),
            $header),
          $settings_button,
          $settings_menu,
        ));
  }

  private function getHeaderActionsConfig(ConpherenceThread $conpherence) {
    if ($conpherence->getIsRoom()) {
      $rename_label = pht('Edit Room');
    } else {
      $rename_label = pht('Rename Thread');
    }
    $can_edit = PhabricatorPolicyFilter::hasCapability(
      $this->getUser(),
      $conpherence,
      PhabricatorPolicyCapability::CAN_EDIT);

    return array(
      array(
        'name' => pht('Add Participants'),
        'disabled' => !$can_edit,
        'href' => '/conpherence/update/'.$conpherence->getID().'/',
        'icon' => 'fa-plus',
        'key' => ConpherenceUpdateActions::ADD_PERSON,
      ),
      array(
        'name' => $rename_label,
        'disabled' => !$can_edit,
        'href' => '/conpherence/update/'.$conpherence->getID().'/',
        'icon' => 'fa-pencil',
        'key' => ConpherenceUpdateActions::METADATA,
      ),
      array(
        'name' => pht('View in Conpherence'),
        'disabled' => false,
        'href' => '/'.$conpherence->getMonogram(),
        'icon' => 'fa-comments',
        'key' => 'go_conpherence',
      ),
      array(
        'name' => pht('Hide Column'),
        'disabled' => false,
        'href' => '#',
        'icon' => 'fa-times',
        'key' => 'hide_column',
      ),
    );
  }

  private function buildTransactions() {
    $conpherence = $this->getSelectedConpherence();
    if (!$conpherence) {
      if (!$this->getVisible() || $this->getInitialLoad()) {
        return pht('Loading...');
      }
      return array(
        phutil_tag(
          'div',
          array(
            'class' => 'mmb',
          ),
          pht('You do not have any messages yet.')),
        javelin_tag(
          'a',
          array(
            'href' => '/conpherence/new/',
            'class' => 'button grey',
            'sigil' => 'workflow',
          ),
          pht('Send a Message')),
      );
    }

    $data = ConpherenceTransactionRenderer::renderTransactions(
      $this->getUser(),
      $conpherence,
      $full_display = false);
    $messages = ConpherenceTransactionRenderer::renderMessagePaneContent(
      $data['transactions'],
      $data['oldest_transaction_id']);

    return $messages;
  }

  private function buildTextInput() {
    $conpherence = $this->getSelectedConpherence();
    if (!$conpherence) {
      return null;
    }

    $draft = $this->getDraft();
    $draft_value = null;
    if ($draft) {
      $draft_value = $draft->getDraft();
    }

    $textarea_id = celerity_generate_unique_node_id();
    $textarea = javelin_tag(
      'textarea',
      array(
        'id' => $textarea_id,
        'name' => 'text',
        'class' => 'conpherence-durable-column-textarea',
        'sigil' => 'conpherence-durable-column-textarea',
        'placeholder' => pht('Send a message...'),
      ),
      $draft_value);
    Javelin::initBehavior(
      'aphront-drag-and-drop-textarea',
      array(
        'target'          => $textarea_id,
        'activatedClass'  => 'aphront-textarea-drag-and-drop',
        'uri'             => '/file/dropupload/',
      ));
    $id = $conpherence->getID();
    return phabricator_form(
      $this->getUser(),
      array(
        'method' => 'POST',
        'action' => '/conpherence/update/'.$id.'/',
        'sigil' => 'conpherence-message-form',
      ),
      array(
        $textarea,
        phutil_tag(
        'input',
        array(
          'type' => 'hidden',
          'name' => 'action',
          'value' => ConpherenceUpdateActions::MESSAGE,
        )),
      ));
  }

  private function buildStatusText() {
    return null;
  }

  private function buildSendButton() {
    $conpherence = $this->getSelectedConpherence();
    if (!$conpherence) {
      return null;
    }

    return javelin_tag(
      'button',
      array(
        'class' => 'grey',
        'sigil' => 'conpherence-send-message',
      ),
      pht('Send'));
  }

}
