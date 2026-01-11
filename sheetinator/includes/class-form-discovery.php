<?php
/**
 * Sheetinator Form Discovery
 *
 * Discovers and analyzes Forminator forms to extract:
 * - Form metadata (ID, title, status)
 * - Form fields with labels and types
 * - Field structure for sheet headers
 *
 * @package Sheetinator
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sheetinator_Form_Discovery {

    /**
     * Standard columns that are always included
     */
    const STANDARD_COLUMNS = array(
        'Entry ID',
        'Submission Date',
        'Submission Time',
        'User IP',
    );

    /**
     * Get all Forminator custom forms
     *
     * @return array Array of form objects
     */
    public function get_all_forms() {
        if ( ! class_exists( 'Forminator_API' ) ) {
            return array();
        }

        $forms = Forminator_API::get_forms( null, 1, -1 );

        if ( is_wp_error( $forms ) ) {
            return array();
        }

        return $forms;
    }

    /**
     * Get a single form by ID
     *
     * @param int $form_id Form ID
     * @return object|null Form object or null
     */
    public function get_form( $form_id ) {
        if ( ! class_exists( 'Forminator_API' ) ) {
            return null;
        }

        $form = Forminator_API::get_form( $form_id );

        if ( is_wp_error( $form ) ) {
            return null;
        }

        return $form;
    }

    /**
     * Get form title
     *
     * @param int $form_id Form ID
     * @return string Form title
     */
    public function get_form_title( $form_id ) {
        $form = $this->get_form( $form_id );

        if ( ! $form ) {
            return sprintf( 'Form #%d', $form_id );
        }

        $title = $form->settings['formName'] ?? $form->name ?? '';

        if ( empty( $title ) ) {
            return sprintf( 'Form #%d', $form_id );
        }

        return $title;
    }

    /**
     * Get form fields with their labels
     *
     * Uses Forminator_API::get_form_fields() to retrieve field definitions.
     *
     * @param int $form_id Form ID
     * @return array Array of field info (element_id => label)
     */
    public function get_form_fields( $form_id ) {
        if ( ! class_exists( 'Forminator_API' ) ) {
            return array();
        }

        // Use the proper Forminator API method to get form fields
        $form_fields = Forminator_API::get_form_fields( $form_id );

        if ( is_wp_error( $form_fields ) || empty( $form_fields ) ) {
            return array();
        }

        $fields = array();

        foreach ( $form_fields as $field ) {
            $field_data = $this->parse_field( $field );

            if ( ! empty( $field_data ) ) {
                $fields = array_merge( $fields, $field_data );
            }
        }

        return $fields;
    }

    /**
     * Parse a single field into header columns
     *
     * Forminator_API::get_form_fields() returns field objects with properties:
     * - slug: field identifier (e.g., 'text-1', 'name-1')
     * - type: field type (e.g., 'text', 'name', 'email')
     * - get_label_for_entry(): method to get the field label
     * - And various type-specific properties
     *
     * @param object|array $field Field data from Forminator API
     * @return array Array of element_id => label pairs
     */
    private function parse_field( $field ) {
        // Get label BEFORE converting to array (uses method on object)
        $label = '';
        if ( is_object( $field ) && method_exists( $field, 'get_label_for_entry' ) ) {
            $label = $field->get_label_for_entry();
        }

        // Handle both object and array formats
        if ( is_object( $field ) ) {
            $field = get_object_vars( $field );
        }

        // If we didn't get label from method, try properties
        if ( empty( $label ) ) {
            $label = $this->get_field_label( $field );
        }

        // Forminator uses 'slug' as the field identifier
        $element_id = $field['slug'] ?? $field['element_id'] ?? '';
        $type       = $field['type'] ?? '';

        if ( empty( $element_id ) ) {
            return array();
        }

        // Skip certain field types that don't produce data
        $skip_types = array( 'section', 'page-break', 'html', 'captcha', 'gdprconsent', 'stripe', 'paypal' );

        if ( in_array( $type, $skip_types, true ) ) {
            return array();
        }

        // Handle compound fields (name, address, etc.)
        switch ( $type ) {
            case 'name':
                return $this->parse_name_field( $field, $label );

            case 'address':
                return $this->parse_address_field( $field, $label );

            case 'time':
                return $this->parse_time_field( $field, $label );

            case 'date':
                return array( $element_id => $label ?: 'Date' );

            case 'upload':
                return array( $element_id => $label ?: 'File Upload' );

            case 'postdata':
                return $this->parse_postdata_field( $field, $label );

            case 'group':
                return $this->parse_group_field( $field );

            default:
                return array( $element_id => $label ?: ucfirst( $type ) );
        }
    }

    /**
     * Get field label from field data
     *
     * Forminator stores labels in various properties depending on field type.
     * This method checks multiple possible sources.
     *
     * @param array $field Field data
     * @return string Field label
     */
    private function get_field_label( $field ) {
        // Try different label sources - Forminator uses various property names
        $possible_keys = array(
            'field_label',
            'field-label',
            'label',
            'title',
            'placeholder',
            'name',
        );

        $label = '';

        foreach ( $possible_keys as $key ) {
            if ( ! empty( $field[ $key ] ) && is_string( $field[ $key ] ) ) {
                $label = $field[ $key ];
                break;
            }
        }

        // If still empty, use the slug/element_id as fallback
        if ( empty( $label ) ) {
            $slug = $field['slug'] ?? $field['element_id'] ?? '';
            if ( ! empty( $slug ) ) {
                // Convert slug like "text-1" to "Text 1"
                $label = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
            }
        }

        // Clean up the label
        $label = wp_strip_all_tags( $label );
        $label = trim( $label );

        return $label;
    }

    /**
     * Parse name field into components
     *
     * @param array  $field Field data
     * @param string $base_label Base label
     * @return array
     */
    private function parse_name_field( $field, $base_label ) {
        $element_id = $field['slug'] ?? $field['element_id'] ?? '';
        $prefix     = $base_label ?: 'Name';
        $columns    = array();

        // Check which components are enabled based on field settings
        // Forminator stores these as boolean flags
        $components = array(
            'prefix'      => array( 'label' => 'Prefix', 'setting' => 'prefix' ),
            'first-name'  => array( 'label' => 'First Name', 'setting' => 'fname' ),
            'middle-name' => array( 'label' => 'Middle Name', 'setting' => 'mname' ),
            'last-name'   => array( 'label' => 'Last Name', 'setting' => 'lname' ),
        );

        foreach ( $components as $key => $config ) {
            $setting_key = $config['setting'];

            // Check if this component is enabled
            // Default: first-name and last-name are typically enabled
            $is_default_enabled = in_array( $key, array( 'first-name', 'last-name' ), true );
            $is_enabled = isset( $field[ $setting_key ] ) ? ! empty( $field[ $setting_key ] ) : $is_default_enabled;

            if ( $is_enabled ) {
                $columns[ $element_id . '-' . $key ] = $prefix . ' - ' . $config['label'];
            }
        }

        // If no specific components, use single column
        if ( empty( $columns ) ) {
            return array( $element_id => $prefix );
        }

        return $columns;
    }

    /**
     * Parse address field into components
     *
     * @param array  $field Field data
     * @param string $base_label Base label
     * @return array
     */
    private function parse_address_field( $field, $base_label ) {
        $element_id = $field['slug'] ?? $field['element_id'] ?? '';
        $prefix     = $base_label ?: 'Address';

        $components = array(
            'street_address' => 'Street Address',
            'address_line'   => 'Address Line 2',
            'city'           => 'City',
            'state'          => 'State/Province',
            'zip'            => 'ZIP/Postal Code',
            'country'        => 'Country',
        );

        $columns = array();

        foreach ( $components as $key => $label ) {
            // Include all standard address components
            $columns[ $element_id . '-' . $key ] = $prefix . ' - ' . $label;
        }

        return $columns;
    }

    /**
     * Parse time field
     *
     * @param array  $field Field data
     * @param string $label Field label
     * @return array
     */
    private function parse_time_field( $field, $label ) {
        $element_id = $field['slug'] ?? $field['element_id'] ?? '';
        $prefix     = $label ?: 'Time';

        return array(
            $element_id . '-hours'   => $prefix . ' - Hours',
            $element_id . '-minutes' => $prefix . ' - Minutes',
        );
    }

    /**
     * Parse postdata field
     *
     * @param array  $field Field data
     * @param string $label Field label
     * @return array
     */
    private function parse_postdata_field( $field, $label ) {
        $element_id = $field['slug'] ?? $field['element_id'] ?? '';
        $prefix     = $label ?: 'Post';

        return array(
            $element_id . '-post-title'   => $prefix . ' - Title',
            $element_id . '-post-content' => $prefix . ' - Content',
            $element_id . '-post-excerpt' => $prefix . ' - Excerpt',
        );
    }

    /**
     * Parse group/repeater field
     *
     * @param array $field Field data
     * @return array
     */
    private function parse_group_field( $field ) {
        $element_id = $field['slug'] ?? $field['element_id'] ?? '';
        $label      = $this->get_field_label( $field ) ?: 'Group';

        // For groups, we'll store as JSON since rows can vary
        return array( $element_id => $label . ' (JSON)' );
    }

    /**
     * Build complete headers array for a form
     *
     * @param int $form_id Form ID
     * @return array Headers array
     */
    public function build_headers( $form_id ) {
        // Start with standard columns
        $headers = self::STANDARD_COLUMNS;

        // Get form fields
        $fields = $this->get_form_fields( $form_id );

        // Add field labels as headers
        foreach ( $fields as $element_id => $label ) {
            $headers[] = $label;
        }

        return $headers;
    }

    /**
     * Get field element IDs in order (for matching data to columns)
     *
     * @param int $form_id Form ID
     * @return array Array of element IDs
     */
    public function get_field_ids( $form_id ) {
        $fields = $this->get_form_fields( $form_id );
        return array_keys( $fields );
    }

    /**
     * Get field definitions with options for value-to-label mapping
     *
     * Returns field objects/arrays that include option definitions for
     * radio, checkbox, and select fields.
     *
     * @param int $form_id Form ID
     * @return array Array of field_id => field_object pairs
     */
    public function get_field_definitions( $form_id ) {
        if ( ! class_exists( 'Forminator_API' ) ) {
            return array();
        }

        $form_fields = Forminator_API::get_form_fields( $form_id );

        if ( is_wp_error( $form_fields ) || empty( $form_fields ) ) {
            return array();
        }

        $definitions = array();

        foreach ( $form_fields as $field ) {
            // Convert object to array for easier access
            $field_array = is_object( $field ) ? get_object_vars( $field ) : $field;
            $element_id  = $field_array['slug'] ?? $field_array['element_id'] ?? '';

            if ( ! empty( $element_id ) ) {
                $definitions[ $element_id ] = $field_array;
            }
        }

        return $definitions;
    }

    /**
     * Get label for a field option value
     *
     * For radio, checkbox, and select fields, converts the submitted value
     * to its corresponding label.
     *
     * @param string $field_id Field ID
     * @param mixed  $value    Submitted value
     * @param int    $form_id  Form ID
     * @return mixed Original value if not found, or the label if found
     */
    public function get_option_label( $field_id, $value, $form_id ) {
        static $field_cache = array();

        // Build cache key
        $cache_key = $form_id . '_' . $field_id;

        // Get field definition (cached)
        if ( ! isset( $field_cache[ $cache_key ] ) ) {
            $definitions = $this->get_field_definitions( $form_id );
            $field_cache[ $cache_key ] = $definitions[ $field_id ] ?? null;
        }

        $field = $field_cache[ $cache_key ];

        if ( ! $field ) {
            return $value; // Field not found, return original value
        }

        // Check if this field type has options
        $field_type = $field['type'] ?? '';
        $has_options = in_array( $field_type, array( 'radio', 'select', 'checkbox' ), true );

        if ( ! $has_options ) {
            return $value; // Not an option field, return original value
        }

        // Get options array
        $options = $field['options'] ?? array();

        if ( empty( $options ) ) {
            return $value; // No options defined
        }

        // Search for the value in options
        foreach ( $options as $option ) {
            // Handle both array and object format
            if ( is_object( $option ) ) {
                $option = get_object_vars( $option );
            }

            $option_value = $option['value'] ?? '';
            $option_label = $option['label'] ?? '';

            // Match the value and return the label
            if ( (string) $option_value === (string) $value ) {
                return $option_label;
            }
        }

        // Value not found in options, return original value
        return $value;
    }

    /**
     * Check if Forminator is active and has forms
     *
     * @return bool
     */
    public function has_forms() {
        $forms = $this->get_all_forms();
        return ! empty( $forms );
    }

    /**
     * Get summary of all forms with sync status
     *
     * @param Sheetinator_Google_Sheets $sheets Google Sheets handler
     * @return array
     */
    public function get_forms_summary( $sheets ) {
        $forms   = $this->get_all_forms();
        $summary = array();

        foreach ( $forms as $form ) {
            $form_id   = $form->id;
            $has_sheet = $sheets->has_mapping( $form_id );

            $summary[] = array(
                'id'              => $form_id,
                'title'           => $this->get_form_title( $form_id ),
                'status'          => $form->status ?? 'publish',
                'synced'          => $has_sheet,
                'spreadsheet_url' => $has_sheet ? $sheets->get_spreadsheet_url( $form_id ) : null,
                'field_count'     => count( $this->get_form_fields( $form_id ) ),
            );
        }

        return $summary;
    }
}
