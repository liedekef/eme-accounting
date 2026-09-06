<?php

/**
 * Standalone file-attachment helper.
 *
 * Storage layout on disk:
 *   {upload_dir}/{type}/{id}/{random_id}-{key}-{sanitized_filename}
 *
 * -------------------------------------------------------------------------
 * QUICK-START
 * -------------------------------------------------------------------------
 *
 * 1. Create an instance (once per plugin, e.g. in your plugin's init action):
 *
 *      $upload = wp_upload_dir();
 *      $attachments = new EMEFileAttachments(
 *          $upload['basedir'] . '/my-plugin',
 *          $upload['baseurl'] . '/my-plugin'
 *      );
 *
 *    Store it somewhere accessible, e.g. as a static property on your main class.
 *    Each plugin has its own instance → zero conflicts between plugins.
 *
 * 2. Upload on form save (multipart/form-data form, input name="files[]"):
 *
 *      $entry_id = Journal::save( $_POST );
 *      if ( is_int( $entry_id ) ) {
 *          $attachments->upload( $entry_id, 'journal' );
 *      }
 *
 * 3. Render links in a list / edit screen:
 *
 *      $row['files'] = $attachments->get_links_html( $entry_id, 'journal' );
 *      // or
 *      $links = $attachments->get_links( $entry_id, 'journal' );
 *      $row['files'] = implode( '', $links );
 *
 * 4. AJAX delete – register the action in your plugin:
 *
 *      add_action( 'wp_ajax_my_plugin_del_attachment', function() use ( $attachments ) {
 *          check_ajax_referer( 'my_nonce', 'nonce' );
 *          current_user_can( 'manage_options' ) || wp_die( 'Forbidden', 403 );
 *          $attachments->ajax_delete();
 *      } );
 *
 *    In the JS delete handler, POST:
 *      action, nonce, id, type, key, random_id, name
 *    (these are already present as data-* attributes on the delete button
 *     rendered by get_links / get_links_html)
 *
 * 5. Auto-cleanup when a record is deleted:
 *
 *      $attachments->delete_all( $entry_id, 'journal' );
 */
class EMEFileAttachments {

