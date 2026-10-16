<?php
/**
 * Gravity Forms feed add-on for HighLevel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

GFForms::include_feed_addon_framework();

class GHLFeedAddOn extends GFFeedAddOn {

	protected $_version                  = GHL_CONNECTOR_VERSION;
	protected $_min_gravityforms_version = '2.5';
	protected $_slug                     = 'ghl-connector';
	protected $_path                     = GHL_CONNECTOR_BASENAME;
	protected $_full_path                = GHL_CONNECTOR_FILE;
	protected $_title                    = 'HighLevel';
	protected $_short_title              = 'GHL';

	protected $_capabilities_settings_page = 'gravityforms_addon_settings';
	protected $_capabilities_form_settings = 'gravityforms_edit_forms';
	protected $_capabilities_uninstall     = 'gravityforms_uninstall';

	/** GHL contact properties that are sent at the top level of the payload. */
	private static $core_keys = array(
		'name', 'firstName', 'lastName', 'email', 'phone', 'companyName',
		'website', 'source', 'address1', 'city', 'state', 'postalCode', 'country',
	);

	private static $_instance = null;

	/** Lazily built API client, keyed per request. */
	private $api = null;

	/** Per-request memo for the settings-page credential indicator. */
	private $credential_check_cache = array();

	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/* =====================================================================
	 * API client
	 * ================================================================== */

	private function api() {
		if ( null === $this->api ) {
			$this->api = new GHL_API(
				$this->get_ghl_setting( 'apiKey' ),
				$this->get_ghl_setting( 'locationId' )
			);
		}
		return $this->api;
	}

	/**
	 * A connection setting for this site: its own value if one is saved,
	 * otherwise the network default (Network Admin > Settings > GHL Connector).
	 */
	public function get_ghl_setting( $key ) {
		$value = trim( (string) $this->get_plugin_setting( $key ) );
		if ( '' === $value && is_multisite() ) {
			$value = trim( (string) get_site_option( GHL_Connector_Network::option( $key ), '' ) );
		}
		return $value;
	}

	private function has_network_setting( $key ) {
		return is_multisite() && '' !== trim( (string) get_site_option( GHL_Connector_Network::option( $key ), '' ) );
	}

	private function network_setting_note( $key ) {
		if ( ! $this->has_network_setting( $key ) ) {
			return '';
		}
		return 'Leave blank to use the network setting. Enter a value only if this site uses a different one.';
	}

	/**
	 * Credentials changed, so the cached field / pipeline lists are stale.
	 */
	public function update_plugin_settings( $settings ) {
		GHL_API::flush_all_caches();
		$this->api = null;
		parent::update_plugin_settings( $settings );
	}

	public function uninstall() {
		GHL_API::flush_all_caches();
		parent::uninstall();
	}

	/* =====================================================================
	 * Plugin settings
	 * ================================================================== */

	public function plugin_settings_fields() {
		return array(
			array(
				'title'       => 'HighLevel API Setup',
				'description' => '
					<div style="background-color:#f9f9f9;padding:15px;border-left:4px solid #2271b1;margin-bottom:20px;">
						<strong>Required scopes for your Private Integration token:</strong>
						<ul style="margin-top:10px;list-style:disc;margin-left:20px;">
							<li><code>contacts.write</code> &mdash; create/update the contact</li>
							<li><code>contacts.readonly</code> &mdash; read back the contact id</li>
							<li><code>locations/customFields.readonly</code> &mdash; populate the custom field dropdown</li>
							<li><code>users.readonly</code> &mdash; only if you assign contacts to a user</li>
							<li><code>opportunities.write</code> and <code>opportunities.readonly</code> &mdash; only if you use pipeline tracking</li>
						</ul>
						<p style="margin-bottom:0;">Missing a scope makes the feed fail with a 401 or 403. Failures are written to the entry and to the Gravity Forms log, not swallowed.</p>
					</div>',
				'fields'      => array(
					array(
						'name'              => 'apiKey',
						'label'             => 'Private Integration Token',
						'type'              => 'text',
						'input_type'        => 'password',
						'class'             => 'large',
						'required'          => ! $this->has_network_setting( 'apiKey' ),
						'description'       => $this->network_setting_note( 'apiKey' ),
						'feedback_callback' => array( $this, 'validate_credentials_feedback' ),
					),
					array(
						'name'              => 'locationId',
						'label'             => 'Location ID',
						'type'              => 'text',
						'class'             => 'medium',
						'required'          => ! $this->has_network_setting( 'locationId' ),
						'description'       => $this->network_setting_note( 'locationId' ),
						'feedback_callback' => array( $this, 'validate_credentials_feedback' ),
					),
					array(
						'name'    => 'enableLogging',
						'label'   => 'Diagnostics',
						'type'    => 'checkbox',
						'choices' => array(
							array(
								'name'    => 'logSuccess',
								'label'   => 'Add a note to each entry recording the GHL contact id',
								'tooltip' => 'Useful while you are setting things up. Failures are always recorded regardless of this setting.',
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Green tick / red cross next to the credential fields.
	 */
	public function validate_credentials_feedback( $value, $field ) {
		// A blank site field falls back to the network value, so test what will actually be used.
		$api_key = 'apiKey' === rgar( $field, 'name' ) && '' !== trim( (string) $value ) ? $value : $this->get_ghl_setting( 'apiKey' );
		$loc_id  = 'locationId' === rgar( $field, 'name' ) && '' !== trim( (string) $value ) ? $value : $this->get_ghl_setting( 'locationId' );

		if ( empty( $api_key ) || empty( $loc_id ) ) {
			return null;
		}

		// Called once per credential field; only test the pair once per request.
		$cache_key = md5( $api_key . '|' . $loc_id );
		if ( isset( $this->credential_check_cache[ $cache_key ] ) ) {
			return $this->credential_check_cache[ $cache_key ];
		}

		$api    = new GHL_API( $api_key, $loc_id );
		$result = $api->validate_credentials();

		$this->credential_check_cache[ $cache_key ] = $result;

		return $result;
	}

	/* =====================================================================
	 * Choice builders
	 * ================================================================== */

	/**
	 * Grouped GHL destination fields for the mapping dropdown.
	 */
	public function get_ghl_field_choices() {
		$choices = array(
			array( 'label' => '--- DEFAULT FIELDS ---', 'value' => '', 'disabled' => true ),
			array( 'label' => 'Full Name',    'value' => 'name' ),
			array( 'label' => 'First Name',   'value' => 'firstName' ),
			array( 'label' => 'Last Name',    'value' => 'lastName' ),
			array( 'label' => 'Email',        'value' => 'email' ),
			array( 'label' => 'Phone',        'value' => 'phone' ),
			array( 'label' => 'Company Name', 'value' => 'companyName' ),
			array( 'label' => 'Website',      'value' => 'website' ),
			array( 'label' => 'Contact Source (native GHL field)', 'value' => 'source' ),

			array( 'label' => '--- ADDRESS FIELDS ---', 'value' => '', 'disabled' => true ),
			array( 'label' => 'Address Line 1',  'value' => 'address1' ),
			array( 'label' => 'City',            'value' => 'city' ),
			array( 'label' => 'State/Region',    'value' => 'state' ),
			array( 'label' => 'Zip/Postal Code', 'value' => 'postalCode' ),
			array( 'label' => 'Country',         'value' => 'country' ),
		);

		$custom_fields = $this->get_cached_custom_fields();

		if ( $custom_fields ) {
			$choices[] = array( 'label' => '--- CUSTOM FIELDS ---', 'value' => '', 'disabled' => true );
			foreach ( $custom_fields as $field ) {
				$label = $field['name'];
				if ( '' !== $field['dataType'] ) {
					$label .= ' (' . strtolower( $field['dataType'] ) . ')';
				}
				$choices[] = array( 'label' => $label, 'value' => $field['id'] );
			}
		}

		return $choices;
	}

	/**
	 * Custom fields keyed by id, so process_feed() can cast by dataType.
	 */
	private function get_custom_field_map() {
		$map = array();
		foreach ( $this->get_cached_custom_fields() as $field ) {
			$map[ $field['id'] ] = $field;
		}
		return $map;
	}

	private function get_cached_custom_fields() {
		$api = $this->api();
		if ( ! $api->is_configured() ) {
			return array();
		}
		return $api->get_custom_fields();
	}

	/**
	 * One flat dropdown of "Pipeline > Stage", value encoded as pipelineId|stageId.
	 *
	 * Gravity Forms settings fields cannot do dependent selects without custom
	 * JS, so flattening avoids a second round trip and a half-configured feed.
	 */
	public function get_pipeline_stage_choices() {
		$choices = array( array( 'label' => 'Select a pipeline stage', 'value' => '' ) );

		$api = $this->api();
		if ( ! $api->is_configured() ) {
			return $choices;
		}

		foreach ( $api->get_pipelines() as $pipeline ) {
			if ( ! $pipeline['stages'] ) {
				continue;
			}
			$choices[] = array(
				'label'    => '--- ' . strtoupper( $pipeline['name'] ) . ' ---',
				'value'    => '',
				'disabled' => true,
			);
			foreach ( $pipeline['stages'] as $stage ) {
				$choices[] = array(
					'label' => $stage['name'],
					'value' => $pipeline['id'] . '|' . $stage['id'],
				);
			}
		}

		return $choices;
	}

	/**
	 * Location users for the "Assign Contact to User" dropdown.
	 */
	public function get_ghl_user_choices() {
		$choices = array( array( 'label' => '-- Do Not Assign (Unassigned) --', 'value' => '' ) );

		$api = $this->api();
		if ( ! $api->is_configured() ) {
			return $choices;
		}

		foreach ( $api->get_users() as $user ) {
			$choices[] = array(
				'label' => $user['name'] . ( '' !== $user['email'] ? ' (' . $user['email'] . ')' : '' ),
				'value' => $user['id'],
			);
		}

		return $choices;
	}

	/**
	 * Form fields that can hold a value, for the note checklist and the
	 * user-journey selector.
	 */
	private function get_form_field_choices( $form, $prefix = '' ) {
		$choices = array();

		if ( ! $form || empty( $form['fields'] ) ) {
			return $choices;
		}

		foreach ( $form['fields'] as $field ) {
			if ( ! empty( $field->displayOnly ) ) {
				continue;
			}
			$label     = GFCommon::get_label( $field );
			$choices[] = array(
				'label' => '' !== $label ? $label : ( 'Field ' . $field->id ),
				'name'  => $prefix . $field->id,
				'value' => (string) $field->id,
			);
		}

		return $choices;
	}

	/* =====================================================================
	 * Feed settings
	 * ================================================================== */

	public function feed_settings_fields() {
		$form              = $this->get_current_form();
		$note_choices      = $this->get_form_field_choices( $form, 'note_field_' );
		$journey_choices   = array_merge(
			array(
				array( 'label' => 'Auto-detect', 'value' => 'auto' ),
				array( 'label' => 'None',        'value' => 'none' ),
			),
			$this->get_form_field_choices( $form )
		);

		$refresh_url = wp_nonce_url(
			add_query_arg( 'ghl_refresh_lists', '1' ),
			'ghl_refresh_lists',
			'_ghlnonce'
		);

		return array(
			array(
				'title'       => 'GHL Contact Mapping',
				'description' => sprintf(
					'<p>Field, pipeline and user lists are cached for 15 minutes. <a href="%s">Refresh from HighLevel</a>.</p>',
					esc_url( $refresh_url )
				),
				'fields'      => array(
					array(
						'name'     => 'feedName',
						'label'    => 'Feed Name',
						'type'     => 'text',
						'required' => true,
						'default_value' => 'HighLevel Feed',
					),
					array(
						'name'        => 'contactTags',
						'label'       => 'Automatic Tags',
						'type'        => 'text',
						'class'       => 'large',
						'merge_tags'  => true,
						'description' => 'Separate multiple tags with a comma (e.g. <strong>Website Lead, Google Ads</strong>). Merge tags are supported, so <code>{Service:5}</code> will tag by form answer.',
						'tooltip'     => 'Extra spaces are trimmed and blank tags are dropped. Tags are only sent when at least one is non-empty, so an empty value never clears existing tags in GHL.',
					),
					array(
						'name'    => 'assignedUserId',
						'label'   => 'Assign Contact to User',
						'type'    => 'select',
						'choices' => $this->get_ghl_user_choices(),
						'tooltip' => 'Select a user to own this lead in HighLevel. Needs the users.readonly scope to list users.',
					),
					array(
						'name'       => 'mappedFields',
						'label'      => 'Map Fields',
						'type'       => 'generic_map',
						'required'   => true,
						'key_field'  => array(
							'title'   => 'HighLevel Field',
							'choices' => $this->get_ghl_field_choices(),
						),
						'value_field' => array(
							'title' => 'Gravity Forms Field',
						),
						'validation_callback' => array( $this, 'validate_mapped_fields' ),
					),
				),
			),
			array(
				'title'       => 'Note Checklist (Grouped Form Data)',
				'description' => '<p>Ticked fields are written into a single note on the contact. Blank answers are skipped.</p>',
				'fields'      => array(
					array(
						'name'    => 'noteFieldsCheckbox',
						'label'   => 'Include in Note',
						'type'    => 'checkbox',
						'choices' => $note_choices,
					),
					array(
						'name'          => 'journeyField',
						'label'         => 'User Journey Field',
						'type'          => 'select',
						'default_value' => 'auto',
						'choices'       => $journey_choices,
						'description'   => 'The hidden field that Campaign Tracker fills with the user journey. It gets reformatted into a readable section at the bottom of the note instead of being dumped as one long line.',
						'tooltip'       => 'Auto-detect recognizes both the Campaign Tracker plain-text format and the older raw cookie JSON. Choose the field explicitly if auto-detect guesses wrong.',
					),
					array(
						'name'    => 'includeAttribution',
						'label'   => 'Lead Source',
						'type'    => 'checkbox',
						'choices' => array(
							array(
								'name'  => 'includeAttribution',
								'label' => 'Add a LEAD SOURCE section from Sebo Attribution (channel, UTM tags, click IDs, landing page)',
							),
						),
						'description' => defined( 'SEBO_ATTR_PREFIX' )
							? 'To put a value in its own GHL custom field instead, map it above: the "Attribution: …" entries in the Gravity Forms Field list.'
							: 'Sebo Attribution is not active on this site, so this section will be empty.',
					),
				),
			),
			array(
				'title'       => 'Pipeline Tracking (Opportunity)',
				'description' => '<p>Optional. Creates an Opportunity so the lead appears in a pipeline instead of sitting in Contacts. Requires the <code>opportunities.write</code> scope.</p>',
				'fields'      => array(
					array(
						'name'    => 'createOpportunity',
						'label'   => 'Create Opportunity',
						'type'    => 'checkbox',
						'choices' => array(
							array(
								'name'  => 'createOpportunity',
								'label' => 'Add this lead to a pipeline',
							),
						),
					),
					array(
						'name'       => 'pipelineStage',
						'label'      => 'Pipeline &amp; Stage',
						'type'       => 'select',
						'choices'    => $this->get_pipeline_stage_choices(),
						'dependency' => array( 'field' => 'createOpportunity', 'values' => array( '1' ) ),
					),
					array(
						'name'        => 'opportunityName',
						'label'       => 'Opportunity Name',
						'type'        => 'text',
						'class'       => 'large',
						'merge_tags'  => true,
						'description' => 'Leave blank to use the contact name.',
						'dependency'  => array( 'field' => 'createOpportunity', 'values' => array( '1' ) ),
					),
					array(
						'name'          => 'opportunityStatus',
						'label'         => 'Status',
						'type'          => 'select',
						'default_value' => 'open',
						'choices'       => array(
							array( 'label' => 'Open',      'value' => 'open' ),
							array( 'label' => 'Won',       'value' => 'won' ),
							array( 'label' => 'Lost',      'value' => 'lost' ),
							array( 'label' => 'Abandoned', 'value' => 'abandoned' ),
						),
						'dependency'    => array( 'field' => 'createOpportunity', 'values' => array( '1' ) ),
					),
					array(
						'name'        => 'monetaryValue',
						'label'       => 'Monetary Value',
						'type'        => 'text',
						'class'       => 'small',
						'merge_tags'  => true,
						'description' => 'Optional. A number or a merge tag; currency symbols and commas are stripped.',
						'dependency'  => array( 'field' => 'createOpportunity', 'values' => array( '1' ) ),
					),
				),
			),
			array(
				'title'  => 'Conditions',
				'fields' => array(
					array(
						'name'  => 'feed_condition',
						'label' => 'Logic',
						'type'  => 'feed_condition',
					),
				),
			),
		);
	}

	/**
	 * GHL rejects an upsert with neither email nor phone, so catch it at
	 * save time rather than losing leads at submission time.
	 */
	public function validate_mapped_fields( $field, $value ) {
		$mappings = $this->get_setting( 'mappedFields' );
		if ( ! is_array( $mappings ) ) {
			return;
		}

		$has_identifier = false;
		foreach ( $mappings as $mapping ) {
			$key = rgar( $mapping, 'key' );
			if ( in_array( $key, array( 'email', 'phone' ), true ) && '' !== rgar( $mapping, 'value' ) ) {
				$has_identifier = true;
				break;
			}
		}

		if ( ! $has_identifier ) {
			$this->set_field_error( $field, 'Map at least an Email or a Phone field. HighLevel cannot create a contact without one of them.' );
		}
	}

	public function feed_list_columns() {
		return array(
			'feedName'    => 'Name',
			'contactTags' => 'Tags',
			'opportunity' => 'Pipeline',
		);
	}

	public function get_column_value_opportunity( $feed ) {
		if ( ! rgars( $feed, 'meta/createOpportunity' ) ) {
			return '&mdash;';
		}

		$stage = rgars( $feed, 'meta/pipelineStage' );
		if ( ! $stage ) {
			return 'Not configured';
		}

		list( $pipeline_id, $stage_id ) = array_pad( explode( '|', $stage ), 2, '' );

		foreach ( $this->api()->get_pipelines() as $pipeline ) {
			if ( $pipeline['id'] !== $pipeline_id ) {
				continue;
			}
			foreach ( $pipeline['stages'] as $pipeline_stage ) {
				if ( $pipeline_stage['id'] === $stage_id ) {
					return esc_html( $pipeline['name'] . ' > ' . $pipeline_stage['name'] );
				}
			}
		}

		return 'Stage not found';
	}

	/* =====================================================================
	 * Feed processing
	 * ================================================================== */

	public function process_feed( $feed, $entry, $form ) {
		$api = $this->api();

		if ( ! $api->is_configured() ) {
			$this->add_feed_error( 'HighLevel is not configured: add the Private Integration Token and Location ID in Forms > Settings > GHL (or, on a multisite, Network Admin > Settings > GHL Connector).', $feed, $entry, $form );
			return;
		}

		$meta = rgar( $feed, 'meta', array() );

		$contact = $this->build_contact_payload( $meta, $entry, $form );

		if ( '' === rgar( $contact, 'email', '' ) && '' === rgar( $contact, 'phone', '' ) ) {
			$this->add_feed_error( 'Skipped: the submission produced neither an email nor a phone number, and HighLevel requires one of them.', $feed, $entry, $form );
			return;
		}

		$this->log_debug( __METHOD__ . '(): contact payload => ' . wp_json_encode( $contact ) );

		$result = $api->upsert_contact( $contact );

		if ( null === $result ) {
			$this->add_feed_error(
				'Contact upsert failed. ' . $api->get_last_error_summary(),
				$feed,
				$entry,
				$form
			);
			$this->log_error( __METHOD__ . '(): upsert failed. Response body => ' . $api->get_last_body() );
			return;
		}

		$contact_id = rgar( $result, 'id' );
		if ( ! $contact_id ) {
			$this->add_feed_error( 'Contact upsert returned no contact id, so the note and opportunity were skipped.', $feed, $entry, $form );
			return;
		}

		gform_update_meta( $entry['id'], 'ghl_contact_id', $contact_id, $form['id'] );

		if ( $this->get_plugin_setting( 'logSuccess' ) ) {
			$this->add_note( $entry['id'], 'HighLevel contact synced. Contact ID: ' . $contact_id, 'success' );
		}

		$this->maybe_add_note( $api, $contact_id, $meta, $entry, $form, $feed );
		$this->maybe_create_opportunity( $api, $contact_id, $contact, $meta, $entry, $form, $feed );
	}

	/**
	 * Build the /contacts/upsert body from the feed mapping.
	 */
	private function build_contact_payload( $meta, $entry, $form ) {
		$contact       = array();
		$custom_fields = array();

		// Loaded on first use only. A feed that maps nothing but core fields
		// should not spend an HTTP round trip on metadata mid-submission.
		$field_map = null;

		$mappings = rgar( $meta, 'mappedFields' );

		if ( is_array( $mappings ) ) {
			foreach ( $mappings as $mapping ) {
				$key = rgar( $mapping, 'key' );
				if ( '' === $key || null === $key ) {
					continue;
				}

				$value = $this->get_field_value( $form, $entry, rgar( $mapping, 'value' ) );

				// Deliberately not empty(): "0" is a legitimate answer.
				if ( null === $value || '' === $value || array() === $value ) {
					continue;
				}

				if ( in_array( $key, self::$core_keys, true ) ) {
					$contact[ $key ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
					continue;
				}

				if ( null === $field_map ) {
					$field_map = $this->get_custom_field_map();
				}

				$data_type = isset( $field_map[ $key ]['dataType'] ) ? $field_map[ $key ]['dataType'] : '';
				$cast      = $this->cast_custom_field_value( $value, $data_type );

				if ( null === $cast || '' === $cast || array() === $cast ) {
					continue;
				}

				// Documented v2 pairing is id + value.
				$custom_fields[] = array(
					'id'    => $key,
					'value' => $cast,
				);
			}
		}

		// Only send the keys we actually populated; an empty array can clear
		// data that is already on the contact.
		if ( $custom_fields ) {
			$contact['customFields'] = $custom_fields;
		}

		$tags = $this->build_tags( $meta, $entry, $form );
		if ( $tags ) {
			$contact['tags'] = $tags;
		}

		$assigned_to = (string) rgar( $meta, 'assignedUserId' );
		if ( '' !== $assigned_to ) {
			$contact['assignedTo'] = $assigned_to;
		}

		return $contact;
	}

	private function build_tags( $meta, $entry, $form ) {
		$raw = (string) rgar( $meta, 'contactTags' );
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$raw  = GFCommon::replace_variables( $raw, $form, $entry, false, false, false, 'text' );
		$tags = array_map( 'trim', explode( ',', $raw ) );
		$tags = array_filter( $tags, 'strlen' );

		return array_values( array_unique( $tags ) );
	}

	/**
	 * GHL custom fields are typed; sending a string to a numeric or
	 * multi-select field is a common cause of values silently not landing.
	 */
	private function cast_custom_field_value( $value, $data_type ) {
		if ( is_array( $value ) ) {
			$value = implode( ',', $value );
		}
		$value = (string) $value;

		switch ( strtoupper( $data_type ) ) {
			case 'CHECKBOX':
			case 'MULTIPLE_OPTIONS':
				$parts = array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' );
				return array_values( $parts );

			case 'NUMERICAL':
			case 'MONETORY':
			case 'MONETARY':
				$clean = preg_replace( '/[^0-9.\-]/', '', $value );
				return '' === $clean ? null : (float) $clean;

			default:
				return $value;
		}
	}

	/* =====================================================================
	 * Note
	 * ================================================================== */

	private function maybe_add_note( $api, $contact_id, $meta, $entry, $form, $feed ) {
		$journey_field_id = rgar( $meta, 'journeyField', 'auto' );

		$data_lines   = array();
		$journey_body = '';
		$source_lines = rgar( $meta, 'includeAttribution' ) ? $this->attribution_lines( $entry ) : array();

		foreach ( $form['fields'] as $field ) {
			if ( ! rgar( $meta, 'note_field_' . $field->id ) ) {
				continue;
			}

			$value = $this->get_field_value( $form, $entry, $field->id );
			if ( is_array( $value ) ) {
				$value = implode( ', ', $value );
			}
			$value = trim( (string) $value );

			if ( '' === $value ) {
				continue;
			}

			$label     = GFCommon::get_label( $field );
			$is_journey = $this->is_journey_field( $field, $value, $journey_field_id );

			if ( $is_journey && '' === $journey_body ) {
				$journey_body = $this->format_journey( $value );
				if ( '' !== $journey_body ) {
					continue;
				}
			}

			$data_lines[] = $label . ': ' . $value;
		}

		if ( ! $data_lines && '' === $journey_body && ! $source_lines ) {
			return;
		}

		$sections = array();
		if ( $data_lines ) {
			$sections[] = "FORM DATA:\n" . implode( "\n", $data_lines );
		}
		if ( $source_lines ) {
			$sections[] = "LEAD SOURCE:\n" . implode( "\n", $source_lines );
		}
		if ( '' !== $journey_body ) {
			$sections[] = "USER JOURNEY:\n" . $journey_body;
		}

		$note = implode( "\n\n", $sections );

		if ( null === $api->add_note( $contact_id, $note ) ) {
			$this->add_feed_error(
				'Contact synced but the note failed. ' . $api->get_last_error_summary(),
				$feed,
				$entry,
				$form
			);
		}
	}

	/**
	 * Sebo Attribution values saved on the entry, as "key: value" lines.
	 */
	private function attribution_lines( $entry ) {
		if ( ! defined( 'SEBO_ATTR_PREFIX' ) || ! function_exists( 'sebo_attr_catalog' ) ) {
			return array();
		}

		$lines = array();
		foreach ( array_keys( sebo_attr_catalog() ) as $key ) {
			$value = trim( (string) rgar( $entry, SEBO_ATTR_PREFIX . $key ) );
			if ( '' !== $value ) {
				$lines[] = $key . ': ' . $value;
			}
		}
		return $lines;
	}

	private function is_journey_field( $field, $value, $journey_field_id ) {
		if ( 'none' === $journey_field_id ) {
			return false;
		}

		if ( 'auto' !== $journey_field_id ) {
			return (string) $field->id === (string) $journey_field_id;
		}

		// Auto-detect: raw cookie JSON, or Campaign Tracker's pipe-delimited lines.
		if ( false !== strpos( $value, 'posts_id' ) ) {
			return true;
		}

		return $this->looks_like_campaign_tracker_journey( $value );
	}

	private function looks_like_campaign_tracker_journey( $value ) {
		$lines = preg_split( '/\r\n|\r|\n/', trim( $value ) );

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			// "2026 September 04 13:44:02 | page | Title | URL | 3 mins"
			if ( count( explode( '|', $line ) ) >= 5 && preg_match( '/^\d{4}\s+\w+\s+\d{1,2}\s+\d{1,2}:\d{2}/', $line ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reformat a journey value into readable bullets.
	 *
	 * Handles Campaign Tracker's plain-text output (the current format) and the
	 * older raw cookie JSON. Returns '' when the value is neither, so the caller
	 * can fall back to printing it as an ordinary field.
	 */
	private function format_journey( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		if ( false !== strpos( $value, 'posts_id' ) ) {
			$json = $this->format_journey_from_json( $value );
			if ( '' !== $json ) {
				return $json;
			}
		}

		if ( ! $this->looks_like_campaign_tracker_journey( $value ) ) {
			return '';
		}

		$bullets = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $value ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}

			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) < 5 ) {
				$bullets[] = '- ' . $line;
				continue;
			}

			list( $when, $type, $title, $url, $stay ) = array( $parts[0], $parts[1], $parts[2], $parts[3], $parts[4] );

			$bullet = '- ' . ( '' !== $title ? $title : 'Untitled' );
			if ( '' !== $when ) {
				$bullet .= ' | ' . $when;
			}
			if ( '' !== $stay ) {
				$bullet .= ' | ' . $stay;
			}
			if ( '' !== $url ) {
				$bullet .= "\n  " . $url;
			}

			$bullets[] = $bullet;
		}

		return implode( "\n", $bullets );
	}

	private function format_journey_from_json( $value ) {
		$decoded = json_decode( $value, true );

		if ( ! is_array( $decoded ) || empty( $decoded['posts_id'] ) || ! is_array( $decoded['posts_id'] ) ) {
			return '';
		}

		$timezone = wp_timezone();
		$bullets  = array();

		foreach ( $decoded['posts_id'] as $step ) {
			$title  = isset( $step['title'] ) ? (string) $step['title'] : 'Untitled';
			$bullet = '- ' . $title;

			if ( ! empty( $step['timestamp'] ) ) {
				$date = new DateTime( '@' . (int) $step['timestamp'] );
				$date->setTimezone( $timezone );
				$bullet .= ' | ' . $date->format( 'M j, Y g:i a' );
			}

			$bullets[] = $bullet;
		}

		return implode( "\n", $bullets );
	}

	/* =====================================================================
	 * Opportunity
	 * ================================================================== */

	private function maybe_create_opportunity( $api, $contact_id, $contact, $meta, $entry, $form, $feed ) {
		if ( ! rgar( $meta, 'createOpportunity' ) ) {
			return;
		}

		$stage = (string) rgar( $meta, 'pipelineStage' );
		if ( '' === $stage || false === strpos( $stage, '|' ) ) {
			$this->add_feed_error( 'Pipeline tracking is enabled but no pipeline stage is selected, so no opportunity was created.', $feed, $entry, $form );
			return;
		}

		list( $pipeline_id, $stage_id ) = array_pad( explode( '|', $stage ), 2, '' );

		if ( '' === $pipeline_id || '' === $stage_id ) {
			$this->add_feed_error( 'Pipeline stage setting is malformed; re-save the feed. No opportunity was created.', $feed, $entry, $form );
			return;
		}

		$name = trim( (string) rgar( $meta, 'opportunityName' ) );
		if ( '' !== $name ) {
			$name = GFCommon::replace_variables( $name, $form, $entry, false, false, false, 'text' );
		}
		if ( '' === trim( $name ) ) {
			$name = $this->derive_contact_name( $contact );
		}

		$body = array(
			'pipelineId'      => $pipeline_id,
			'pipelineStageId' => $stage_id,
			'contactId'       => $contact_id,
			'name'            => $name,
			'status'          => rgar( $meta, 'opportunityStatus', 'open' ),
		);

		$monetary = trim( (string) rgar( $meta, 'monetaryValue' ) );
		if ( '' !== $monetary ) {
			$monetary = GFCommon::replace_variables( $monetary, $form, $entry, false, false, false, 'text' );
			$clean    = preg_replace( '/[^0-9.\-]/', '', $monetary );
			if ( '' !== $clean ) {
				$body['monetaryValue'] = (float) $clean;
			}
		}

		if ( ! empty( $contact['source'] ) ) {
			$body['source'] = $contact['source'];
		}

		$this->log_debug( __METHOD__ . '(): opportunity payload => ' . wp_json_encode( $body ) );

		if ( null === $api->create_opportunity( $body ) ) {
			$this->add_feed_error(
				'Contact synced but the opportunity failed. ' . $api->get_last_error_summary() . ' Check that the token has the opportunities.write scope.',
				$feed,
				$entry,
				$form
			);
			$this->log_error( __METHOD__ . '(): opportunity failed. Response body => ' . $api->get_last_body() );
		}
	}

	private function derive_contact_name( $contact ) {
		if ( ! empty( $contact['name'] ) ) {
			return $contact['name'];
		}

		$name = trim( rgar( $contact, 'firstName', '' ) . ' ' . rgar( $contact, 'lastName', '' ) );
		if ( '' !== $name ) {
			return $name;
		}

		if ( ! empty( $contact['email'] ) ) {
			return $contact['email'];
		}
		if ( ! empty( $contact['phone'] ) ) {
			return $contact['phone'];
		}

		return 'Website Lead';
	}
}
