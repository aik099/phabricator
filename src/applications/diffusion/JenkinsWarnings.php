<?php

final class JenkinsWarnings {

  private $apiRequest;

  public function __construct($job_name, $build_number, $api_method) {
    $this->apiRequest = JenkinsAPIRequest::create()
      ->addJob($job_name)
      ->addBuild($build_number)
      ->setSuffix($api_method);
  }

  public function get(array $file_filter) {
    $response     = $this->apiRequest->query();
    $raw_warnings = $response->issues;

    if (!$raw_warnings) {
      return array();
    }

    return $this->filter($this->groupByFile($raw_warnings), $file_filter);
  }

  private function groupByFile(array $raw_warnings) {
    $grouped_warnings = array();

    foreach ($raw_warnings as $raw_warning) {
      $file_name = preg_replace(
        '#.*/jobs/[^\/]*/workspace/(.*)$#',
        '$1',
        $raw_warning->fileName);

      if (!idx($grouped_warnings, $file_name)) {
        $grouped_warnings[$file_name] = array();
      }

      // Decode due https://github.com/squizlabs/PHP_CodeSniffer/issues/315
      $grouped_warnings[$file_name][] = array(
        'line' => $raw_warning->lineStart,
        'message' => $this->decodeMessage($raw_warning->message),
        'priority' => $raw_warning->severity,
      );
    }

    return $grouped_warnings;
  }

  private function decodeMessage($message) {
    // See https://issues.jenkins-ci.org/browse/JENKINS-25511
    $message = str_replace('&apos;', '&#39;', $message);

    return htmlspecialchars_decode($message, ENT_QUOTES);
  }

  private function filter(array $warnings, array $allowed_files) {
    $filtered_warnings = $this->filterByFilenames($warnings, $allowed_files);

    return $this->filterByChangedLines($filtered_warnings, $allowed_files);
  }

  private function filterByFilenames(array $warnings, array $allowed_files) {
    $filtered_warnings = array();
    $allowed_files = array_keys($allowed_files);

    // Make sure, that filename in warnings array exactly matches
    // one, that is used in commit.
    foreach ($warnings as $file => $file_warnings) {
      foreach ($allowed_files as $allowed_file) {
        if (substr($allowed_file, (-1) * strlen($file)) == $file) {
          $filtered_warnings[$allowed_file] = $file_warnings;
          break;
        }
      }
    }

    return $filtered_warnings;
  }

  private function filterByChangedLines(array $warnings, array $allowed_files) {
    $filtered_warnings = array();

    foreach ($warnings as $file => $file_warnings) {
      $allowed_file_warnings = array();
      $changed_lines = $this->increaseFuzzFactor($allowed_files[$file]);

      foreach ($file_warnings as $file_warning) {
        if (idx($changed_lines, $file_warning['line'])) {
          $allowed_file_warnings[] = $file_warning;
        }
      }

      $filtered_warnings[$file] = $allowed_file_warnings;
    }

    return array_filter($filtered_warnings);
  }

  private function increaseFuzzFactor(array $changed_lines) {
    $new_changed_lines = $changed_lines;

    foreach (array_keys($changed_lines) as $changed_line) {
      $new_changed_lines[$changed_line - 1] = true;
      $new_changed_lines[$changed_line + 1] = true;
    }

    return $new_changed_lines;
  }

}
