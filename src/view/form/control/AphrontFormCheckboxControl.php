<?php

final class AphrontFormCheckboxControl extends AphrontFormControl {

  private $boxes = array();
  private $checkboxKey;

  public function setCheckboxKey($checkbox_key) {
    $this->checkboxKey = $checkbox_key;
    return $this;
  }

  public function getCheckboxKey() {
    return $this->checkboxKey;
  }

  public function __construct() {
    $this->setValue(array());
  }

  public function addCheckbox(
    $name,
    $value,
    $label,
    $checked = false,
    $id = null) {

    $this->boxes[] = array(
      'name'    => $name,
      'value'   => $value,
      'label'   => $label,
      'checked' => $checked,
      'id'      => $id,
    );

    if (!$checked) {
      return $this;
    }

    $combined_value = $this->getValue();

    if ($this->isMultiple($name)) {
      if (!isset($combined_value[$name])) {
        $combined_value[$name] = array();
      }

      $combined_value[$name][] = $value;
    } else {
      $combined_value[$name] = $value;
    }

    $this->setValue($combined_value);

    return $this;
  }

  private function isMultiple($checkbox_name) {
    return substr($checkbox_name, -2) == '[]';
  }

  public function readValueFromRequest(AphrontRequest $request) {
    $combined_value = array();

    foreach ($this->boxes as $box) {
      $checkbox_name = $box['name'];

      if ($this->isMultiple($checkbox_name)) {
        $submitted_value = $request->getArr($checkbox_name);

        if ($submitted_value) {
          $combined_value[$checkbox_name] = $submitted_value;
        }
      } else {
        $submitted_value = $request->getStr($checkbox_name);

        if (isset($submitted_value)) {
          $combined_value[$checkbox_name] = $submitted_value;
        }
      }
    }

    $this->setValue($combined_value);

    return $this;
  }

  public function setValue($value) {
    if (!is_array($value)) {
      // To compensate for "PhabricatorBoolEditField" field in checkbox mode.
      $value = array($this->boxes[0]['name'] => $value);

      //$error_msg = 'Value must be associative array of selected checkboxes';
      //throw new Exception($error_msg);
    }

    return parent::setValue($value);
  }

  protected function getCustomControlClass() {
    return 'aphront-form-control-checkbox';
  }

  public function setOptions(array $options) {
    $boxes = array();
    foreach ($options as $key => $value) {
      $boxes[] = array(
        'value' => $key,
        'label' => $value,
      );
    }

    $this->boxes = $boxes;

    return $this;
  }

  protected function renderInput() {
    $rows = array();
    $combined_value = $this->getValue();

    foreach ($this->boxes as $box) {
      $id = idx($box, 'id');
      if ($id === null) {
        $id = celerity_generate_unique_node_id();
      }

      if (idx($box, 'name') !== null) {
        // Alex version.
        $checkbox_name = $box['name'];
        $checkbox_value = (string)$box['value'];

        $is_checked = $box['checked'];

        if ($this->isMultiple($checkbox_name)) {
          if (isset($combined_value[$checkbox_name])) {
            $is_checked = in_array(
              $checkbox_value,
              $combined_value[$checkbox_name],
              true
            );
          }
        } else {
          if (isset($combined_value[$checkbox_name])) {
            $is_checked =
              (string)$combined_value[$checkbox_name] === $checkbox_value;
          }
        }
      } else {
        // Evan version.
        $checkbox_name = $this->getName().'[]';

        if (array_key_exists('checked', $box)) {
          $is_checked = $box['checked'];
        } else {
          $is_checked = in_array($box['value'], $this->getValue());
        }
      }

      $checkbox = phutil_tag(
        'input',
        array(
          'id' => $id,
          'type' => 'checkbox',
          'name' => $checkbox_name,
          'value' => $box['value'],
          'checked' => $is_checked ? 'checked' : null,
          'disabled' => $this->getDisabled() ? 'disabled' : null,
        ));
      $label = phutil_tag(
        'label',
        array(
          'for' => $id,
        ),
        $box['label']);
      $rows[] = phutil_tag('tr', array(), array(
        phutil_tag('td', array(), $checkbox),
        phutil_tag('th', array(), $label),
      ));
    }

    // When a user submits a form with a checkbox unchecked, the browser
    // doesn't submit anything to the server. This hidden key lets the server
    // know that the checkboxes were present on the client, the user just did
    // not select any of them.

    $checkbox_key = $this->getCheckboxKey();
    if ($checkbox_key) {
      $rows[] = phutil_tag(
        'input',
        array(
          'type' => 'hidden',
          'name' => $checkbox_key,
          'value' => 1,
        ));
    }

    return phutil_tag(
      'table',
      array('class' => 'aphront-form-control-checkbox-layout'),
      $rows);
  }

}
