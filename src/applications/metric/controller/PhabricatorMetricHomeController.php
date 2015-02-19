<?php

final class PhabricatorMetricHomeController extends PhabricatorMetricController {

  /**
   * Report form controls.
   *
   * @var AphrontFormControl[]
   */
  private $formControls = array();

  /**
   * Metric engines.
   *
   * @var PhabricatorMetricEngine[]
   */
  private $engines = array();

  public function shouldAllowPublic() {
    return true;
  }

  public function processRequest() {
    /*if ($request->isFormPost()) {
      $uri = new PhutilURI('/metric/chart/');
      $uri->setQueryParam('y1', $request->getStr('y1'));
      return id(new AphrontRedirectResponse())->setURI($uri);
    }

    $types = array(
      '+N:*',
      '+N:DREV',
      'updated',
    );

    $engines = PhabricatorFactEngine::loadAllEngines();
    $specs = PhabricatorFactSpec::newSpecsForFactTypes($engines, $types);*/

    $request = $this->getRequest();

    $this->engines = PhabricatorMetricEngine::loadAllEngines($this->getViewer());
    $this->formControls = $this->getReportFormControls($request);

    $engine = $this->getEngine();

    if (!is_object($engine)) {
      return new Aphront404Response();
    }

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addTextCrumb(pht('Home'));

    $filter = new AphrontListFilterView();
    $filter->appendChild($this->createReportForm($request));

    return $this->newPage()
      ->setTitle('Metrics')
      ->setCrumbs($crumbs)
      ->appendChild(array(
        $filter,
        $this->getChart($engine),
      ));
  }

  private function getReportFormControls(AphrontRequest $request) {
    $user = $request->getUser();

    $granularity_options = array(
      MetricReportParam::GRANULARITY_DAY => pht('1 Day'),
      MetricReportParam::GRANULARITY_WEEK => pht('1 Week'),
      MetricReportParam::GRANULARITY_MONTH => pht('1 Month'),
      MetricReportParam::GRANULARITY_YEAR => pht('1 Year'),
    );

    $breakdown_options = array(
      MetricReportParam::BREAKDOWN_NONE => pht('None'),
      MetricReportParam::BREAKDOWN_USERS => pht('By Users'),
      MetricReportParam::BREAKDOWN_REPOSITORIES => pht('By Repositories'),
    );

    /** @var AphrontFormControl[] $controls */
    $controls = array(
      'granularity' => id(new AphrontFormSelectControl())
        ->setLabel(pht('Granularity'))
        ->setName('granularity')
        ->setOptions($granularity_options),

      'breakdown' => id(new AphrontFormSelectControl())
        ->setLabel(pht('Breakdown'))
        ->setName('breakdown')
        ->setOptions($breakdown_options),

      'period_start' => id(new AphrontFormDateControl())
        ->setUser($user)
        ->setLabel(pht('Period Start'))
        ->setName('period_start'),

      'period_end' => id(new AphrontFormDateControl())
        ->setUser($user)
        ->setLabel(pht('Period End'))
        ->setName('period_end'),
    );

    $controls = array_merge($controls, $this->getTypeaheadControls(
      'users',
      new PhabricatorPeopleDatasource()));

    $controls = array_merge($controls, $this->getTypeaheadControls(
      'repositories',
      new DiffusionRepositoryDatasource()));

    if ($this->supportsQualityRules()) {
      $controls = array_merge($controls, $this->getTypeaheadControls(
        'quality_rules',
        new PhabricatorQualityRuleDatasource()));
    }

    $controls['report_type'] = id(new AphrontFormSelectControl())
      ->setLabel(pht('Report Type'))
      ->setName('report_type')
      ->setMultiple(true)
      ->setOptions($this->getReportTypes());

    // Set form defaults.
    foreach ($this->getReportFormDefaults($user) as $control_name => $default) {
      $controls[$control_name]->setValue($default);
    }

    // Read from request.
    $granularity = $request->getInt('granularity');

    if (isset($granularity)) {
      foreach ($controls as $control) {
        $control->readValueFromRequest($request);
      }
    }

    return $controls;
  }

  private function getReportTypes($default_only = false) {
    $ret = array();

    foreach ($this->engines as $key => $engine) {
      if ($default_only && !$engine->isDefault()) {
        continue;
      }

      $ret[$key] = $engine->getReportName();

      if ($engine->isDeveloperOnly()) {
        $ret[$key] = '* '.$ret[$key];
      }
    }

    return $ret;
  }

  private function getTypeaheadControls($name,
                                        PhabricatorTypeaheadDatasource $datasource) {

    $label = implode(' ', array_map('ucfirst', explode('_', $name)));

    return array(
      $name => id(new AphrontFormTokenizerControl())
        ->setDatasource($datasource)
        ->setName($name)
        ->setLabel(pht($label)),

      $name.'_checkboxes' => id(new AphrontFormCheckboxControl())
        ->addCheckbox(
          'exclude_selected_'.$name,
          1,
          pht('Exclude selected '.str_replace('_', ' ', $name)))
    );
  }

