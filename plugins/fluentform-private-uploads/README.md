# Fluent Forms Private Uploads

This extension plugin enforces private file handling for Fluent Forms:

1. Forces Fluent Forms file uploads to be written to:
   `/home/1264996.cloudwaysapps.com/hgfynmchnh/private_html/fluentforms-uploads`
2. Rewrites submission payload file values to a private marker (`ff-private://...`) and relocates files from public uploads if needed.
3. Replaces admin Entry Details file links with signed, protected download URLs.
4. Serves file downloads only to logged-in users with `manage_options` capability.
5. Supports single and multiple file uploads.
6. Includes multiple enforcement layers: `upload_dir`, `fluentform/insert_response_data`, `fluentform/filter_insert_data`, and a post-insert reconciliation hook for robust relocation.

## Install

- Copy `fluentform-private-uploads` into `wp-content/plugins/`
- Activate **Fluent Forms Private Uploads**

## Notes

- Existing submissions with public file URLs are converted lazily in the Entry Details renderer when possible.
- Protected download endpoint uses `admin-post.php` action `ff_private_fluentform_download` plus nonce validation.
