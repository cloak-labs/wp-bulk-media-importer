# WP Bulk Media Importer

This is a small PHP/Composer package (intended to be used by WordPress plugin/theme developers) that provides a simple object-oriented API for importing images in bulk from external URLs referenced in a CSV file. You can also import image alt text, captions, descriptions, and hook into the upload process to import other custom stuff for each image (such as attaching each image to a taxonomy term based on a CSV value).

Most bulk media importer plugins seem to only support importing posts/pages/CPTs, with the option to import images/media as long as they're attached to the posts being imported; whereas this package simply uploads the images to the WP Media Library without requiring you to attach them to posts/pages.

_Note:_ This plugin does not expose anything via the WP admin UI -- you must be somewhat comfortable with PHP to use it.

## Installation

Require this package with Composer in the root directory of your project.

```bash
composer require cloak-labs/wp-bulk-media-importer
```

## Usage

```php
use CloakWP\BulkMediaImporter;

BulkMediaImporter::make()
  ->fromCsv(get_theme_file_path('/assets/images.csv'))
  ->onUpload(function ($imageId, $imageMeta) {
    /*
      Custom logic to attach each imported image to a term/category from
      the custom taxonomy `category_media`, if a valid term/category
      slug is set in the CSV's `category` column.
     */
    if (BulkMediaImporter::isMetaValid($imageMeta, 'category')) {
      $taxonomy = 'category_media';
      $term = get_term_by('slug', $imageMeta['category'], $taxonomy);
      if (!$term) return false;

      wp_set_object_terms($imageId, $term->term_id, $taxonomy);
    }
  })
  ->run(); // make sure to call `run` to actually run the import
```

It's not recommended to include the above implementation directly in `functions.php`, as you will likely end up running the import more than once. You can create a WP admin menu item that runs the import on click, or you can create a custom WP-CLI command to run it like so:

```php
// in functions.php
if (defined('WP_CLI') && WP_CLI) {
  WP_CLI::add_command('bulk-media-import', function () {
    BulkMediaImporter::make()
      ->fromCsv(get_theme_file_path('/assets/images.csv'))
      ->onUpload(function ($imageId, $imageMeta) {
        if (BulkMediaImporter::isMetaValid($imageMeta, 'category')) {
          $taxonomy = 'category_media';
          $term = get_term_by('slug', $imageMeta['category'], $taxonomy);
          if (!$term) {
            WP_CLI::warning("Term not found: " . $imageMeta['category']);
            return false;
          }

          wp_set_object_terms($imageId, $term->term_id, $taxonomy);
          WP_CLI::success("Assigned category to image ID: $imageId");
        }
      })
      ->run();
  });
}
```

_Note_:

- The CSV is expected to have a `src` column referencing the external image URLs
- The CSV also expects alt text, captions, and descriptions to be under the columns `alt`, `caption`, `description`, respectively.
- Any other CSV columns will be included in `$imageMeta` as the 2nd argument to the `onUpload` method, enabling you to write custom import logic based on your custom CSV values.

## Future

This package is very young and currently just supports importing from a CSV that references external image URLs; but it's intentionally architected (and named) to support other types of bulk image import scenarios in the future -- please create a new issue describing your desired import scenarios.
