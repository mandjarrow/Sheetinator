<?php
/**
 * Sheetinator Sync Handler
 *
 * Handles syncing form submissions to Google Sheets:
 * - Real-time sync on form submission
 * - Data transformation and formatting
 * - Error handling and logging
 *
 * @package Sheetinator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sheetinator_Sync_Handler {

    /**
     * Option key for sync log
     */
    const LOG_OPTION = 'sheetinator_sync_log';

    /**
     * Maximum log entries to keep
     */
    const MAX_LOG_ENTRIES = 100;

    /**
     * @var Sheetinator_Google_Sheets Google Sheets handler
     */
    private $sheets;

    /**
     * @var Sheetinator_Form_Discovery Form discovery handler
     */
    private $discovery;

    /**
     * Constructor
     *
     * @param Sheetinator_Google_Sheets  $sheets    Google Sheets handler
     * @param Sheetinator_Form_Discovery $discovery Form discovery handler
     */
    public function __construct( Sheetinator_Google_Sheets $sheets, Sheetinator_Form_Discovery $discovery ) {
        $this->sheets    = $sheets;
        $this->discovery = $discovery;
    }

    /**
     * Handle form submission - hook callback
     *
     * @param object $entry      Entry object
     * @param int    $form_id    Form ID
     * @param array  $field_data Field data
     */
    public function handle_submission( $entry, $form_id, $field_data ) {
        // Check if this form has a spreadsheet mapping
        if ( ! $this->sheets->has_mapping( $form_id ) ) {
            return;
        }

        // Get spreadsheet ID
        $spreadsheet_id = $this->sheets->get_spreadsheet_id( $form_id );

        if ( ! $spreadsheet_id ) {
            $this->log_error( $form_id, 'No spreadsheet ID found for form.' );
            return;
        }

        // Transform submission data to row format
        $row_data = $this->transform_submission( $entry, $form_id, $field_data );

        // Append row to spreadsheet
        $result = $this->sheets->append_row( $spreadsheet_id, $row_data );

        if ( is_wp_error( $result ) ) {
            $this->log_error( $form_id, $result->get_error_message() );
            $this->notify_admin_error( $form_id, $result->get_error_message() );
        } else {
            $this->log_success( $form_id, $entry->entry_id ?? 0 );
        }
    }

    /**
     * Transform submission data to row format
     *
     * @param object $entry      Entry object
     * @param int    $form_id    Form ID
     * @param array  $field_data Field data
     * @return array Row data
     */
    private function transform_submission( $entry, $form_id, $field_data ) {
        $row = array();

        // Add standard columns
        $row[] = $entry->entry_id ?? '';
        $row[] = wp_date( 'Y-m-d', strtotime( $entry->date_created ?? 'now' ) );
        $row[] = wp_date( 'H:i:s', strtotime( $entry->date_created ?? 'now' ) );
        $row[] = $this->get_user_ip();

        // Get field IDs in order
        $field_ids = $this->discovery->get_field_ids( $form_id );

        // Build a lookup of submitted data by element_id
        $data_lookup = $this->build_data_lookup( $field_data );

        // Add field values in order
        foreach ( $field_ids as $field_id ) {
            $row[] = $this->get_field_value( $field_id, $data_lookup, $form_id );
        }

        return $row;
    }

    /**
     * Build lookup array from field data
     *
     * @param array $field_data Submitted field data
     * @return array Lookup array
     */
    private function build_data_lookup( $field_data ) {
        $lookup = array();

        foreach ( $field_data as $field ) {
            $name  = $field['name'] ?? '';
            $value = $field['value'] ?? '';

            if ( ! empty( $name ) ) {
                $lookup[ $name ] = $value;
            }
        }

        return $lookup;
    }

    /**
     * Get field value from lookup, handling compound fields
     *
     * Automatically converts radio/select/checkbox values to labels
     * using field definitions.
     *
     * @param string $field_id    Field ID
     * @param array  $data_lookup Data lookup array
     * @param int    $form_id     Form ID for option label lookup
     * @return string Field value
     */
    private function get_field_value( $field_id, $data_lookup, $form_id ) {
        // Direct match
        if ( isset( $data_lookup[ $field_id ] ) ) {
            $value = $data_lookup[ $field_id ];

            // Convert value to label for radio/select/checkbox fields
            $value = $this->convert_value_to_label( $field_id, $value, $form_id );

            return $this->format_value( $value );
        }

        // Check for compound field parts (e.g., name-1-first-name)
        // The field_id might be "name-1-first-name" but data might be under "name-1"
        $base_id = preg_replace( '/-(first-name|last-name|middle-name|prefix|street_address|address_line|city|state|zip|country|hours|minutes)$/', '', $field_id );

        if ( $base_id !== $field_id && isset( $data_lookup[ $base_id ] ) ) {
            $compound_data = $data_lookup[ $base_id ];

            // Extract the specific part
            $part = str_replace( $base_id . '-', '', $field_id );

            if ( is_array( $compound_data ) && isset( $compound_data[ $part ] ) ) {
                return $this->format_value( $compound_data[ $part ] );
            }

            // Handle hyphenated keys (e.g., first-name vs first_name)
            $part_underscore = str_replace( '-', '_', $part );
            if ( is_array( $compound_data ) && isset( $compound_data[ $part_underscore ] ) ) {
                return $this->format_value( $compound_data[ $part_underscore ] );
            }
        }

        return '';
    }

    /**
     * Convert field value to label for option-based fields
     *
     * For radio, select, and checkbox fields, converts numeric or short values
     * to their human-readable labels.
     *
     * @param string $field_id Field ID
     * @param mixed  $value    Field value
     * @param int    $form_id  Form ID
     * @return mixed Converted value or original if not applicable
     */
    private function convert_value_to_label( $field_id, $value, $form_id ) {
        // Skip conversion for empty values
        if ( $value === '' || $value === null ) {
            return $value;
        }

        // For arrays (checkboxes with multiple values), convert each item
        if ( is_array( $value ) ) {
            $converted = array();
            foreach ( $value as $item ) {
                $converted[] = $this->discovery->get_option_label( $field_id, $item, $form_id );
            }
            return $converted;
        }

        // For single values, convert using field definitions
        return $this->discovery->get_option_label( $field_id, $value, $form_id );
    }

    /**
     * Format a value for spreadsheet
     *
     * @param mixed $value Value to format
     * @return string Formatted value
     */
    private function format_value( $value ) {
        // Handle arrays (checkboxes, multi-select, etc.)
        if ( is_array( $value ) ) {
            // For nested arrays (like address), flatten to JSON
            $has_nested = false;
            foreach ( $value as $v ) {
                if ( is_array( $v ) || is_object( $v ) ) {
                    $has_nested = true;
                    break;
                }
            }

            if ( $has_nested ) {
                return wp_json_encode( $value );
            }

            // Simple array: join with comma
            return implode( ', ', array_map( 'strval', $value ) );
        }

        // Handle objects
        if ( is_object( $value ) ) {
            return wp_json_encode( $value );
        }

        // Strip HTML and clean up
        $value = wp_strip_all_tags( (string) $value );
        $value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );

        return $value;
    }

    /**
     * Get user IP address
     *
     * @return string IP address
     */
    private function get_user_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        );

        foreach ( $ip_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                // Handle comma-separated IPs (X-Forwarded-For)
                if ( strpos( $ip, ',' ) !== false ) {
                    $ips = explode( ',', $ip );
                    $ip  = trim( $ips[0] );
                }
                return $ip;
            }
        }

        return '';
    }

    /**
     * Sync all forms - create spreadsheets for forms that don't have one
     *
     * @return array Results array
     */
    public function sync_all_forms() {
        $forms   = $this->discovery->get_all_forms();
        $results = array(
            'success' => array(),
            'errors'  => array(),
            'skipped' => array(),
        );

        foreach ( $forms as $form ) {
            $form_id = $form->id;

            // Skip if already has mapping
            if ( $this->sheets->has_mapping( $form_id ) ) {
                // Verify the sheet still exists
                $spreadsheet_id = $this->sheets->get_spreadsheet_id( $form_id );
                if ( $this->sheets->verify_spreadsheet( $spreadsheet_id ) ) {
                    $results['skipped'][] = array(
                        'form_id' => $form_id,
                        'title'   => $this->discovery->get_form_title( $form_id ),
                        'reason'  => 'Already synced',
                    );
                    continue;
                }
                // Sheet was deleted, remove mapping and recreate
                $this->sheets->remove_mapping( $form_id );
            }

            // Create spreadsheet for this form
            $result = $this->create_form_spreadsheet( $form_id );

            if ( is_wp_error( $result ) ) {
                $results['errors'][] = array(
                    'form_id' => $form_id,
                    'title'   => $this->discovery->get_form_title( $form_id ),
                    'error'   => $result->get_error_message(),
                );
            } else {
                $results['success'][] = array(
                    'form_id'         => $form_id,
                    'title'           => $this->discovery->get_form_title( $form_id ),
                    'spreadsheet_url' => $result['spreadsheet_url'],
                );
            }
        }

        return $results;
    }

    /**
     * Create a spreadsheet for a single form
     *
     * @param int $form_id Form ID
     * @return array|WP_Error Spreadsheet data or error
     */
    public function create_form_spreadsheet( $form_id ) {
        $form_title = $this->discovery->get_form_title( $form_id );
        $headers    = $this->discovery->build_headers( $form_id );

        // Debug: Log headers being created
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( '[Sheetinator] Creating spreadsheet for form %d with %d headers', $form_id, count( $headers ) ) );
            error_log( '[Sheetinator] Headers: ' . wp_json_encode( $headers ) );
        }

        // Create unique spreadsheet title
        $sheet_title = $this->generate_unique_title( $form_title, $form_id );

        // Create the spreadsheet
        $result = $this->sheets->create_spreadsheet( $sheet_title, $headers );

        if ( is_wp_error( $result ) ) {
            error_log( '[Sheetinator] Spreadsheet creation error: ' . $result->get_error_message() );
            return $result;
        }

        // Save the mapping
        $this->sheets->save_mapping(
            $form_id,
            $result['spreadsheet_id'],
            $result['spreadsheet_url']
        );

        return $result;
    }

    /**
     * Generate unique spreadsheet title
     *
     * @param string $base_title Base title
     * @param int    $form_id    Form ID
     * @return string Unique title
     */
    private function generate_unique_title( $base_title, $form_id ) {
        // Include form ID to ensure uniqueness
        $site_name = get_bloginfo( 'name' );
        $site_name = substr( $site_name, 0, 20 ); // Limit site name length

        return sprintf(
            '[%s] %s (Form #%d)',
            $site_name,
            $base_title,
            $form_id
        );
    }

    /**
     * Resync a single form (recreate spreadsheet)
     *
     * @param int $form_id Form ID
     * @return array|WP_Error Result or error
     */
    public function resync_form( $form_id ) {
        // Remove existing mapping
        $this->sheets->remove_mapping( $form_id );

        // Create new spreadsheet
        return $this->create_form_spreadsheet( $form_id );
    }

    /**
     * Import existing entries from Forminator to Google Sheets
     *
     * Uses batch operations to avoid Google API rate limits.
     * Sends up to 500 rows per API call.
     *
     * @param int $form_id Form ID
     * @return array Result with counts
     */
    public function import_existing_entries( $form_id ) {
        $result = array(
            'imported' => 0,
            'failed'   => 0,
            'total'    => 0,
            'errors'   => array(),
            'debug'    => array(),
        );

        // Check if form has a spreadsheet mapping
        if ( ! $this->sheets->has_mapping( $form_id ) ) {
            $result['errors'][] = __( 'Form is not synced. Please sync the form first.', 'sheetinator' );
            return $result;
        }

        $spreadsheet_id = $this->sheets->get_spreadsheet_id( $form_id );

        if ( ! $spreadsheet_id ) {
            $result['errors'][] = __( 'No spreadsheet found for this form.', 'sheetinator' );
            return $result;
        }

        // Get all form entries from Forminator
        if ( ! class_exists( 'Forminator_API' ) ) {
            $result['errors'][] = __( 'Forminator API not available.', 'sheetinator' );
            return $result;
        }

        $entries = Forminator_API::get_form_entries( $form_id );

        if ( is_wp_error( $entries ) ) {
            $result['errors'][] = $entries->get_error_message();
            return $result;
        }

        if ( empty( $entries ) ) {
            $result['errors'][] = __( 'No entries found for this form.', 'sheetinator' );
            return $result;
        }

        $result['total'] = count( $entries );

        // Get field IDs for mapping
        $field_ids = $this->discovery->get_field_ids( $form_id );

        // Add field IDs to debug
        $result['debug']['field_ids'] = array_slice( $field_ids, 0, 10 );

        // Transform all entries to rows
        $all_rows = array();
        foreach ( $entries as $entry ) {
            $all_rows[] = $this->transform_entry( $entry, $form_id, $field_ids, $result['debug'] );
        }

        // Add sample row to debug (first entry's data)
        if ( ! empty( $all_rows ) ) {
            $result['debug']['sample_row'] = array_slice( $all_rows[0], 0, 8 );
        }

        // Batch append rows (500 at a time to stay within limits)
        $batch_size = 500;
        $batches = array_chunk( $all_rows, $batch_size );

        foreach ( $batches as $batch_index => $batch ) {
            $append_result = $this->sheets->append_rows( $spreadsheet_id, $batch );

            if ( is_wp_error( $append_result ) ) {
                $result['failed'] += count( $batch );
                $result['errors'][] = sprintf(
                    __( 'Batch %d failed: %s', 'sheetinator' ),
                    $batch_index + 1,
                    $append_result->get_error_message()
                );
            } else {
                $result['imported'] += count( $batch );
            }

            // Small delay between batches to avoid rate limits
            if ( count( $batches ) > 1 && $batch_index < count( $batches ) - 1 ) {
                sleep( 1 );
            }
        }

        return $result;
    }

    /**
     * Transform a Forminator entry to row format
     *
     * @param object $entry     Entry object from Forminator_API
     * @param int    $form_id   Form ID
     * @param array  $field_ids Field IDs in order
     * @param array  &$debug    Debug info array (passed by reference)
     * @return array Row data
     */
    private function transform_entry( $entry, $form_id, $field_ids, &$debug = null ) {
        $row = array();

        // Get entry ID
        $entry_id = $entry->entry_id ?? ( is_object( $entry ) ? $entry->entry_id : '' );

        // Add standard columns
        $row[] = $entry_id;
        $row[] = wp_date( 'Y-m-d', strtotime( $entry->date_created ?? 'now' ) );
        $row[] = wp_date( 'H:i:s', strtotime( $entry->date_created ?? 'now' ) );

        // Build data lookup from entry meta
        $data_lookup = array();
        $ip = '';

        // Try to get full entry data if meta_data is not populated
        $full_entry = null;
        if ( ( ! isset( $entry->meta_data ) || empty( $entry->meta_data ) ) && ! empty( $entry_id ) ) {
            $full_entry = Forminator_API::get_entry( $form_id, $entry_id );
            if ( ! is_wp_error( $full_entry ) && $full_entry ) {
                $entry = $full_entry;
            }
        }

        // Method 1: Try using get_meta() method if available (Forminator entry model)
        if ( is_object( $entry ) && method_exists( $entry, 'get_meta' ) ) {
            foreach ( $field_ids as $field_id ) {
                // Get the base field ID (without compound suffixes)
                $base_id = preg_replace( '/-(first-name|last-name|middle-name|prefix|street_address|address_line|city|state|zip|country|hours|minutes)$/', '', $field_id );

                $value = $entry->get_meta( $base_id, '' );
                if ( $value !== '' && $value !== null ) {
                    $data_lookup[ $base_id ] = maybe_unserialize( $value );
                }
            }

            // Get IP
            $ip = $entry->get_meta( '_forminator_user_ip', '' );
        }

        // Method 2: Parse meta_data array/object if get_meta didn't work or wasn't available
        if ( empty( $data_lookup ) && isset( $entry->meta_data ) ) {
            $meta_data = $entry->meta_data;

            // If meta_data is an object, convert to array
            if ( is_object( $meta_data ) ) {
                $meta_data = get_object_vars( $meta_data );
            }

            if ( is_array( $meta_data ) ) {
                foreach ( $meta_data as $key => $meta ) {
                    // Handle associative array (field_slug => value)
                    if ( is_string( $key ) && ! is_numeric( $key ) ) {
                        $name = $key;
                        $value = $meta;
                    }
                    // Handle array of objects/arrays with name/value pairs
                    elseif ( is_object( $meta ) ) {
                        $name = $meta->meta_key ?? $meta->name ?? '';
                        $value = $meta->meta_value ?? $meta->value ?? '';
                    }
                    elseif ( is_array( $meta ) ) {
                        $name = $meta['meta_key'] ?? $meta['name'] ?? '';
                        $value = $meta['meta_value'] ?? $meta['value'] ?? '';
                    }
                    else {
                        continue;
                    }

                    if ( empty( $name ) ) {
                        continue;
                    }

                    // Get IP address
                    if ( $name === '_forminator_user_ip' ) {
                        $ip = $value;
                        continue;
                    }

                    // Skip other internal meta fields
                    if ( strpos( $name, '_' ) === 0 ) {
                        continue;
                    }

                    // Unserialize value if needed
                    $value = maybe_unserialize( $value );

                    $data_lookup[ $name ] = $value;
                }
            }
        }

        $row[] = $ip;

        // Collect debug info for first entry only
        if ( is_array( $debug ) && ! isset( $debug['sample_entry'] ) ) {
            $debug['sample_entry'] = array(
                'entry_id'          => $entry_id,
                'meta_keys_found'   => array_keys( $data_lookup ),
                'expected_field_ids' => array_slice( $field_ids, 0, 10 ),
                'meta_data_type'    => isset( $entry->meta_data ) ? gettype( $entry->meta_data ) : 'not set',
                'has_get_meta'      => is_object( $entry ) && method_exists( $entry, 'get_meta' ),
                'sample_values'     => array_slice( $data_lookup, 0, 3, true ),
            );
        }

        // Add field values in order
        foreach ( $field_ids as $field_id ) {
            $row[] = $this->get_field_value( $field_id, $data_lookup, $form_id );
        }

        return $row;
    }

    /**
     * Log successful sync
     *
     * @param int $form_id  Form ID
     * @param int $entry_id Entry ID
     */
    private function log_success( $form_id, $entry_id ) {
        $this->add_log_entry( array(
            'type'     => 'success',
            'form_id'  => $form_id,
            'entry_id' => $entry_id,
            'time'     => current_time( 'mysql' ),
        ) );
    }

    /**
     * Log sync error
     *
     * @param int    $form_id Form ID
     * @param string $message Error message
     */
    private function log_error( $form_id, $message ) {
        $this->add_log_entry( array(
            'type'    => 'error',
            'form_id' => $form_id,
            'message' => $message,
            'time'    => current_time( 'mysql' ),
        ) );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf( '[Sheetinator] Sync error for form %d: %s', $form_id, $message ) );
        }
    }

    /**
     * Add entry to sync log
     *
     * @param array $entry Log entry
     */
    private function add_log_entry( $entry ) {
        $log   = get_option( self::LOG_OPTION, array() );
        $log[] = $entry;

        // Keep only recent entries
        if ( count( $log ) > self::MAX_LOG_ENTRIES ) {
            $log = array_slice( $log, -self::MAX_LOG_ENTRIES );
        }

        update_option( self::LOG_OPTION, $log );
    }

    /**
     * Get sync log
     *
     * @param int $limit Number of entries to return
     * @return array Log entries
     */
    public function get_log( $limit = 20 ) {
        $log = get_option( self::LOG_OPTION, array() );
        return array_slice( array_reverse( $log ), 0, $limit );
    }

    /**
     * Clear sync log
     *
     * @return bool
     */
    public function clear_log() {
        return delete_option( self::LOG_OPTION );
    }

    /**
     * Notify admin of sync error
     *
     * @param int    $form_id Form ID
     * @param string $message Error message
     */
    private function notify_admin_error( $form_id, $message ) {
        // Store error for display in admin
        $errors   = get_transient( 'sheetinator_sync_errors' ) ?: array();
        $errors[] = array(
            'form_id' => $form_id,
            'title'   => $this->discovery->get_form_title( $form_id ),
            'message' => $message,
            'time'    => current_time( 'mysql' ),
        );

        // Keep only last 10 errors
        $errors = array_slice( $errors, -10 );

        set_transient( 'sheetinator_sync_errors', $errors, DAY_IN_SECONDS );
    }

    /**
     * Get and clear sync errors for admin display
     *
     * @return array
     */
    public function get_sync_errors() {
        $errors = get_transient( 'sheetinator_sync_errors' ) ?: array();
        delete_transient( 'sheetinator_sync_errors' );
        return $errors;
    }
}