    /** Whitelist of allowed file extensions (lowercase, no dot). */
    const ALLOWED_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ods', 'odt',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt', 'csv',
    ];

    /** Maximum accepted attachment size in bytes (10 MB). */
    const MAX_FILE_SIZE = 10485760;

    /** Admin-ajax action used by the authenticated download proxy. */
    const DOWNLOAD_ACTION = 'eme_accounting_download_attachment';

    /** @var string  Absolute path to the upload root. */
    private string $base_dir;

    /** @var string  Public URL to the upload root. */
    private string $base_url;

    /* ------------------------------------------------------------------
     * Constructor
     * ------------------------------------------------------------------ */

    /**
     * @param string $base_dir  Absolute server path, no trailing slash.
     * @param string $base_url  Matching public URL, no trailing slash.
     */
    public function __construct( string $base_dir, string $base_url ) {
        $this->base_dir = rtrim( $base_dir, '/\\' );
        $this->base_url = rtrim( $base_url, '/' );
    }

    /* ------------------------------------------------------------------
     * Write
     * ------------------------------------------------------------------ */

    /**
     * Move uploaded files (from $_FILES[$file_key]) into storage.
     *
     * @param int    $id        Record ID (journal entry, asset, laptop …).
     * @param string $type      Subdirectory name, e.g. 'journal', 'assets'.
     * @param string $file_key  The HTML input name attribute, default 'files'.
     */
    public function upload( int $id, string $type, string $file_key = 'files' ): void {
        if ( empty( $_FILES ) || empty( $_FILES[ $file_key ] ) ) {
            return;
        }

        $target = $this->target_dir( $id, $type );
        $this->mkdir_with_index( $target );
        $this->protect_dir( $this->base_dir );

        $file_entry = $_FILES[ $file_key ]; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // Normalise single-file upload to the same array shape as multi.
        if ( ! is_array( $file_entry['name'] ) ) {
            foreach ( [ 'name', 'tmp_name', 'size', 'error' ] as $k ) {
                $file_entry[ $k ] = [ $file_entry[ $k ] ];
            }
        }

        $total = count( $file_entry['name'] );
        for ( $i = 0; $i < $total; $i++ ) {
            $original  = $file_entry['name'][ $i ];
            $tmp       = $file_entry['tmp_name'][ $i ];
            $size      = $file_entry['size'][ $i ];
            $error     = $file_entry['error'][ $i ];

            if ( $error === UPLOAD_ERR_NO_FILE || empty( $original ) || $size === 0 ) {
                continue;
            }
            if ( $error !== UPLOAD_ERR_OK ) {
                continue;
            }

            // Extension allowlist prevents script uploads (php, phtml, …).
            $ext = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, self::ALLOWED_EXTENSIONS, true ) ) {
                continue;
            }
            // Reject oversized files.
            if ( (int) $size > self::MAX_FILE_SIZE ) {
                continue;
            }

            $stored = $this->sanitize_filename( $original, $file_key );
            if ( $stored === false ) {
                continue;
            }

            $dest = $target . '/' . $stored;
            if ( file_exists( $dest ) ) {
                continue; // already uploaded (same random prefix makes this rare)
            }

            move_uploaded_file( $tmp, $dest ); //phpcs:ignore Generic.PHP.ForbiddenFunctions.Found
        }
    }

    /**
     * Delete ALL attachments for a record (call when the record itself is deleted).
     *
     * @param int    $id
     * @param string $type
     */
    public function delete_all( int $id, string $type ): void {
        $dir = $this->target_dir( $id, $type );
        if ( is_dir( $dir ) ) {
            $this->del_tree( $dir );
        }
    }

    /**
     * Delete a single stored file by its stored filename (incl. random prefix).
     *
     * @param string $stored_filename  e.g. "abc123-files-invoice.pdf"
     * @param int    $id
     * @param string $type
     */
    public function delete_one( string $stored_filename, int $id, string $type ): void {
        $path = $this->target_dir( $id, $type ) . '/' . $stored_filename;
        if ( is_file( $path ) ) {
            wp_delete_file( $path );
        }
    }

    /* ------------------------------------------------------------------
     * Read
     * ------------------------------------------------------------------ */

    /**
     * Return raw file-info arrays for all attachments of a record,
     * optionally filtered by key.
     *
     * Each element:
     *   id, type, random_id, key, field_name, name, url, mimetype
     *
     * @param  int    $id
     * @param  string $type
     * @param  string $key   Pass '' to return all keys, or e.g. 'files' to filter.
     * @return array<int,array<string,mixed>>
     */
    public function get_files( int $id, string $type, string $key = 'files' ): array {
        $res = [];
        $dir = $this->target_dir( $id, $type );

        if ( ! is_dir( $dir ) ) {
            return $res;
        }

        $handle = opendir( $dir );
        if ( ! $handle ) {
            return $res;
        }

        while ( ( $entry = readdir( $handle ) ) !== false ) {
            if ( $entry === '.' || $entry === '..' || $entry === 'index.html' ) {
                continue;
            }

            // Expected format: {random_id}-{key}-{filename}   (max 3 parts)
            $info = explode( '-', $entry, 3 );
            if ( count( $info ) !== 3 ) {
                continue;
            }

            if ( $key !== '' && $info[1] !== $key ) {
                continue;
            }

            $res[] = [
                'id'         => $id,
                'type'       => $type,
                'random_id'  => $info[0],
                'key'        => $info[1],
                'field_name' => $entry,
                'name'       => $info[2],
                'url'        => $this->download_url( $id, $type, $entry ),
                'mimetype'   => mime_content_type( $dir . '/' . $entry ),
            ];
        }
        closedir( $handle );

        return $res;
    }

    /**
     * Build the authenticated download-proxy URL for a stored file.
     *
     * Files are never linked directly; every download goes through the
     * admin-ajax proxy which enforces nonce + capability checks.
     *
     * @param  int    $id
     * @param  string $type
     * @param  string $stored  Stored filename, e.g. "abc…-files-invoice.pdf".
     * @return string
     */
    public function download_url( int $id, string $type, string $stored ): string {
        $parts = explode( '-', $stored, 3 );
        if ( count( $parts ) !== 3 ) {
            return '';
        }
        return add_query_arg(
            [
                'action'    => self::DOWNLOAD_ACTION,
                'nonce'     => wp_create_nonce( 'eme_accounting_nonce' ),
                'id'        => $id,
                'type'      => $type,
                'random_id' => $parts[0],
                'key'       => $parts[1],
                'name'      => $parts[2],
            ],
            admin_url( 'admin-ajax.php' )
        );
    }

    /**
     * Return rendered HTML link+delete-button strings.
     *
     * The delete button carries the data-* attributes expected by the AJAX
     * handler (ajax_delete / your custom JS).
     *
     * @param  int    $id
     * @param  string $type
     * @param  string $key
     * @return string[]
     */
    public function get_links( int $id, string $type, string $key = 'files' ): array {
        $links = [];
        foreach ( $this->get_files( $id, $type, $key ) as $file ) {
            $links[] = $this->render_link( $file );
        }
        return $links;
    }
    public function get_delete_links( int $id, string $type, string $key = 'files' ): array {
        $links = [];
        foreach ( $this->get_files( $id, $type, $key ) as $file ) {
            $links[] = $this->render_link( $file, true );
        }
        return $links;
    }

    /**
     * Convenience wrapper – returns a single imploded HTML string.
     *
     * @param  int    $id
     * @param  string $type
     * @param  string $key
     * @return string
     */
    public function get_links_html( int $id, string $type, string $key = 'files' ): string {
        return implode( '', $this->get_links( $id, $type, $key ) );
    }
    public function get_delete_links_html( int $id, string $type, string $key = 'files' ): string {
        return implode( '', $this->get_delete_links( $id, $type, $key ) );
    }

    /**
     * Render a single link + delete button for a file-info array.
     *
     * @param  array<string,mixed> $file  One element from get_files().
     * @param  boolean $deletelink
     * @return string
     */
    public function render_link( array $file, bool $deletelink = false ): string {
        $name      = esc_html( $file['name'] );
        $url       = esc_url( $file['url'] );
        $id        = (int) $file['id'];
        $type      = esc_attr( $file['type'] );
        $random_id = esc_attr( $file['random_id'] );
        $key       = esc_attr( $file['key'] );

        $is_image = str_starts_with( $file['mimetype'], 'image/' );
        $link_class = $is_image ? 'image-preview-link norowselectonclick' : 'norowselectonclick';
        $link = "<a id='upload-{$random_id}' class='{$link_class}' target='_blank' href='{$url}'>{$name}</a>";

        if ($deletelink) {
            $link .= " <a class='del_upload-button norowselectonclick' href='#'"
                . " data-id='{$id}'"
                . " data-name='{$name}'"
                . " data-random_id='{$random_id}'"
                . " data-type='{$type}'"
                . " data-key='{$key}'>"
                . "<span class='norowselectonclick'>❌</span>"
                . "</a>";
        }

        return "<div id='span_{$random_id}'>{$link}<br></div>";
    }

    /* ------------------------------------------------------------------
     * AJAX
     * ------------------------------------------------------------------ */

    /**
     * Generic AJAX delete handler.
     *
     * Wire this up yourself so you can apply the correct nonce and
     * capability check for your plugin:
     *
     *   add_action( 'wp_ajax_my_plugin_del_attachment', function() {
     *       check_ajax_referer( 'my_nonce', 'nonce' );
     *       current_user_can( 'manage_options' ) || wp_die( 'Forbidden', 403 );
     *       EMEFileAttachments::ajax_delete();
     *   } );
     *
     * Expected POST fields: id, type, key, random_id, name
     */
    public function ajax_delete(): void {
        $id        = isset( $_POST['id'] )        ? (int) $_POST['id']                              : 0;
        $type      = isset( $_POST['type'] )      ? sanitize_key( $_POST['type'] )                  : '';
        $random_id = isset( $_POST['random_id'] ) ? $this->sanitize_filepart( eme_sanitize_request( $_POST['random_id'] ) ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $key       = isset( $_POST['key'] )       ? sanitize_key( $_POST['key'] )                   : '';
        $raw_name  = isset( $_POST['name'] )      ? trim( eme_sanitize_request( $_POST['name'] ) )   : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        if ( ! $id || ! $type || ! $random_id || ! $key || ! $raw_name ) {
            wp_send_json_error( 'Missing parameters.' );
        }

        // Reconstruct stored filename from the posted components.
        $dot      = strrpos( $raw_name, '.' );
        $namepart = $dot !== false ? substr( $raw_name, 0, $dot ) : $raw_name;
        $ext      = $dot !== false ? substr( $raw_name, $dot + 1 ) : 'none';

        $clean_name = $this->sanitize_filepart( $namepart );
        $clean_ext  = $this->sanitize_filepart( $ext );

        if ( ! $clean_name || ! $clean_ext ) {
            wp_send_json_error( 'Invalid filename.' );
        }

        $stored = "{$random_id}-{$key}-{$clean_name}.{$clean_ext}";
        $this->delete_one( $stored, $id, $type );

        wp_send_json_success();
    }

    /* ------------------------------------------------------------------
     * Internal helpers
     * ------------------------------------------------------------------ */

    private function target_dir( int $id, string $type ): string {
        return $this->base_dir . '/' . $type . '/' . $id;
    }

    /**
     * Create a directory (recursively) and drop an empty index.html in it
     * to prevent directory listing.
     */
    private function mkdir_with_index( string $targetPath ): bool {
        $mkdir_res = true;
        if ( ! is_dir( $targetPath ) ) {
            $mkdir_res = wp_mkdir_p( $targetPath );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- filesystem check
        if ( $mkdir_res && is_writable( $targetPath ) && ! is_file( $targetPath . '/index.html' ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- filesystem check
            touch( $targetPath . '/index.html' );
        } 
        return $mkdir_res;
    }

    /**
     * Drop a .htaccess in the given directory to prevent the webserver
     * from executing scripts (PHP) stored there.
     */
    private function protect_dir( string $dir ): void {
        $dir = rtrim( $dir, '/\\' );
        if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- filesystem check
            return;
        }
        $file = $dir . '/.htaccess';
        if ( is_file( $file ) ) {
            return;
        }
        $rules = "# Disable script execution in this upload directory.\n"
            . "<FilesMatch \"\\.(php|php[0-9]|phtml|phar|pht)$\">\n"
            . "    <IfModule mod_authz_core.c>\n"
            . "        Require all denied\n"
            . "    </IfModule>\n"
            . "    <IfModule !mod_authz_core.c>\n"
            . "        Order deny,allow\n"
            . "        Deny from all\n"
            . "    </IfModule>\n"
            . "</FilesMatch>\n";
        file_put_contents( $file, $rules );
    }

    /**
     * Recursively delete a directory and all its contents.
     */
    private function del_tree( string $dir ): bool {
        foreach ( array_diff( scandir( $dir ), [ '.', '..' ] ) as $item ) {
            $full = "$dir/$item";
            is_dir( $full ) ? $this->del_tree( $full ) : wp_delete_file( $full );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- directory cleanup
        return rmdir( $dir );
    }

    /**
     * Build the stored filename:  {random_id}-{key}-{sanitized_name}
     *
     * Returns false when the name cannot be sanitized to a safe string.
     *
     * @param  string $original  Original filename as provided by the browser.
     * @param  string $key       The file-input key (= HTML name attribute).
     * @return string|false
     */
    private function sanitize_filename( string $original, string $key ): string|false {
        $original = trim( $original );
        if ( $original === '' ) {
            return false;
        }

        $namepart = pathinfo( $original, PATHINFO_FILENAME );
        $ext      = pathinfo( $original, PATHINFO_EXTENSION );
        if ( $ext === '' ) {
            $ext = 'none';
        }

        $clean_name = $this->sanitize_filepart( $namepart );
        $clean_ext  = $this->sanitize_filepart( $ext );

        if ( $clean_name === '' || $clean_ext === '' ) {
            return false;
        }

        $filename = "{$clean_name}.{$clean_ext}";

        // Collapse repeated separators.
        $filename = preg_replace( [ '/ +/', '/_+/', '/-+/' ], '-', $filename );
        // Clean up around dots.
        $filename = preg_replace( [ '/-*\.-*/', '/\.{2,}/' ], '.', $filename );
        $filename = trim( $filename, '.-' );

        if ( $filename === '' ) {
            return false;
        }

        return $this->random_id() . '-' . $key . '-' . $filename;
    }

    /**
     * Sanitize a single filename component (name or extension):
     * keep letters, digits, spaces, hyphens; replace everything else with _.
     */
    private function sanitize_filepart( string $part ): string {
        return trim( preg_replace( '/[^\da-z \-]/i', '_', $part ) );
    }

    /**
     * Generate a cryptographically random hex string (46 chars).
     */
    private function random_id(): string {
        $length = 46;
        if ( function_exists( 'random_bytes' ) ) {
            return substr( bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ), 0, $length );
        }
        if ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
            return substr( bin2hex( openssl_random_pseudo_bytes( (int) ceil( $length / 2 ) ) ), 0, $length );
        }
        return substr( uniqid(), 0, 13 ) . '_' . substr( md5( (string) wp_rand() ), 0, 32 );
    }
}
