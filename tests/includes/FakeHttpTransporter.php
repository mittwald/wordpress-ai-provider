<?php
/**
 * An HTTP transporter test double.
 *
 * @package Mittwald\AiProvider\Tests
 */

declare(strict_types=1);

namespace Mittwald\AiProvider\Tests\Includes;

use RuntimeException;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;

/**
 * Records outgoing requests and replays queued responses.
 *
 * Every model class in this plugin builds its request through `createRequest()`
 * and hands it to the transporter, so recording what arrives here is the most
 * direct way to assert on the wire format the provider produces.
 */
final class FakeHttpTransporter implements HttpTransporterInterface {

	/**
	 * Responses still to be returned, in order.
	 *
	 * @var list<Response>
	 */
	private array $responses = array();

	/**
	 * Requests that were sent, in order.
	 *
	 * @var list<Request>
	 */
	private array $requests = array();

	/**
	 * Queues a raw response.
	 *
	 * @param Response $response The response to return.
	 */
	public function queue( Response $response ): self {
		$this->responses[] = $response;
		return $this;
	}

	/**
	 * Queues a JSON response.
	 *
	 * @param array<string, mixed> $data        Response payload.
	 * @param int                  $status_code HTTP status code.
	 */
	public function queue_json( array $data, int $status_code = 200 ): self {
		return $this->queue(
			new Response(
				$status_code,
				array( 'Content-Type' => 'application/json' ),
				(string) json_encode( $data, JSON_THROW_ON_ERROR )
			)
		);
	}

	/**
	 * Queues a response with a raw (non-JSON) body, such as audio data.
	 *
	 * @param string $body        Raw response body.
	 * @param string $mime_type   Content type of the body.
	 * @param int    $status_code HTTP status code.
	 */
	public function queue_raw( string $body, string $mime_type, int $status_code = 200 ): self {
		return $this->queue( new Response( $status_code, array( 'Content-Type' => $mime_type ), $body ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Request             $request The request to send.
	 * @param RequestOptions|null $options Transport options.
	 */
	public function send( Request $request, ?RequestOptions $options = null ): Response {
		$this->requests[] = $request;

		if ( array() === $this->responses ) {
			throw new RuntimeException(
				sprintf( 'No response queued for %s %s.', (string) $request->getMethod(), $request->getUri() )
			);
		}

		return array_shift( $this->responses );
	}

	/**
	 * Returns every request that was sent.
	 *
	 * @return list<Request>
	 */
	public function requests(): array {
		return $this->requests;
	}

	/**
	 * Returns the number of requests that were sent.
	 */
	public function request_count(): int {
		return count( $this->requests );
	}

	/**
	 * Returns the request that was sent last.
	 *
	 * @throws RuntimeException If no request was sent.
	 */
	public function last_request(): Request {
		if ( array() === $this->requests ) {
			throw new RuntimeException( 'No request was sent.' );
		}

		return $this->requests[ count( $this->requests ) - 1 ];
	}

	/**
	 * Returns the decoded JSON body of the request that was sent last.
	 *
	 * @return array<string, mixed>
	 * @throws RuntimeException If the body is absent or not a JSON object.
	 */
	public function last_request_payload(): array {
		$body = $this->last_request()->getBody();
		if ( null === $body ) {
			throw new RuntimeException( 'The last request had no body.' );
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( 'The last request body was not a JSON object: ' . $body );
		}

		/** @var array<string, mixed> $decoded */
		return $decoded;
	}
}
