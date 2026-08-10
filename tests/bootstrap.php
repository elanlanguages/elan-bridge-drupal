<?php

/**
 * @file
 * Prepares the standalone module repository for Drupal kernel tests.
 */

declare(strict_types=1);

$project_root = dirname(__DIR__);
$drupal_root = $project_root . '/vendor/drupal';
$core_root = $drupal_root . '/core';

if (!is_dir($core_root)) {
  throw new RuntimeException('Install Composer dependencies before running tests.');
}

// Drupal 11 moved SQLite into a module; Drupal 10 still uses the legacy core
// driver. Keep an explicitly supplied database URL, otherwise select the URL
// form supported by the installed core before PHPUnit forks kernel tests.
if ((string) getenv('SIMPLETEST_DB') === '') {
  $simpletest_db = 'sqlite://localhost/:memory:';
  if (is_file($core_root . '/modules/sqlite/sqlite.info.yml')) {
    $simpletest_db .= '?module=sqlite';
  }
  putenv('SIMPLETEST_DB=' . $simpletest_db);
  $_ENV['SIMPLETEST_DB'] = $simpletest_db;
  $_SERVER['SIMPLETEST_DB'] = $simpletest_db;
}

/**
 * Creates a development-only link without replacing an existing path.
 */
function elan_bridge_test_link(string $target, string $link): void {
  if (file_exists($link) || is_link($link)) {
    return;
  }
  if (!symlink($target, $link)) {
    throw new RuntimeException(sprintf('Could not create Drupal test link %s.', $link));
  }
}

foreach (['sites', 'modules', 'profiles', 'themes'] as $directory) {
  if (!is_dir($drupal_root . '/' . $directory)) {
    mkdir($drupal_root . '/' . $directory, 0775, TRUE);
  }
}

elan_bridge_test_link(
  $project_root . '/vendor/autoload.php',
  $drupal_root . '/autoload.php',
);
elan_bridge_test_link(
  $project_root . '/vendor/drupal/key',
  $drupal_root . '/modules/key',
);
elan_bridge_test_link(
  $project_root . '/vendor/drupal/tmgmt',
  $drupal_root . '/modules/tmgmt',
);

// Do not link the whole repository: Drupal's bootstrap recursively follows
// extension links and would then walk back into vendor/drupal indefinitely.
$module_root = $drupal_root . '/modules/elan_bridge';
if (!is_dir($module_root)) {
  mkdir($module_root, 0775, TRUE);
}
foreach (['config', 'src'] as $directory) {
  elan_bridge_test_link(
    $project_root . '/' . $directory,
    $module_root . '/' . $directory,
  );
}
foreach (
  [
    'elan_bridge.info.yml',
    'elan_bridge.install',
    'elan_bridge.links.menu.yml',
    'elan_bridge.module',
    'elan_bridge.permissions.yml',
    'elan_bridge.routing.yml',
    'elan_bridge.services.yml',
  ] as $file
) {
  elan_bridge_test_link($project_root . '/' . $file, $module_root . '/' . $file);
}

require $core_root . '/tests/bootstrap.php';

// Core's bootstrap changes into the synthetic Drupal root. PHPUnit 9 resolves
// relative suite directories afterwards, so restore the repository directory.
chdir($project_root);
