<?php

declare( strict_types=1 );

namespace Mittwald\AiProvider;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * Class for an OpenAI text generation model.
 *
 * @since 0.1.0
 */
class MittwaldTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {
	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		return new Request(
			$method,
			MittwaldAIProvider::url( $path ),
			$headers,
			$data,
			$this->getRequestOptions()
		);
	}
}