  private function getReportFormDefaults(PhabricatorUser $user) {
    $period_start = mktime(0, 0, 0, date('m'), 1, date('Y'));
    $period_end = strtotime('+1 month -1 second', $period_start);

    $period_start = $this->getDateInUserTimezone($period_start, $user);
    $period_end = $this->getDateInUserTimezone($period_end, $user);

    $defaults = array(
      'granularity' => MetricReportParam::GRANULARITY_DAY,
      'breakdown' => MetricReportParam::BREAKDOWN_NONE,
      'period_start' => $period_start,
      'period_end' => $period_end,
      'users' => array($user->getPHID()),
      'users_checkboxes' => array('exclude_selected_users' => 0),
      'repositories' => array(),
      'repositories_checkboxes' => array('exclude_selected_repositories' => 0),
      'report_type' => array_keys($this->getReportTypes(true)),
    );

    if ($this->supportsQualityRules()) {
      $defaults['quality_rules'] = array();
      $defaults['quality_rules_checkboxes'] = array('exclude_selected_quality_rules' => 0);
    }

    return $defaults;
  }

  private function getDateInUserTimezone($date, PhabricatorUser $user) {
    $timezone = new DateTimeZone($user->getTimezoneIdentifier());

    $date_time = new DateTime(date('Y-m-d H:i:s', $date), $timezone);

    return $date_time->format('U');
  }

  private function createReportForm(AphrontRequest $request) {
    $user = $request->getUser();

    $form = id(new AphrontFormView())
      ->setUser($user)
      ->setMethod('GET');

    foreach ($this->formControls as $control) {
      $form->appendControl($control);
    }

    $form
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->setValue(pht('Execute Query')));

    return $form;
  }

  private function getChart(PhabricatorMetricEngine $engine) {
    if (!$this->validateForm()) {
      return null;
    }

    $view = new MetricChartView();
    $view->setChartData($engine->getChartParams($this->getReportParams()));

    return $view;
  }

  private function getEngine() {
    $selected_report_types = $this->getReportParam('report_type');
    $engine_class = reset($selected_report_types);

    if (!array_key_exists($engine_class, $this->engines)) {
      return null;
    }

    return $this->engines[$engine_class];
  }

  private function validateForm() {
    $is_valid = true;

    foreach ($this->formControls as $field => $control) {
      if ($field == 'period_start' || $field == 'period_end') {
        $value = $this->getReportParam($field);

        if (is_null($value)) {
          $is_valid = false;
          $control->setError('Invalid Format');
        }
      }
    }

    if ($this->getReportParam('period_end') < $this->getReportParam('period_start')) {
      $is_valid = false;
      $this->formControls['period_end']->setError('Must be after "Period Start"');
    }

    if (!$this->getReportParam('report_type')) {
      $is_valid = false;
      $this->formControls['report_type']->setError('Required');
    }

    $users = $this->getReportParam('users');
    $users_checkboxes = $this->getReportParam('users_checkboxes');
    $exclude_users = array_key_exists(
      'exclude_selected_users',
      $users_checkboxes
    ) && $users_checkboxes['exclude_selected_users'];

    if ($exclude_users && !$users) {
      $is_valid = false;
      $this->formControls['users']->setError('Required');
    }

    $repositories = $this->getReportParam('repositories');
    $repositories_checkboxes = $this->getReportParam('repositories_checkboxes');
    $exclude_repositories = array_key_exists(
      'exclude_selected_repositories',
      $repositories_checkboxes
    ) && $repositories_checkboxes['exclude_selected_repositories'];

    if ($exclude_repositories && !$repositories) {
      $is_valid = false;
      $this->formControls['repositories']->setError('Required');
    }

    if ($this->supportsQualityRules()) {
      $quality_rules = $this->getReportParam('quality_rules');
      $quality_rules_checkboxes = $this->getReportParam('quality_rules_checkboxes');
      $exclude_quality_rules = array_key_exists(
          'exclude_selected_quality_rules',
          $quality_rules_checkboxes
        ) && $quality_rules_checkboxes['exclude_selected_quality_rules'];

      if ($exclude_quality_rules && !$quality_rules) {
        $is_valid = false;
        $this->formControls['quality_rules']->setError('Required');
      }
    }

    return $is_valid;
  }

  private function getReportParam($name) {
    return $this->formControls[$name]->getValue();
  }

  private function getReportParams() {
    $ret = array();

    foreach (array_keys($this->formControls) as $field) {
      $ret[$field] = $this->getReportParam($field);
    }

    return $ret;
  }

  protected function supportsQualityRules() {
    foreach ($this->engines as $engine) {
      if ($engine instanceof PhabricatorMetricQualityRuleCommitsEngine) {
        return true;
      }
    }

    return false;
  }

}
