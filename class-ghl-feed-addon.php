<?php
GFForms::include_feed_addon_framework();

class GHLFeedAddOn extends GFFeedAddOn {

    protected $_version = '2.2';
    protected $_min_gravityforms_version = '2.5';
    protected $_slug = 'ghl-connector';
    protected $_path = 'ghl-connector/ghl-connector.php';
    protected $_full_path = __FILE__;
    protected $_title = 'HighLevel';
    protected $_short_title = 'GHL';

    private static $_instance = null;

    public static function get_instance() {
        if ( self::$_instance == null ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function plugin_settings_fields() {
        return array(
            array(
                'title'  => 'HighLevel API Setup Instructions',
                'description' => '
                    <div style="background-color: #f9f9f9; padding: 15px; border-left: 4px solid #2271b1; margin-bottom: 20px;">
                        <strong>Required Scopes for Private Integration:</strong><br>
                        Ensure your GHL API Token has these scopes enabled or the feed will fail:
                        <ul style="margin-top: 10px; list-style: disc; margin-left: 20px;">
                            <li><code>contacts.write</code></li>
                            <li><code>contacts.readonly</code></li>
                            <li><code>locations/customFields.readonly</code></li>
                        </ul>
                    </div>',
                'fields' => array(
                    array( 'name' => 'apiKey', 'label' => 'Private Integration Token', 'type' => 'text', 'class' => 'large', 'required' => true ),
                    array( 'name' => 'locationId', 'label' => 'Location ID', 'type' => 'text', 'class' => 'medium', 'required' => true ),
                ),
            ),
        );
    }

    public function get_ghl_field_choices() {
        $choices = array(
            array( 'label' => '--- DEFAULT FIELDS ---', 'value' => '', 'disabled' => true ),
            array( 'label' => 'Full Name', 'value' => 'name' ),
            array( 'label' => 'First Name', 'value' => 'firstName' ),
            array( 'label' => 'Last Name', 'value' => 'lastName' ),
            array( 'label' => 'Email', 'value' => 'email' ),
            array( 'label' => 'Phone', 'value' => 'phone' ),
            array( 'label' => 'Company Name', 'value' => 'companyName' ),
            array( 'label' => 'Website', 'value' => 'website' ),
            array( 'label' => '--- ADDRESS FIELDS ---', 'value' => '', 'disabled' => true ),
            array( 'label' => 'Address Line 1', 'value' => 'address1' ),
            array( 'label' => 'City', 'value' => 'city' ),
            array( 'label' => 'State/Region', 'value' => 'state' ),
            array( 'label' => 'Zip/Postal Code', 'value' => 'postalCode' ),
            array( 'label' => 'Country', 'value' => 'country' ),
        );

        $api_key = $this->get_plugin_setting( 'apiKey' );
        $loc_id  = $this->get_plugin_setting( 'locationId' );

        if ( !empty($api_key) && !empty($loc_id) ) {
            $response = wp_remote_get( "https://services.leadconnectorhq.com/locations/{$loc_id}/customFields?model=contact", array(
                'headers' => array( 'Authorization' => 'Bearer ' . $api_key, 'Version' => '2021-07-28' ),
                'timeout' => 10
            ) );

            if ( !is_wp_error($response) ) {
                $body = json_decode( wp_remote_retrieve_body($response), true );
                if ( !empty($body['customFields']) ) {
                    $choices[] = array( 'label' => '--- CUSTOM FIELDS ---', 'value' => '', 'disabled' => true );
                    foreach ( $body['customFields'] as $f ) {
                        $choices[] = array( 'label' => $f['name'], 'value' => $f['id'] );
                    }
                }
            }
        }
        return $choices;
    }

    public function feed_settings_fields() {
        $form_id = rgget( 'id' );
        $form    = GFAPI::get_form( $form_id );
        $form_fields = array();

        if ( $form ) {
            foreach ( $form['fields'] as $field ) {
                if ( $field->displayOnly ) continue;
                $form_fields[] = array( 'label' => $field->label, 'name' => 'note_field_' . $field->id );
            }
        }

        return array(
            array(
                'title'  => 'GHL Contact Mapping',
                'fields' => array(
                    array( 'name' => 'feedName', 'label' => 'Feed Name', 'type' => 'text', 'required' => true ),
                    array( 
                        'name'        => 'contactTags', 
                        'label'       => 'Automatic Tags', 
                        'type'        => 'text', 
                        'description' => 'Separate multiple tags with a comma (e.g., <strong>Website Lead, Google Ads</strong>).',
                    ),
                    array(
                        'name'      => 'mappedFields',
                        'label'     => 'Map Fields',
                        'type'      => 'generic_map',
                        'key_field' => array(
                            'title'   => 'HighLevel Field',
                            'choices' => $this->get_ghl_field_choices(),
                        ),
                    ),
                ),
            ),
            array(
                'title'  => 'Note Checklist (Grouped Form Data)',
                'fields' => array(
                    array( 'name' => 'noteFieldsCheckbox', 'label' => 'Include in Note', 'type' => 'checkbox', 'choices' => $form_fields ),
                ),
            ),
            array( 'title'  => 'Conditions', 'fields' => array( array( 'name' => 'feed_condition', 'label' => 'Logic', 'type' => 'feed_condition' ) ) ),
        );
    }

    public function process_feed( $feed, $entry, $form ) {
        $api_key = $this->get_plugin_setting( 'apiKey' );
        $loc_id  = $this->get_plugin_setting( 'locationId' );
        $meta    = $feed['meta'];

        if ( empty( $api_key ) || empty( $loc_id ) ) return;

        $body = array( 'locationId' => $loc_id, 'customFields' => array(), 'tags' => array() );
        
        if ( ! empty( rgar( $meta, 'contactTags' ) ) ) {
            $body['tags'] = array_map( 'trim', explode( ',', $meta['contactTags'] ) );
        }

        $core_keys = array( 'name', 'firstName', 'lastName', 'email', 'phone', 'companyName', 'website', 'address1', 'city', 'state', 'postalCode', 'country' );
        $mappings  = rgar( $meta, 'mappedFields' );
        
        if ( is_array( $mappings ) ) {
            foreach ( $mappings as $m ) {
                $key = rgar( $m, 'key' );
                if ( empty($key) ) continue;
                $val = $this->get_field_value( $form, $entry, rgar( $m, 'value' ) );
                if ( empty($val) ) continue;

                if ( in_array( $key, $core_keys ) ) {
                    $body[$key] = $val;
                } else {
                    $body['customFields'][] = array( 'id' => $key, 'field_value' => (string)$val );
                }
            }
        }

        $tz = wp_timezone();
        $notes = array();
        $journey_text = "";

        foreach ( $form['fields'] as $field ) {
            if ( ! rgar( $meta, 'note_field_' . $field->id ) ) continue;
            $val = $this->get_field_value( $form, $entry, $field->id );
            if ( empty($val) ) continue;

            if ( strpos( $val, 'posts_id' ) !== false ) {
                $j = json_decode( $val, true );
                $journey_text = "\n--- USER JOURNEY ---\n";
                foreach ( array_reverse($j['posts_id']) as $p ) {
                    $d = new DateTime("@" . $p['timestamp']);
                    $d->setTimezone($tz);
                    $journey_text .= "• {$p['title']} - " . $d->format("M j, g:i a") . "\n";
                }
            } else { $notes[] = "{$field->label}: {$val}"; }
        }

        $resp = wp_remote_post( 'https://services.leadconnectorhq.com/contacts/upsert', array(
            'headers' => array( 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json', 'Version' => '2021-07-28' ),
            'body'    => json_encode( $body ),
            'timeout' => 25
        ) );

        $res_body = json_decode( wp_remote_retrieve_body($resp), true );
        $cid = $res_body['contact']['id'] ?? null;

        if ( $cid && (!empty($notes) || !empty($journey_text)) ) {
            $note_body = ( !empty($notes) ? "FORM DATA:\n" . implode("\n", $notes) : "" ) . $journey_text;
            wp_remote_post( "https://services.leadconnectorhq.com/contacts/{$cid}/notes", array(
                'headers' => array( 'Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json', 'Version' => '2021-07-28' ),
                'body'    => json_encode( array( 'body' => $note_body ) )
            ) );
        }
    }

    public function feed_list_columns() { return array( 'feedName' => 'Name' ); }
}
