<?php
/**
 * PrintNode API client.
 *
 * @link https://www.printnode.com/en/docs/api/curl
 */
class Shipping_Desk_PrintNode_Client {

	const BASE_URL = 'https://api.printnode.com';

	/** @var string */
	protected $api_key;

	public function __construct( $api_key = null ) {
		$this->api_key = $api_key !== null ? (string) $api_key : (string) get_option( 'shipping_desk_printnode_api_key', '' );
	}

	/**
	 * Return the stored API key or a WP_Error.
	 *
	 * @return string|WP_Error
	 */
	public function get_api_key() {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'no_printnode_api_key', __( 'PrintNode API key is not set.', 'shipping-desk' ) );
		}
		return $this->api_key;
	}

	/**
	 * Build HTTP Basic Auth headers.
	 *
	 * @return array|WP_Error
	 */
	protected function headers() {
		$key = $this->get_api_key();
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		return [
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
			'Authorization' => 'Basic ' . base64_encode( $key . ':' ),
		];
	}

	/**
	 * Decode a WordPress HTTP response body and check for errors.
	 *
	 * PrintNode answers accepted print jobs with a 2xx status and may return an
	 * empty (or non-JSON) body, so a successful HTTP status is enough to treat
	 * the request as successful. Only non-2xx statuses are treated as errors.
	 *
	 * @param array|WP_Error $response     The response from wp_remote_*.
	 * @param int            $success_code  Legacy expected code (any 2xx is accepted).
	 * @return array|WP_Error
	 */
	protected function decode_response( $response, $success_code = 200 ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = trim( (string) wp_remote_retrieve_body( $response ) );

		if ( $code >= 200 && $code < 300 ) {
			if ( '' === $body ) {
				return [];
			}
			$data = json_decode( $body, true );
			if ( is_array( $data ) ) {
				return $data;
			}
			return [ 'raw' => $body ];
		}

		$message = '';
		$data    = json_decode( $body, true );
		if ( is_array( $data ) ) {
			if ( ! empty( $data['message'] ) ) {
				$message = (string) $data['message'];
			} elseif ( ! empty( $data['error'] ) ) {
				$message = is_string( $data['error'] ) ? $data['error'] : wp_json_encode( $data['error'] );
			} elseif ( ! empty( $data['errors'] ) ) {
				$message = is_array( $data['errors'] ) ? implode( '; ', $data['errors'] ) : (string) $data['errors'];
			}
		}
		if ( '' === $message ) {
			$message = sprintf( __( 'PrintNode returned HTTP %d.', 'shipping-desk' ), $code );
		}

		return new WP_Error( 'printnode_error', $message );
	}

	/**
	 * Retrieve all printers across all computers.
	 *
	 * @return array|WP_Error   Array of printer objects or a WP_Error.
	 */
	public function get_printers() {
		$headers = $this->headers();
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}
		$response = wp_remote_get( self::BASE_URL . '/printers', [
			'headers' => $headers,
			'timeout' => 20,
		] );
		return $this->decode_response( $response );
	}

	/**
	 * Send a single print job.
	 *
	 * @param int    $printer_id   Numeric printer ID.
	 * @param string $title        Job title.
	 * @param string $content_type  "pdf_uri" or "pdf_base64".
	 * @param string $content      URL or base64 PDF data.
	 * @param string $source       Human-readable source label (e.g. "Veeqo Shipping").
	 * @return array|WP_Error      PrintNode response or WP_Error.
	 */
	public function send_print_job( $printer_id, $title, $content_type, $content, $source = 'Veeqo Shipping' ) {
		$headers = $this->headers();
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}
		$payload = [
			'printerId'   => (int) $printer_id,
			'title'       => (string) $title,
			'contentType' => (string) $content_type,
			'content'     => (string) $content,
			'source'      => (string) $source,
		];
		$response = wp_remote_post( self::BASE_URL . '/printjobs', [
			'headers' => $headers,
			'body'    => wp_json_encode( $payload ),
			'timeout' => 30,
		] );
		return $this->decode_response( $response );
	}

	/**
	 * Send multiple print jobs in one request.
	 *
	 * @param array $jobs  Array of job arrays, each with keys: printerId, title, contentType, content, source.
	 * @return array|WP_Error
	 */
	public function send_print_jobs( array $jobs ) {
		$headers = $this->headers();
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}
		$jobs_payload = [];
		foreach ( $jobs as $job ) {
			$jobs_payload[] = [
				'printerId'   => (int) ( $job['printerId'] ?? 0 ),
				'title'       => (string) ( $job['title'] ?? '' ),
				'contentType' => (string) ( $job['contentType'] ?? 'pdf_uri' ),
				'content'     => (string) ( $job['content'] ?? '' ),
				'source'      => (string) ( $job['source'] ?? 'Veeqo Shipping' ),
			];
		}
		$response = wp_remote_post( self::BASE_URL . '/printjobs', [
			'headers' => $headers,
			'body'    => wp_json_encode( $jobs_payload ),
			'timeout' => min( 120, max( 30, count( $jobs_payload ) * 10 ) ),
		] );
		return $this->decode_response( $response );
	}

}