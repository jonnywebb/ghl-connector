<?php
/**
 * Thin HighLevel (LeadConnector) API v2 client.
 *
 * Every call returns a decoded array on success or null on failure. When it
 * returns null, get_last_error() / get_last_status() / get_last_body() explain
 * why, so the caller can surface something useful instead of failing silently.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GHL_API {

	const BASE_URL    = 'https://services.leadconnectorhq.com';
	const API_VERSION = '2021-07-28';
	const CACHE_TTL   = 900; // 15 minutes.

	/** Transient prefixes, tracked so flush_all_caches() can clear them. */
	const CACHE_PREFIX_FIELDS    = 'ghl_conn_fields_';
	const CACHE_PREFIX_PIPELINES = 'ghl_conn_pipes_';
	const CACHE_PREFIX_USERS     = 'ghl_conn_users_';

	private $api_key;
	private $location_id;

	private $last_error  = '';
	private $last_status = 0;
	private $last_body   = '';

	public function __construct( $api_key, $location_id ) {
		$this->api_key     = trim( (string) $api_key );
		$this->location_id = trim( (string) $location_id );
	}

	public function is_configured() {
		return '' !== $this->api_key && '' !== $this->location_id;
	}

	public function get_location_id() {
		return $this->location_id;
	}

	public function get_last_error() {
		return $this->last_error;
	}

	public function get_last_status() {
		return $this->last_status;
	}

	public function get_last_body() {
		return $this->last_body;
	}

	/**
	 * Short, log-safe description of the most recent failure.
	 */
	public function get_last_error_summary() {
		$parts = array();
		if ( $this->last_status ) {
			$parts[] = 'HTTP ' . $this->last_status;
		}
		if ( '' !== $this->last_error ) {
			$parts[] = $this->last_error;
		}
		if ( ! $parts ) {
			$parts[] = 'Unknown error.';
		}
		return implode( ' - ', $parts );
	}

	private function headers() {
		return array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'Version'       => self::API_VERSION,
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
		);
	}

	/**
	 * One request, retried once on 429 / 5xx / transport error.
	 *
	 * Runs during form submission, so the timeout stays low on purpose: a
	 * slow GHL response should not hold the visitor on a spinning page.
	 */
	public function request( $method, $path, $body = null, $timeout = 15 ) {
		$this->last_error  = '';
		$this->last_status = 0;
		$this->last_body   = '';

		$args = array(
			'method'  => strtoupper( $method ),
			'headers' => $this->headers(),
			'timeout' => $timeout,
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$url = self::BASE_URL . $path;

		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$this->last_error = $response->get_error_message();
				if ( 1 === $attempt ) {
					sleep( 1 );
					continue;
				}
				return null;
			}

			$this->last_status = (int) wp_remote_retrieve_response_code( $response );
			$this->last_body   = (string) wp_remote_retrieve_body( $response );
			$decoded           = json_decode( $this->last_body, true );

			if ( $this->last_status >= 200 && $this->last_status < 300 ) {
				return is_array( $decoded ) ? $decoded : array();
			}

			if ( 1 === $attempt && ( 429 === $this->last_status || $this->last_status >= 500 ) ) {
				sleep( 2 );
				continue;
			}

			$this->last_error = $this->extract_error_message( $decoded );
			return null;
		}

		return null;
	}

	/**
	 * GHL returns errors as {message: string|string[], error: string}.
	 */
	private function extract_error_message( $decoded ) {
		if ( ! is_array( $decoded ) ) {
			return '' !== $this->last_body ? substr( $this->last_body, 0, 300 ) : 'Empty response.';
		}

		if ( isset( $decoded['message'] ) ) {
			if ( is_array( $decoded['message'] ) ) {
				return implode( '; ', array_map( 'strval', $decoded['message'] ) );
			}
			return (string) $decoded['message'];
		}

		if ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
			return $decoded['error'];
		}

		return substr( $this->last_body, 0, 300 );
	}

	/* ---------------------------------------------------------------------
	 * Contacts
	 * ------------------------------------------------------------------ */

	/**
	 * Upsert a contact. Returns the contact array (including 'id') or null.
	 */
	public function upsert_contact( array $body ) {
		$body['locationId'] = $this->location_id;

		$result = $this->request( 'POST', '/contacts/upsert', $body, 20 );
		if ( null === $result ) {
			return null;
		}

		// Response shape has moved around between GHL releases; accept all of them.
		if ( isset( $result['contact'] ) && is_array( $result['contact'] ) ) {
			return $result['contact'];
		}
		if ( isset( $result['id'] ) ) {
			return $result;
		}
		if ( isset( $result['contactId'] ) ) {
			return array( 'id' => $result['contactId'] );
		}

		$this->last_error = 'Upsert succeeded but no contact id was returned.';
		return null;
	}

	public function add_note( $contact_id, $note_body ) {
		return $this->request(
			'POST',
			'/contacts/' . rawurlencode( $contact_id ) . '/notes',
			array( 'body' => $note_body ),
			15
		);
	}

	/* ---------------------------------------------------------------------
	 * Opportunities
	 * ------------------------------------------------------------------ */

	public function create_opportunity( array $body ) {
		$body['locationId'] = $this->location_id;
		return $this->request( 'POST', '/opportunities/', $body, 20 );
	}

	/* ---------------------------------------------------------------------
	 * Metadata (cached)
	 * ------------------------------------------------------------------ */

	/**
	 * Contact custom fields, as array of array('id','name','dataType').
	 */
	public function get_custom_fields( $force = false ) {
		$key = self::CACHE_PREFIX_FIELDS . $this->cache_suffix();

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$result = $this->request(
			'GET',
			'/locations/' . rawurlencode( $this->location_id ) . '/customFields?model=contact',
			null,
			12
		);

		if ( null === $result ) {
			return array();
		}

		$fields = array();
		$raw    = isset( $result['customFields'] ) && is_array( $result['customFields'] ) ? $result['customFields'] : array();

		foreach ( $raw as $field ) {
			if ( empty( $field['id'] ) ) {
				continue;
			}
			$fields[] = array(
				'id'       => (string) $field['id'],
				'name'     => isset( $field['name'] ) ? (string) $field['name'] : (string) $field['id'],
				'dataType' => isset( $field['dataType'] ) ? (string) $field['dataType'] : '',
			);
		}

		set_transient( $key, $fields, self::CACHE_TTL );
		$this->remember_cache_key( $key );

		return $fields;
	}

	/**
	 * Pipelines, as array of array('id','name','stages'=>array('id','name')).
	 */
	public function get_pipelines( $force = false ) {
		$key = self::CACHE_PREFIX_PIPELINES . $this->cache_suffix();

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$result = $this->request(
			'GET',
			'/opportunities/pipelines?locationId=' . rawurlencode( $this->location_id ),
			null,
			12
		);

		if ( null === $result ) {
			return array();
		}

		$pipelines = array();
		$raw       = isset( $result['pipelines'] ) && is_array( $result['pipelines'] ) ? $result['pipelines'] : array();

		foreach ( $raw as $pipeline ) {
			if ( empty( $pipeline['id'] ) ) {
				continue;
			}

			$stages = array();
			if ( ! empty( $pipeline['stages'] ) && is_array( $pipeline['stages'] ) ) {
				foreach ( $pipeline['stages'] as $stage ) {
					if ( empty( $stage['id'] ) ) {
						continue;
					}
					$stages[] = array(
						'id'   => (string) $stage['id'],
						'name' => isset( $stage['name'] ) ? (string) $stage['name'] : (string) $stage['id'],
					);
				}
			}

			$pipelines[] = array(
				'id'     => (string) $pipeline['id'],
				'name'   => isset( $pipeline['name'] ) ? (string) $pipeline['name'] : (string) $pipeline['id'],
				'stages' => $stages,
			);
		}

		set_transient( $key, $pipelines, self::CACHE_TTL );
		$this->remember_cache_key( $key );

		return $pipelines;
	}

	/**
	 * Users on the location, as array of array('id','name','email').
	 * Needs the users.readonly scope; without it the list is empty.
	 */
	public function get_users( $force = false ) {
		$key = self::CACHE_PREFIX_USERS . $this->cache_suffix();

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$result = $this->request(
			'GET',
			'/users/?locationId=' . rawurlencode( $this->location_id ),
			null,
			12
		);

		if ( null === $result ) {
			return array();
		}

		$users = array();
		$raw   = isset( $result['users'] ) && is_array( $result['users'] ) ? $result['users'] : array();

		foreach ( $raw as $user ) {
			if ( empty( $user['id'] ) ) {
				continue;
			}
			$name = isset( $user['name'] ) ? trim( (string) $user['name'] ) : '';
			if ( '' === $name ) {
				$name = trim( rgar( $user, 'firstName', '' ) . ' ' . rgar( $user, 'lastName', '' ) );
			}
			$users[] = array(
				'id'    => (string) $user['id'],
				'name'  => '' !== $name ? $name : (string) $user['id'],
				'email' => isset( $user['email'] ) ? (string) $user['email'] : '',
			);
		}

		set_transient( $key, $users, self::CACHE_TTL );
		$this->remember_cache_key( $key );

		return $users;
	}

	/**
	 * Cheap credential check used by the settings-page feedback indicator.
	 */
	public function validate_credentials() {
		if ( ! $this->is_configured() ) {
			return false;
		}

		$result = $this->request(
			'GET',
			'/locations/' . rawurlencode( $this->location_id ) . '/customFields?model=contact',
			null,
			10
		);

		return null !== $result;
	}

	/* ---------------------------------------------------------------------
	 * Cache plumbing
	 * ------------------------------------------------------------------ */

	private function cache_suffix() {
		return md5( $this->location_id . '|' . $this->api_key );
	}

	/**
	 * WordPress has no transient prefix search on every backend, so keep a
	 * small registry of the keys we created and clear them explicitly.
	 */
	private function remember_cache_key( $key ) {
		$keys = get_option( 'ghl_connector_cache_keys', array() );
		if ( ! is_array( $keys ) ) {
			$keys = array();
		}
		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_option( 'ghl_connector_cache_keys', $keys, false );
		}
	}

	public static function flush_all_caches() {
		$keys = get_option( 'ghl_connector_cache_keys', array() );
		if ( is_array( $keys ) ) {
			foreach ( $keys as $key ) {
				delete_transient( $key );
			}
		}
		delete_option( 'ghl_connector_cache_keys' );
	}
}
