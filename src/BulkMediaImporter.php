<?php

declare(strict_types=1);

namespace CloakWP;

// Include necessary WordPress files
require_once(ABSPATH . '/wp-admin/includes/image.php');
require_once(ABSPATH . '/wp-admin/includes/file.php');
require_once(ABSPATH . '/wp-admin/includes/media.php');

class BulkMediaImporter
{
  protected string $csvFilePath = '';

  public function __construct()
  {
  }

  public static function make(): static
  {
    return new static();
  }

  public function fromCsv(string $csvFilePath): static
  {
    $this->csvFilePath = $csvFilePath;
    return $this;
  }

  public function onUpload(callable $onUpload): static
  {
    if (is_callable($onUpload)) {
      add_action('cloakwp/bulk_media_importer/image_uploaded', $onUpload, 10, 2);
    }

    return $this;
  }

  public function run()
  {
    if ($this->csvFilePath)
      $this->importFromCsv($this->csvFilePath);
  }

  /**
   * Provide a file path to a CSV file that has been uploaded to the WP server. The CSV is expected
   * to have a `src` column containing external image URLs, which will be downloaded/imported. Other 
   * optional built-in column names include `alt`, `caption`, and `description`.
   */
  private function importFromCsv(string $csvFilePath)
  {
    if (!file_exists($csvFilePath) || !is_readable($csvFilePath)) {
      return false;
    }

    // Open the CSV file
    if (($handle = fopen($csvFilePath, 'r')) !== false) {
      // Get the headers from the first row
      $headers = fgetcsv($handle);

      // Find the index of the "src" column
      $srcIndex = array_search('src', $headers);
      if ($srcIndex === false) {
        // Handle the error if "src" column is not found
        fclose($handle);
        throw new \Exception('The CSV file must contain a "src" column for image URLs.');
      }

      $successCount = 0;

      // Loop through the CSV rows
      while (($data = fgetcsv($handle, 1000, ',')) !== false) {
        $imageUrl = $data[$srcIndex];

        // Prepare the $imageMeta associative array for other fields
        $imageMeta = [];
        foreach ($headers as $index => $header) {
          if ($index === $srcIndex)
            continue; // Skip the `src` column (image URL)
          $imageMeta[$header] = $data[$index] ?? ''; // Use the header as the key
        }

        // Import the image
        $imageId = $this->uploadFromUrl($imageUrl, $imageMeta);

        // TODO: handle success or failure here -- or perhaps just call action hooks to let user decide how to handle?
        if (!$imageId) {
          error_log("Failed to import image from URL: {$imageUrl}");
        } else {
          $successCount++;
        }
      }

      if ($successCount) {
        error_log("Successfully imported $successCount images!");
      }

      fclose($handle);
    }
  }

  private function uploadToMediaLibrary(array $fileArray, array $imageMeta)
  {
    // Upload image to Media Library
    $imageId = media_handle_sideload($fileArray, 0);

    // If error storing permanently, unlink temp file
    if (is_wp_error($imageId)) {
      @unlink($fileArray['tmp_name']);
      return false;
    }

    // Add alt text
    if (self::isMetaValid($imageMeta, 'alt')) {
      update_post_meta($imageId, '_wp_attachment_image_alt', sanitize_text_field($imageMeta['alt']));
    }

    // Validate & attach image meta to the new media post
    $attachment = [];
    if (self::isMetaValid($imageMeta, 'caption')) {
      $attachment['post_excerpt'] = sanitize_text_field($imageMeta['caption']);
    }

    if (self::isMetaValid($imageMeta, 'description')) {
      $attachment['post_content'] = sanitize_text_field($imageMeta['description']);
    }

    // Update the attachment post with caption and description
    if (!empty($attachment)) {
      $attachment['ID'] = $imageId;
      wp_update_post($attachment);
    }

    // Custom action hook after the image is uploaded
    do_action('cloakwp/bulk_media_importer/image_uploaded', $imageId, $imageMeta);

    return $imageId;
  }

  private function uploadFromUrl(string $imageUrl, array $imageMeta)
  {
    // Download image to temp location
    $tmp = download_url($imageUrl);

    // Handle download errors
    if (is_wp_error($tmp))
      return false;

    // Get the filename and extension ("photo.png" => "photo", "png")
    $filename = pathinfo($imageUrl, PATHINFO_FILENAME);
    $extension = pathinfo($imageUrl, PATHINFO_EXTENSION);

    // An extension is required or else WordPress will reject the upload
    if (!$extension) {
      $extension = $this->getFileExtensionViaMimeType($tmp);

      if (!$extension) {
        // Could not identify extension. Clear temp file and abort.
        wp_delete_file($tmp);
        return false;
      }
    }

    // Prepare an array of post data for the attachment
    $fileArray = array(
      'name' => "$filename.$extension",
      'tmp_name' => $tmp,
    );

    // Upload image to Media Library
    $imageId = $this->uploadToMediaLibrary($fileArray, $imageMeta);

    return $imageId;
  }

  private function getFileExtensionViaMimeType(string $filePath)
  {
    // Look up mime type; eg. "/photo.png" -> "image/png"
    $mime = mime_content_type($filePath);
    $mime = is_string($mime) ? sanitize_mime_type($mime) : false;

    // Only allow certain mime types because mime types do not always end in a valid extension (see the .doc example below)
    $mimeExtensions = array(
      // mime_type         => extension (no period)
      'text/plain' => 'txt',
      'text/csv' => 'csv',
      'application/msword' => 'doc',
      'image/jpg' => 'jpg',
      'image/jpeg' => 'jpeg',
      'image/gif' => 'gif',
      'image/png' => 'png',
      'video/mp4' => 'mp4',
    );

    if (isset($mimeExtensions[$mime])) {
      // Use the mapped extension
      return $mimeExtensions[$mime];
    }

    return false; // no valid mime type found
  }

  public static function isMetaValid(array $meta, string $field): bool
  {
    if (isset($meta[$field]) && is_string($meta[$field]) && !empty($meta[$field]))
      return true;
    return false;
  }
}