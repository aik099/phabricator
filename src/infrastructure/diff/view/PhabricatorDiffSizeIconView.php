<?php

/**
 * Renders the git-style "+++--  " size indicator used for revisions and
 * commits, given raw added/removed line counts.
 */
final class PhabricatorDiffSizeIconView extends AphrontView {

  private $addedLineCount;
  private $removedLineCount;

  public function setAddedLineCount($added_line_count) {
    $this->addedLineCount = $added_line_count;
    return $this;
  }

  public function setRemovedLineCount($removed_line_count) {
    $this->removedLineCount = $removed_line_count;
    return $this;
  }

  public static function getScaleGlyphs($add, $rem) {
    $all = $add + $rem;
    if (!$all) {
      return str_repeat(' ', 7);
    }

    $map = array(
      20 => 2,
      50 => 3,
      150 => 4,
      375 => 5,
      1000 => 6,
      2500 => 7,
    );

    $n = 1;
    foreach ($map as $size => $count) {
      if ($size <= $all) {
        $n = $count;
      } else {
        break;
      }
    }

    $add_n = (int)ceil(($add / $all) * $n);
    $rem_n = (int)ceil(($rem / $all) * $n);
    while ($add_n + $rem_n > $n) {
      if ($add_n > 1) {
        $add_n--;
      } else {
        $rem_n--;
      }
    }

    return
      str_repeat('+', $add_n).
      str_repeat('-', $rem_n).
      str_repeat(' ', 7 - $n);
  }

  public function render() {
    $add = (int)$this->addedLineCount;
    $rem = (int)$this->removedLineCount;

    $glyphs = self::getScaleGlyphs($add, $rem);

    $size = array();
    $plus_count = 0;
    for ($ii = 0; $ii < 7; $ii++) {
      $c = $glyphs[$ii];

      switch ($c) {
        case '+':
          $size[] = id(new PHUIIconView())
            ->setIcon('fa-plus');
          $plus_count++;
          break;
        case '-':
          $size[] = id(new PHUIIconView())
            ->setIcon('fa-minus');
          break;
        default:
          $size[] = id(new PHUIIconView())
            ->setIcon('fa-square-o invisible');
          break;
      }
    }

    $classes = array();
    $classes[] = 'differential-revision-size';

    $tip = array();
    $tip[] = pht('%s Lines', new PhutilNumber($add + $rem));

    if ($plus_count <= 1) {
      $classes[] = 'differential-revision-small';
      $tip[] = pht('Smaller Change');
    }

    if ($plus_count >= 4) {
      $classes[] = 'differential-revision-large';
      $tip[] = pht('Larger Change');
    }

    $tip = phutil_implode_html(" \xC2\xB7 ", $tip);

    return javelin_tag(
      'span',
      array(
        'class' => implode(' ', $classes),
        'sigil' => 'has-tooltip',
        'meta' => array(
          'tip' => $tip,
          'align' => 'E',
          'size' => 400,
        ),
      ),
      $size);
  }

}
