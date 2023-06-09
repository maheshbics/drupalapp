<?php

namespace Drupal\Core\Asset;

@trigger_error('The ' . __NAMESPACE__ . '\CssCollectionOptimizer is deprecated in drupal:10.1.0 and is removed from drupal:11.0.0. Instead, use ' . __NAMESPACE__ . '\CssCollectionOptimizerLazy. See https://www.drupal.org/node/2888767', E_USER_DEPRECATED);

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;

/**
 * Optimizes CSS assets.
 *
 *  @deprecated in drupal:10.1.0 and is removed from drupal:11.0.0. Instead, use
 *    \Drupal\Core\Asset\CssCollectionOptimizerLazy.
 *
 * @see https://www.drupal.org/node/2888767
 */
class CssCollectionOptimizer implements AssetCollectionOptimizerInterface {

  /**
   * A CSS asset grouper.
   *
   * @var \Drupal\Core\Asset\CssCollectionGrouper
   */
  protected $grouper;

  /**
   * A CSS asset optimizer.
   *
   * @var \Drupal\Core\Asset\CssOptimizer
   */
  protected $optimizer;

  /**
   * An asset dumper.
   *
   * @var \Drupal\Core\Asset\AssetDumper
   */
  protected $dumper;

  /**
   * The state key/value store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a CssCollectionOptimizer.
   *
   * @param \Drupal\Core\Asset\AssetCollectionGrouperInterface $grouper
   *   The grouper for CSS assets.
   * @param \Drupal\Core\Asset\AssetOptimizerInterface $optimizer
   *   The optimizer for a single CSS asset.
   * @param \Drupal\Core\Asset\AssetDumperInterface $dumper
   *   The dumper for optimized CSS assets.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state key/value store.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(AssetCollectionGrouperInterface $grouper, AssetOptimizerInterface $optimizer, AssetDumperInterface $dumper, StateInterface $state, FileSystemInterface $file_system) {
    $this->grouper = $grouper;
    $this->optimizer = $optimizer;
    $this->dumper = $dumper;
    $this->state = $state;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   *
   * The cache file name is retrieved on a page load via a lookup variable that
   * contains an associative array. The array key is the hash of the file names
   * in $css while the value is the cache file name. The cache file is generated
   * in two cases. First, if there is no file name value for the key, which will
   * happen if a new file name has been added to $css or after the lookup
   * variable is emptied to force a rebuild of the cache. Second, the cache file
   * is generated if it is missing on disk. Old cache files are not deleted
   * immediately when the lookup variable is emptied, but are deleted after a
   * configurable period (@code system.performance.stale_file_threshold @endcode)
   * to ensure that files referenced by a cached page will still be available.
   */
  public function optimize(array $css_assets, array $libraries) {
    // Group the assets.
    $css_groups = $this->grouper->group($css_assets);

    // Now optimize (concatenate + minify) and dump each asset group, unless
    // that was already done, in which case it should appear in
    // drupal_css_cache_files.
    // Drupal contrib can override this default CSS aggregator to keep the same
    // grouping, optimizing and dumping, but change the strategy that is used to
    // determine when the aggregate should be rebuilt (e.g. mtime, HTTPS …).
    $map = $this->state->get('drupal_css_cache_files', []);
    $css_assets = [];
    foreach ($css_groups as $order => $css_group) {
      // We have to return a single asset, not a group of assets. It is now up
      // to one of the pieces of code in the switch statement below to set the
      // 'data' property to the appropriate value.
      $css_assets[$order] = $css_group;
      unset($css_assets[$order]['items']);

      switch ($css_group['type']) {
        case 'file':
          // No preprocessing, single CSS asset: just use the existing URI.
          if (!$css_group['preprocess']) {
            $uri = $css_group['items'][0]['data'];
            $css_assets[$order]['data'] = $uri;
          }
          // Preprocess (aggregate), unless the aggregate file already exists.
          else {
            $key = $this->generateHash($css_group);
            $uri = '';
            if (isset($map[$key])) {
              $uri = $map[$key];
            }
            if (empty($uri) || !file_exists($uri)) {
              // Optimize each asset within the group.
              $data = '';
              $current_license = FALSE;
              foreach ($css_group['items'] as $css_asset) {
                // Ensure license information is available as a comment after
                // optimization.
                if ($css_asset['license'] !== $current_license) {
                  $data .= "/* @license " . $css_asset['license']['name'] . " " . $css_asset['license']['url'] . " */\n";
                }
                $current_license = $css_asset['license'];
                $data .= $this->optimizer->optimize($css_asset);
              }
              // Per the W3C specification at
              // http://www.w3.org/TR/REC-CSS2/cascade.html#at-import, @import
              // rules must precede any other style, so we move those to the
              // top. The regular expression is expressed in NOWDOC since it is
              // detecting backslashes as well as single and double quotes. It
              // is difficult to read when represented as a quoted string.
              $regexp = <<<'REGEXP'
/@import\s*(?:'(?:\\'|.)*'|"(?:\\"|.)*"|url\(\s*(?:\\[\)\'\"]|[^'")])*\s*\)|url\(\s*'(?:\'|.)*'\s*\)|url\(\s*"(?:\"|.)*"\s*\)).*;/iU
REGEXP;
              preg_match_all($regexp, $data, $matches);
              $data = preg_replace($regexp, '', $data);
              $data = implode('', $matches[0]) . (!empty($matches[0]) ? "\n" : '') . $data;
              // Dump the optimized CSS for this group into an aggregate file.
              $uri = $this->dumper->dump($data, 'css');
              // Set the URI for this group's aggregate file.
              $css_assets[$order]['data'] = $uri;
              // Persist the URI for this aggregate file.
              $map[$key] = $uri;
              $this->state->set('drupal_css_cache_files', $map);
            }
            else {
              // Use the persisted URI for the optimized CSS file.
              $css_assets[$order]['data'] = $uri;
            }
            $css_assets[$order]['preprocessed'] = TRUE;
          }
          break;

        case 'external':
          // We don't do any aggregation and hence also no caching for external
          // CSS assets.
          $uri = $css_group['items'][0]['data'];
          $css_assets[$order]['data'] = $uri;
          break;
      }
    }

    return $css_assets;
  }

  /**
   * Generate a hash for a given group of CSS assets.
   *
   * @param array $css_group
   *   A group of CSS assets.
   *
   * @return string
   *   A hash to uniquely identify the given group of CSS assets.
   */
  protected function generateHash(array $css_group) {
    $css_data = [];
    foreach ($css_group['items'] as $css_file) {
      $css_data[] = $css_file['data'];
    }
    return hash('sha256', serialize($css_data));
  }

  /**
   * {@inheritdoc}
   */
  public function getAll() {
    return $this->state->get('drupal_css_cache_files');
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {
    $this->state->delete('drupal_css_cache_files');

    $delete_stale = function ($uri) {
      // Default stale file threshold is 30 days.
      if (\Drupal::time()->getRequestTime() - filemtime($uri) > \Drupal::config('system.performance')->get('stale_file_threshold')) {
        $this->fileSystem->delete($uri);
      }
    };
    if (is_dir('public://css')) {
      $this->fileSystem->scanDirectory('public://css', '/.*/', ['callback' => $delete_stale]);
    }
  }

}
